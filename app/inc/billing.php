<?php
declare(strict_types=1);

function billing_ready(): bool
{
    return (string)config('stripe.secret_key', '') !== ''
        && (string)config('stripe.webhook_secret', '') !== '';
}

function billing_account(int $userId): array
{
    db()->prepare('INSERT OR IGNORE INTO subscriptions (user_id) VALUES (?)')->execute([$userId]);
    $q = db()->prepare('SELECT * FROM subscriptions WHERE user_id=?'); $q->execute([$userId]);
    return $q->fetch();
}

/** Endpoint is never supplied by a request. No redirects or arbitrary URLs. */
function stripe_request(string $method, string $endpoint, array $data = [], string $idempotency = ''): array
{
    if (!billing_ready()) throw new RuntimeException('Stripe ist nicht eingerichtet. Siehe docs/STRIPE.md.');
    if (!preg_match('#^/(customers|checkout/sessions|billing_portal/sessions|subscriptions)(/[A-Za-z0-9_]+)?$#D', $endpoint)) {
        throw new RuntimeException('Ungültiger Stripe-Endpunkt.');
    }
    $url = 'https://api.stripe.com/v1' . $endpoint;
    if ($method === 'GET' && $data) $url .= '?' . http_build_query($data);
    $headers = ['Authorization: Bearer ' . config('stripe.secret_key'), 'Stripe-Version: 2024-06-20'];
    if ($idempotency !== '') $headers[] = 'Idempotency-Key: ' . $idempotency;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $result = is_string($raw) ? json_decode($raw, true) : null;
    if ($code < 200 || $code >= 300 || !is_array($result)) {
        // Do not expose Stripe payloads, secrets or customer details to browsers.
        throw new RuntimeException('Stripe-Anfrage fehlgeschlagen. Bitte später erneut versuchen und die Stripe-Logs prüfen.');
    }
    return $result;
}

function billing_plans(): array
{
    return (array)config('stripe.plans', []);
}

function billing_entitled(array $s): bool
{
    return in_array($s['status'], ['active', 'trialing'], true);
}

function billing_require_capacity(int $userId, string $kind, int $businessId = 0): void
{
    if (!config('stripe.enforce_limits', false)) return;
    $s = billing_account($userId); $plan = billing_plans()[$s['plan_key']] ?? null;
    if (!billing_entitled($s) || !$plan) {
        flash(t('Für diese Aktion ist ein aktives Abo erforderlich.'), 'err'); redirect('/billing');
    }
    $limit = (int)($plan['limits'][$kind] ?? 0);
    if ($limit <= 0) return;
    $sql = match ($kind) {
        'businesses' => 'SELECT COUNT(*) FROM businesses WHERE user_id=?',
        'members' => 'SELECT COUNT(*) FROM members m JOIN businesses b ON b.id=m.business_id WHERE b.user_id=?',
        'programs' => 'SELECT COUNT(*) FROM programs p JOIN businesses b ON b.id=p.business_id WHERE b.user_id=?',
        default => throw new RuntimeException('Unbekanntes Planlimit'),
    };
    $q=db()->prepare($sql);$q->execute([$userId]);
    if ((int)$q->fetchColumn() >= $limit) {
        flash(t('Planlimit erreicht. Bitte den Plan wechseln.'), 'err'); redirect('/billing');
    }
}

function route_billing(string $method): never
{
    $u = require_login(); $s = billing_account((int)$u['id']);
    if ($method === 'POST') {
        try {
            if (!billing_ready()) throw new RuntimeException('Stripe ist noch nicht eingerichtet. Siehe docs/STRIPE.md.');
            $action = $_POST['action'] ?? '';
            if ($action === 'portal') {
                if (!$s['stripe_customer_id']) throw new RuntimeException('Noch kein Stripe-Kundenkonto vorhanden.');
                $session = stripe_request('POST', '/billing_portal/sessions', [
                    'customer'=>$s['stripe_customer_id'], 'return_url'=>base_url() . '/billing',
                ]);
            } elseif ($action === 'checkout') {
                // Serialize checkout creation per account and reuse the open Checkout Session.
                db()->exec('BEGIN IMMEDIATE');
                $s = billing_account((int)$u['id']);
                if ($s['stripe_subscription_id'] && !in_array($s['status'], ['canceled','incomplete_expired'], true)) {
                    throw new RuntimeException('Bitte das bestehende Abo im Kundenportal verwalten.');
                }
                $key = (string)($_POST['plan'] ?? ''); $plan = billing_plans()[$key] ?? null;
                if (!$plan || !preg_match('/^price_[A-Za-z0-9]+$/D', (string)($plan['price_id'] ?? ''))) {
                    throw new RuntimeException('Dieser Plan ist noch nicht mit einer Stripe-Price-ID verbunden.');
                }
                if (!$s['stripe_customer_id']) {
                    $c = stripe_request('POST', '/customers', ['email'=>$u['email'], 'name'=>$u['name'],
                        'metadata'=>['user_id'=>(string)$u['id']]], 'loyalty-customer-' . substr(hash('sha256', base_url()),0,16) . '-' . $u['id']);
                    db()->prepare('UPDATE subscriptions SET stripe_customer_id=? WHERE user_id=?')->execute([$c['id'],$u['id']]);
                    $s['stripe_customer_id']=$c['id'];
                }
                $session = null;
                if ($s['checkout_session_id']) {
                    $old = stripe_request('GET', '/checkout/sessions/' . $s['checkout_session_id']);
                    if (($old['status'] ?? '') === 'open') {
                        if (($old['metadata']['plan_key'] ?? '') !== $key) throw new RuntimeException('Ein Checkout ist noch offen. Bitte zuerst fortsetzen oder dessen Ablauf abwarten.');
                        $session=$old;
                    }
                    if (($old['status'] ?? '') === 'complete') throw new RuntimeException('Zahlung wird noch synchronisiert. Bitte kurz warten.');
                }
                if (!$session) {
                    $generation=(int)$s['checkout_generation']+1;
                    $session=stripe_request('POST', '/checkout/sessions', [
                        'mode'=>'subscription', 'customer'=>$s['stripe_customer_id'],
                        'line_items'=>[['price'=>$plan['price_id'],'quantity'=>1]],
                        'success_url'=>base_url() . '/billing?checkout=success',
                        'cancel_url'=>base_url() . '/billing?checkout=cancel',
                        'client_reference_id'=>(string)$u['id'],
                        'metadata'=>['user_id'=>(string)$u['id'],'plan_key'=>$key],
                        'subscription_data'=>['metadata'=>['user_id'=>(string)$u['id']]],
                    ], 'loyalty-checkout-' . substr(hash('sha256', base_url()),0,16) . '-' . $u['id'] . '-' . $generation);
                    db()->prepare('UPDATE subscriptions SET checkout_session_id=?,checkout_generation=? WHERE user_id=?')
                        ->execute([$session['id'],$generation,$u['id']]);
                }
                db()->exec('COMMIT');
            } else throw new RuntimeException('Ungültige Aktion.');
            $url = (string)($session['url'] ?? '');
            $host=parse_url($url,PHP_URL_HOST);
            if (parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array($host,['checkout.stripe.com','billing.stripe.com'],true)) throw new RuntimeException('Ungültige Stripe-Weiterleitung.');
            redirect($url);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            // SQLite BEGIN IMMEDIATE is explicit; ensure rollback if PDO does not track it.
            try { db()->exec('ROLLBACK'); } catch (Throwable) {}
            flash($e->getMessage(), 'err'); redirect('/billing');
        }
    }
    $body='<h1>' . t('Pläne & Abrechnung') . '</h1><p class="muted">' . t('Ein Abo pro Betreiberkonto, einschließlich seiner Unternehmen. Kundenkarten bleiben bei Kündigung lesbar. Bei aktivierter Planprüfung sind Änderungen ohne aktives Abo gesperrt.') . '</p>';
    if (!billing_ready()) $body.='<div class="notice">' . t('Stripe ist noch nicht eingerichtet. Es werden keine Zahlungen ausgelöst. Setup: docs/STRIPE.md') . '</div>';
    if (isset($_GET['checkout'])) $body.='<p class="notice">' . t('Der Rücksprung von Stripe bestätigt keine Zahlung. Der Abo-Status wird ausschließlich über signierte Webhooks aktualisiert.') . '</p>';
    $body.='<section class="panel"><h2>' . t('Dein Abo') . '</h2><p>' . t('Plan') . ': <strong>' . h($s['plan_key'] ?: 'Keiner') . '</strong> · Status: <strong>' . h($s['status']) . '</strong></p>';
    if ($s['cancel_at_period_end']) $body.='<p>' . t('Kündigung zum Ende des Abrechnungszeitraums vorgemerkt.') . '</p>';
    if ($s['current_period_end']) $body.='<p>' . t('Aktueller Zeitraum bis') . ': ' . h(gmdate('Y-m-d H:i', (int)$s['current_period_end'])) . ' UTC</p>';
    if ($s['stripe_customer_id'] && billing_ready()) $body.='<form method="post">' . csrf_field() . '<button class="btn" name="action" value="portal">' . t('Abo verwalten, wechseln oder kündigen') . '</button></form>';
    $body.='<p class="notice">' . (config('stripe.enforce_limits',false) ? t('Planprüfung aktiv: alle betrieblichen Schreibkanäle werden serverseitig geprüft. Login, Abrechnung und Datenschutz bleiben erreichbar.') : t('Planprüfung ausgeschaltet: Test-/Entwicklungsmodus, keine Bezahlschranke.')) . '</p></section><div class="grid">';
    foreach (billing_plans() as $key=>$p) {
        $body.='<section class="panel"><h2>' . h($p['name'] ?? $key) . '</h2><p>' . h($p['description'] ?? '') . '</p><p>' . t('Verbindliche Preise, Steuern und Laufzeit werden bei Stripe vor dem Kauf angezeigt.') . '</p><ul>';
        foreach (($p['limits'] ?? []) as $kind=>$limit) $body.='<li>' . h(['businesses'=>'Unternehmen','members'=>'Mitglieder','programs'=>'Programme','staff'=>'Teammitglieder'][$kind] ?? (string)$kind) . ': ' . ((int)$limit > 0 ? (int)$limit : t('unbegrenzt')) . '</li>';
        foreach (($p['features'] ?? []) as $feature=>$enabled) $body.='<li>'.h(['api'=>'REST API','integrations'=>'Shop-Anbindungen','campaigns'=>'Kampagnen','webhooks'=>'Webhooks'][$feature] ?? $feature).': '.($enabled ? t('enthalten') : t('nicht enthalten')).'</li>';
        $body.='</ul><form method="post">' . csrf_field() . '<input type="hidden" name="plan" value="' . h($key) . '"><button class="btn" name="action" value="checkout"' . (!billing_ready() || empty($p['price_id']) ? ' disabled' : '') . '>' . t('Plan wählen') . '</button></form></section>';
    }
    page(t('Pläne & Abrechnung'),$body.'</div>',$u);exit;
}

function stripe_signature_valid(string $raw, string $header, string $secret, ?int $now=null): bool
{
    if ($secret==='') return false;
    $timestamp=null; $sigs=[];
    foreach (explode(',',$header) as $part) {
        $pair=explode('=',trim($part),2);
        if (count($pair)!==2) continue;
        if ($pair[0]==='t' && ctype_digit($pair[1])) $timestamp=(int)$pair[1];
        if ($pair[0]==='v1') $sigs[]=$pair[1];
    }
    if ($timestamp===null || abs(($now ?? time())-$timestamp)>300) return false;
    $expected=hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
    foreach($sigs as $sig) if (hash_equals($expected,$sig)) return true;
    return false;
}

/** Resolve current state from Stripe, not event ordering or return query parameters. */
function billing_sync_subscription(string $id): void
{
    if (!preg_match('/^sub_[A-Za-z0-9]+$/D',$id)) throw new RuntimeException('Ungültiges Abo.');
    $sub=stripe_request('GET','/subscriptions/'.$id);
    $customer=is_array($sub['customer'] ?? null) ? $sub['customer']['id'] : ($sub['customer'] ?? '');
    $q=db()->prepare('SELECT * FROM subscriptions WHERE stripe_customer_id=?');$q->execute([$customer]);$local=$q->fetch();
    if (!$local) return; // An unrelated product on the same Stripe account.
    if (!empty($local['stripe_subscription_id']) && $local['stripe_subscription_id']!==$id
        && !in_array($local['status'],['canceled','incomplete_expired','none'],true)) return;
    $uid=(string)($sub['metadata']['user_id'] ?? '');
    if ($uid!==(string)$local['user_id']) return;
    $price=$sub['items']['data'][0]['price']['id'] ?? ''; $planKey='';
    foreach (billing_plans() as $key=>$plan) if (($plan['price_id'] ?? '')===$price && $price!=='') $planKey=$key;
    if ($planKey==='') throw new RuntimeException('Unbekannte Stripe Price-ID. Konfiguration prüfen.');
    db()->prepare('UPDATE subscriptions SET stripe_subscription_id=?,checkout_session_id=NULL,plan_key=?,status=?,cancel_at_period_end=?,current_period_end=?,updated_at=datetime(\'now\') WHERE user_id=?')
        ->execute([$id,$planKey,$sub['status'],(int)($sub['cancel_at_period_end'] ?? false),$sub['current_period_end'] ?? null,$local['user_id']]);
}

function route_stripe_webhook(string $method): never
{
    if ($method!=='POST') { http_response_code(405);header('Allow: POST');exit; }
    if (!billing_ready()) { http_response_code(503);echo 'Stripe not configured';exit; }
    $raw=(string)file_get_contents('php://input',false,null,0,1048577);
    if (strlen($raw)>1048576 || !stripe_signature_valid($raw,(string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''),(string)config('stripe.webhook_secret'))) {
        http_response_code(400);echo 'Invalid signature';exit;
    }
    $event=json_decode($raw,true);
    if (!is_array($event) || !preg_match('/^evt_[A-Za-z0-9]+$/D',(string)($event['id'] ?? ''))) { http_response_code(400);exit; }
    try {
        db()->exec('BEGIN IMMEDIATE');
        $q=db()->prepare('SELECT id FROM stripe_events WHERE id=?');$q->execute([$event['id']]);
        if (!$q->fetch()) {
            $type=$event['type'] ?? ''; $o=$event['data']['object'] ?? []; $id='';
            if (str_starts_with($type,'customer.subscription.')) $id=$o['id'] ?? '';
            if (in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded','invoice.paid','invoice.payment_failed'],true)) {
                $id=is_array($o['subscription'] ?? null) ? $o['subscription']['id'] : ($o['subscription'] ?? ($o['parent']['subscription_details']['subscription'] ?? ''));
            }
            if ($id!=='') billing_sync_subscription($id);
            db()->prepare('INSERT INTO stripe_events (id,event_type) VALUES (?,?)')->execute([$event['id'],$type]);
        }
        db()->exec('COMMIT'); echo 'ok';
    } catch (Throwable) {
        try { db()->exec('ROLLBACK'); } catch (Throwable) {}
        http_response_code(500);echo 'Retry later';
    }
    exit;
}
