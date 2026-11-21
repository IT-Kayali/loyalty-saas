<?php
declare(strict_types=1);

/* ==================================================================
 * REST API (JSON, Bearer-Key) + Webhooks + Shop-Integrationen
 *
 * Sicherheits-Fix gegenüber der Vorgängerversion:
 * Webhook-Ziele werden SSRF-geprüft - nur http(s) auf öffentliche
 * Adressen, keine Redirects, IP-Auflösung gegen private/reservierte
 * Bereiche geprüft (bei Anlage UND bei jeder Zustellung).
 * ================================================================== */

const WEBHOOK_EVENTS = [
    'member.joined'        => 'Neues Mitglied',
    'card.stamp'           => 'Stempel vergeben',
    'card.points'          => 'Punkte gutgeschrieben',
    'card.redeem'          => 'Einlösung',
    'card.visit'           => 'Besuch (Mehrfachkarte)',
    'achievement.unlocked' => 'Meilenstein erreicht',
];

/* ------------------------------------------------------------------ */
/* SSRF-Schutz                                                         */
/* ------------------------------------------------------------------ */

/** true, wenn die IP öffentlich routbar ist (kein LAN, Loopback, Metadata etc.). */
function ip_is_public(string $ip): bool
{
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
}

/**
 * Prüft eine Webhook-URL gegen SSRF:
 * http/https (https erzwungen, außer webhook_allow_http), Host muss
 * ausschließlich auf öffentliche IPs auflösen.
 * Liefert null bei OK, sonst eine Fehlermeldung.
 */
function webhook_url_error(string $url): ?string
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
        return t('Ungültige URL.');
    }
    $scheme = strtolower((string)$parts['scheme']);
    $allowHttp = (bool)config('webhook_allow_http', false);
    if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
        return t('Nur HTTPS-URLs sind erlaubt.');
    }
    $host = (string)$parts['host'];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ip_is_public($host) ? null : t('IP-Adressen im privaten/reservierten Bereich sind nicht erlaubt.');
    }
    $ips = gethostbynamel($host);
    if ($ips === false || $ips === []) {
        return t('Hostname kann nicht aufgelöst werden.');
    }
    foreach ($ips as $ip) {
        if (!ip_is_public($ip)) {
            return t('Der Hostname zeigt auf eine interne Adresse - nicht erlaubt.');
        }
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Webhooks                                                            */
/* ------------------------------------------------------------------ */

/** Feuert ein Event an alle aktiven Webhooks des Business. */
function fire_webhook(int $businessId, string $event, array $payload): void
{
    $owner=db()->prepare('SELECT user_id FROM businesses WHERE id=?');$owner->execute([$businessId]);
    if(plan_policy((int)$owner->fetchColumn(),'webhooks')!==null)return;
    $stmt = db()->prepare("SELECT * FROM webhooks WHERE business_id = ? AND active = 1
        AND (',' || events || ',') LIKE ?");
    $stmt->execute([$businessId, '%,' . $event . ',%']);
    foreach ($stmt->fetchAll() as $hook) {
        $body = json_encode([
            'event' => $event,
            'business_id' => $businessId,
            'timestamp' => date('c'),
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE);
        db()->prepare('INSERT INTO webhook_deliveries (webhook_id, event, payload) VALUES (?,?,?)')
            ->execute([$hook['id'], $event, $body]);
        webhook_attempt((int)db()->lastInsertId(), true);
    }
}

const WEBHOOK_MAX_ATTEMPTS = 6;
/** Wartezeit in Minuten nach Versuch 1, 2, 3 ... */
const WEBHOOK_BACKOFF_MIN = [1, 5, 30, 120, 720];

/**
 * Ein Zustellversuch fuer eine gespeicherte Zustellung. Bei Erfolg 'delivered'.
 * Bei Fehler 'failed' mit next_attempt_at (Backoff), nach WEBHOOK_MAX_ATTEMPTS 'dead'
 * (nur noch manuell wiederholbar). $first: erster Versuch direkt beim Ereignis.
 * Liefert den neuen Status.
 */
function webhook_attempt(int $deliveryId, bool $first = false, bool $dueOnly = false): string
{
    if (!$first) {
        // Atomare Sperre: nur ein Worker/Klick darf diese Zustellung gleichzeitig senden (Lease 2 Minuten).
        $claim = db()->prepare("UPDATE webhook_deliveries SET lock_until=? WHERE id=? AND status!='delivered' AND (lock_until IS NULL OR lock_until<?)"
            . ($dueOnly ? " AND status='failed' AND next_attempt_at IS NOT NULL AND next_attempt_at<=?" : ''));
        $nowS = gmdate('Y-m-d H:i:s');
        $claim->execute(array_merge([gmdate('Y-m-d H:i:s', time() + 120), $deliveryId, $nowS], $dueOnly ? [$nowS] : []));
        if ($claim->rowCount() !== 1) {
            return 'locked';
        }
    }
    $st = db()->prepare('SELECT d.*, w.url, w.secret, w.active FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id WHERE d.id = ?');
    $st->execute([$deliveryId]);
    $d = $st->fetch();
    $st->closeCursor(); // Lesesperre sofort freigeben, sonst blockieren sich parallele Worker
    if (!$d) {
        return 'missing';
    }
    $attempts = $first ? 1 : (int)$d['attempts'] + 1;
    if (!(int)$d['active']) {
        db()->prepare("UPDATE webhook_deliveries SET status='dead', response=?, next_attempt_at=NULL, lock_until=NULL, dead_ack=0, delivered_at=datetime('now') WHERE id=?")
            ->execute(['Webhook ist deaktiviert', $deliveryId]);
        webhook_notify_dead($deliveryId);
        return 'dead';
    }
    [$status, $response] = webhook_post((string)$d['url'], (string)$d['payload'], (string)$d['secret'], [
        'X-Webhook-Delivery: ' . $deliveryId,
        'X-Webhook-Attempt: ' . $attempts,
    ]);
    $next = null;
    if ($status !== 'delivered') {
        if ($attempts >= WEBHOOK_MAX_ATTEMPTS) {
            $status = 'dead';
        } else {
            $status = 'failed';
            $wait = WEBHOOK_BACKOFF_MIN[min($attempts, count(WEBHOOK_BACKOFF_MIN)) - 1];
            $next = gmdate('Y-m-d H:i:s', time() + $wait * 60);
        }
    }
    db()->prepare("UPDATE webhook_deliveries SET status=?, response=?, attempts=?, next_attempt_at=?, lock_until=NULL, dead_ack=0, delivered_at=datetime('now') WHERE id=?")
        ->execute([$status, mb_strimwidth((string)$response, 0, 500, ''), $attempts, $next, $deliveryId]);
    if ($status === 'dead') {
        webhook_notify_dead($deliveryId);
    }
    return $status;
}

/** Hinweis an den Plattformbetreiber (support_email) bei endgueltig fehlgeschlagener Zustellung. Ohne Payload, URL und Secret. Eine Mail je Zustellung. */
function webhook_notify_dead(int $deliveryId): void
{
    $to = (string)config('support_email', '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/@(example\.(com|org|net)|.*\.test|.*\.local)$/i', $to) || !function_exists('queue_mail')) {
        return;
    }
    $st = db()->prepare('SELECT d.event, b.name FROM webhook_deliveries d JOIN webhooks w ON w.id=d.webhook_id JOIN businesses b ON b.id=w.business_id WHERE d.id=?');
    $st->execute([$deliveryId]);
    $r = $st->fetch();
    if (!$r) {
        return;
    }
    queue_mail(null, null, $to, 'Loyalty: Webhook-Zustellung endgültig fehlgeschlagen',
        'Eine Webhook-Zustellung (Nr. ' . $deliveryId . ', Event ' . $r['event'] . ', Unternehmen ' . $r['name'] . ') ist nach allen Versuchen fehlgeschlagen. Bitte im Admin-Dashboard prüfen: ' . base_url() . '/admin',
        'transactional', 'webhook-dead-' . $deliveryId . '-' . (int)db()->query('SELECT attempts FROM webhook_deliveries WHERE id=' . $deliveryId)->fetchColumn());
}

/** Wiederholt alle faelligen fehlgeschlagenen Zustellungen (Cron: tools/webhook-retry.php). Liefert [versucht, geliefert]. */
function webhook_retry_due(int $limit = 50): array
{
    $ids = db()->prepare("SELECT id FROM webhook_deliveries WHERE status='failed' AND next_attempt_at IS NOT NULL AND next_attempt_at <= ? ORDER BY next_attempt_at LIMIT " . max(1, $limit));
    $ids->execute([gmdate('Y-m-d H:i:s')]);
    $tried = $ok = 0;
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $tried++;
        $r = webhook_attempt((int)$id, false, true);
        if ($r === 'locked') {
            $tried--;
            continue;
        }
        if ($r === 'delivered') {
            $ok++;
        }
    }
    return [$tried, $ok];
}

/** POSTet die Payload an die Webhook-URL (SSRF-geprüft, keine Redirects). Liefert [status, response]. */
function webhook_post(string $url, string $body, string $secret, array $extraHeaders = []): array
{
    $transport = config('webhook_transport', null);
    if (is_callable($transport)) { // Testnaht: ersetzt nur den Versand, nicht die Statuslogik
        return $transport($url, $body, $secret, $extraHeaders);
    }
    $urlError = webhook_url_error($url);
    if ($urlError !== null) {
        return ['failed', 'SSRF-Schutz: ' . $urlError];
    }
    $signature = $secret !== '' ? hash_hmac('sha256', $body, $secret) : '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => array_filter(array_merge($extraHeaders, [
            'Content-Type: application/json',
            'User-Agent: LoyaltyApp-Webhook/2.0',
            $signature !== '' ? 'X-Webhook-Signature: sha256=' . $signature : null,
        ])),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false,      // keine Redirects (Rebinding-Schutz)
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE => 65536,
    ]);
    $result = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['failed', 'Verbindung fehlgeschlagen: ' . $err];
    }
    curl_close($ch);
    return [($code >= 200 && $code < 300) ? 'delivered' : 'failed', 'HTTP ' . $code];
}

/* ------------------------------------------------------------------ */
/* Owner: API-Key + Webhook-Verwaltung + Integrationen                 */
/* ------------------------------------------------------------------ */

function route_apikey_regenerate(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if ($b) {
        db()->prepare('UPDATE businesses SET api_key = ? WHERE id = ?')
            ->execute([bin2hex(random_bytes(24)), $b['id']]);
        flash(t('Neuer API-Schlüssel erzeugt. Der alte ist sofort ungültig.'));
        redirect('/business/' . (int)$b['id']);
    }
    redirect('/dashboard');
}

function route_webhook_create(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $url = trim($_POST['url'] ?? '');
    $events = array_values(array_intersect(array_keys(WEBHOOK_EVENTS), (array)($_POST['events'] ?? [])));
    if (!$b || !$events) {
        flash(t('URL und mindestens ein Event erforderlich.'), 'err');
        redirect($b ? '/business/' . (int)$b['id'] : '/dashboard');
    }
    $urlError = webhook_url_error($url);
    if ($urlError !== null) {
        flash(t('Webhook-URL abgelehnt:') . ' ' . $urlError, 'err');
        redirect('/business/' . (int)$b['id']);
    }
    db()->prepare('INSERT INTO webhooks (business_id, url, events, secret) VALUES (?,?,?,?)')
        ->execute([$b['id'], $url, implode(',', $events), trim($_POST['secret'] ?? '')]);
    flash(t('Webhook angelegt.'));
    redirect('/business/' . (int)$b['id']);
}

function route_webhook_delete(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if ($b) {
        db()->prepare('DELETE FROM webhooks WHERE id = ? AND business_id = ?')
            ->execute([(int)$_POST['webhook_id'], $b['id']]);
        flash(t('Webhook gelöscht.'));
        redirect('/business/' . (int)$b['id']);
    }
    redirect('/dashboard');
}

/** Owner: eine fehlgeschlagene Zustellung sofort erneut senden. */
function route_webhook_retry(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if (!$b) {
        redirect('/dashboard');
    }
    $st = db()->prepare('SELECT d.id, d.status FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id WHERE d.id = ? AND w.business_id = ?');
    $st->execute([(int)($_POST['delivery_id'] ?? 0), $b['id']]);
    $d = $st->fetch();
    if (!$d || $d['status'] === 'delivered') {
        flash(t('Zustellung nicht gefunden oder bereits zugestellt.'), 'err');
    } else {
        $r = webhook_attempt((int)$d['id']);
        flash($r === 'locked' ? t('Diese Zustellung wird gerade gesendet. Bitte kurz warten.') : ($r === 'delivered' ? t('Webhook zugestellt.') : t('Zustellung weiter fehlgeschlagen. Details im Protokoll.')), $r === 'delivered' ? 'ok' : 'err');
    }
    redirect('/business/' . (int)$b['id']);
}

/** Speichert die Shop/POS-Integrationseinstellungen (Shopify-Secret, Auto-Punkte). */
function route_integration_save(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if (!$b) {
        redirect('/dashboard');
    }
    $secret = trim((string)($_POST['shopify_secret'] ?? ''));
    $ppc = max(0, min(1000, (int)($_POST['points_per_cent'] ?? 0)));
    db()->prepare('UPDATE businesses SET shopify_secret = ?, points_per_cent = ? WHERE id = ?')
        ->execute([$secret !== '' ? $secret : null, $ppc, $b['id']]);
    flash(t('Integration gespeichert.'));
    redirect('/business/' . (int)$b['id']);
}

function api_html(array $b): string
{
    $hooks = db()->prepare('SELECT * FROM webhooks WHERE business_id = ? ORDER BY id');
    $hooks->execute([$b['id']]);
    $list = '';
    foreach ($hooks->fetchAll() as $hook) {
        $del = db()->prepare('SELECT id, event, status, response, delivered_at, attempts, next_attempt_at FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT 5');
        $del->execute([$hook['id']]);
        $log = '';
        foreach ($del->fetchAll() as $dl) {
            $log .= '<br><span class="muted" style="font-size:12px">' . h($dl['delivered_at']) . ' ' . h($dl['event'])
                . ' – ' . h($dl['status']) . ' (' . h($dl['response']) . ', ' . (int)$dl['attempts'] . ' ' . t('Versuch(e)')
                . ($dl['status'] === 'failed' && $dl['next_attempt_at'] ? ', ' . t('nächster Versuch') . ' ' . h($dl['next_attempt_at']) . ' UTC' : '') . ')</span>';
            if ($dl['status'] !== 'delivered') {
                $log .= ' <form method="post" action="/webhook/retry" style="display:inline">' . csrf_field()
                    . '<input type="hidden" name="business_id" value="' . (int)$b['id'] . '">'
                    . '<input type="hidden" name="delivery_id" value="' . (int)$dl['id'] . '">'
                    . '<button class="btn ghost small">' . t('Erneut senden') . '</button></form>';
            }
        }
        $list .= '<li><code>' . h($hook['url']) . '</code> <span class="muted">' . h($hook['events']) . '</span>'
            . ' <form method="post" action="/webhook/delete" style="display:inline">' . csrf_field()
            . '<input type="hidden" name="business_id" value="' . (int)$b['id'] . '">'
            . '<input type="hidden" name="webhook_id" value="' . (int)$hook['id'] . '">'
            . '<button class="btn ghost small">' . t('Löschen') . '</button></form>' . $log . '</li>';
    }
    $list = $list ? '<ul>' . $list . '</ul>' : '<p class="muted">' . t('Noch keine Webhooks.') . '</p>';

    $eventBoxes = '';
    foreach (WEBHOOK_EVENTS as $key => $label) {
        $eventBoxes .= '<label style="display:inline-flex;gap:4px;align-items:center;margin-right:12px;font-weight:normal">'
            . '<input type="checkbox" name="events[]" value="' . $key . '" style="width:auto"> ' . h(t($label)) . '</label>';
    }

    $hookSecretNote = t('Webhooks werden mit HMAC-SHA256 signiert (Header X-Webhook-Signature), wenn ein Secret gesetzt ist.');
    $shopifyUrl = base_url() . '/integrations/shopify/webhook/' . (int)$b['id'];

    return '
      <p>' . t('API-Schlüssel:') . ' <code>' . h($b['api_key'] ?? '') . '</code>
        <form method="post" action="/apikey/regenerate" style="display:inline">' . csrf_field() . '
          <input type="hidden" name="business_id" value="' . (int)$b['id'] . '">
          <button class="btn ghost small">' . t('Neu erzeugen') . '</button>
        </form></p>
      <p class="muted">' . t('Nutzung:') . ' <code>curl -H "Authorization: Bearer KEY" ' . h(base_url()) . '/api/v1/members</code>
        – <a href="/api/docs" target="_blank">' . t('Vollständige API-Dokumentation') . '</a></p>

      <h3 style="margin:16px 0 8px">' . t('Shop & Kasse (WooCommerce, Shopify, POS)') . '</h3>
      <p class="muted">' . t('Bestellungen erzeugen automatisch Treuepunkte. Für WooCommerce gibt es das fertige Plugin im Repo (integrations/woocommerce). Für Shopify trägst du unten das App-Secret ein und hinterlegst diese Webhook-URL im Shop:') . '</p>
      <p><code>' . h($shopifyUrl) . '</code></p>
      <form method="post" action="/integration/save" class="form inline-form">' . csrf_field() . '
        <input type="hidden" name="business_id" value="' . (int)$b['id'] . '">
        <label>' . t('Shopify Webhook-Secret') . ' <input name="shopify_secret" value="' . h($b['shopify_secret'] ?? '') . '" maxlength="120"></label>
        <label>' . t('Punkte je Cent Umsatz (0 = aus)') . ' <input type="number" name="points_per_cent" value="' . (int)($b['points_per_cent'] ?? 0) . '" min="0" max="1000"></label>
        <button class="btn ghost">' . t('Speichern') . '</button>
      </form>
      <p class="muted">' . t('Beispiel: 1 Punkt je Cent = 100 Punkte für 1 € Umsatz. Gilt für Shopify-Webhooks und den Endpunkt POST /api/v1/integrations/order (WooCommerce, eigene Plugins, Kassensysteme).') . '</p>

      <h3 style="margin:16px 0 8px">' . t('Webhooks') . '</h3>
      ' . $list . '
      <details class="panel">
        <summary>' . t('Webhook anlegen') . '</summary>
        <form method="post" action="/webhook/create" class="form">' . csrf_field() . '
          <input type="hidden" name="business_id" value="' . (int)$b['id'] . '">
          <label>' . t('Ziel-URL (HTTPS)') . ' <input name="url" required placeholder="https://mein-server.de/hook"></label>
          <label>' . t('Secret (optional, für HMAC-Signatur)') . ' <input name="secret" maxlength="80"></label>
          <div>' . $eventBoxes . '</div>
          <p class="muted">' . $hookSecretNote . '</p>
          <button class="btn">' . t('Anlegen') . '</button>
        </form>
      </details>';
}

/* ------------------------------------------------------------------ */
/* REST API Dispatcher (JSON, kein Session-Login)                      */
/* ------------------------------------------------------------------ */

function api_auth(): array
{
    $key = '';
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('#^Bearer\s+(\S+)$#i', $header, $m)) {
        $key = $m[1];
    } elseif (isset($_GET['api_key'])) {
        $key = (string)$_GET['api_key'];
    }
    $stmt = db()->prepare('SELECT * FROM businesses WHERE api_key = ?');
    $stmt->execute([$key]);
    $b = $stmt->fetch();
    if (!$b) {
        api_json(['error' => 'Ungültiger API-Schlüssel'], 401);
    }
    return $b;
}

function api_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function member_api_payload(array $m): array
{
    $cards = db()->prepare('SELECT c.id, c.program_id, c.stamps, c.points, c.points_lifetime, c.pending_rewards,
        c.uses_left, c.visits_left, p.name AS program, p.type, p.active
        FROM cards c JOIN programs p ON p.id = c.program_id WHERE c.member_id = ?');
    $cards->execute([$m['id']]);
    return [
        'id' => (int)$m['id'],
        'name' => $m['name'],
        'email' => $m['email'],
        'code' => $m['code'],
        'joined_at' => $m['created_at'],
        'cards' => $cards->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** Mitglied per Code im Business laden (POS/Integration-Helfer). */
function api_member_by_code(int $bid, string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM members WHERE business_id = ? AND code = ?');
    $stmt->execute([$bid, strtoupper($code)]);
    return $stmt->fetch() ?: null;
}

/** Mitglied per E-Mail finden oder anlegen (Shop/POS-Bestellungen). */
function api_member_upsert(int $bid, string $email, string $name): array
{
    if ($email !== '') {
        $stmt = db()->prepare('SELECT * FROM members WHERE business_id = ? AND lower(email) = lower(?)');
        $stmt->execute([$bid, $email]);
        $m = $stmt->fetch();
        if ($m) {
            return $m;
        }
    }
    db()->prepare('INSERT INTO members (business_id, name, email, code) VALUES (?,?,?,?)')
        ->execute([$bid, $name !== '' ? $name : ($email !== '' ? $email : 'Kunde'), $email, member_code()]);
    $mid = (int)db()->lastInsertId();
    $programs = db()->prepare('SELECT * FROM programs WHERE business_id = ? AND active = 1');
    $programs->execute([$bid]);
    foreach ($programs->fetchAll() as $p) {
        $uses = $p['type'] === 'voucher' ? (int)$p['uses_per_customer'] : null;
        $visits = $p['type'] === 'pass' ? (int)$p['pass_visits'] : null;
        db()->prepare('INSERT OR IGNORE INTO cards (program_id, member_id, uses_left, visits_left) VALUES (?,?,?,?)')
            ->execute([$p['id'], $mid, $uses, $visits]);
    }
    fire_webhook($bid, 'member.joined', ['member_id' => $mid, 'name' => $name]);
    $stmt = db()->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$mid]);
    return $stmt->fetch();
}

/** Punkte aus einem Umsatz gutschreiben (Shop/POS). Liefert gutgeschriebene Punkte. */
function api_credit_order_points(array $b, int $memberId, int $totalCents, string $reference): int
{
    $ppc=(int)($b['points_per_cent'] ?? 0);
    if($ppc<=0 || $totalCents<=0)return 0;
    if($reference==='' || strlen($reference)>80)throw new InvalidArgumentException('Stable order reference required');
    if($totalCents>100000000 || $ppc>1000)throw new InvalidArgumentException('Order amount exceeds safety limit');
    $bid=(int)$b['id'];$db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare("SELECT c.* FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=? AND p.type='points' AND p.active=1 LIMIT 1");
        $q->execute([$memberId,$bid]);$card=$q->fetch();
        if(!$card){$db->exec('COMMIT');return 0;}
        $amount=$totalCents*$ppc;
        $q=$db->prepare('INSERT OR IGNORE INTO order_receipts (business_id,reference,amount_cents,points)VALUES(?,?,?,?)');
        $q->execute([$bid,$reference,$totalCents,$amount]);
        if($q->rowCount()===0){$db->exec('COMMIT');return 0;}
        $db->prepare('UPDATE cards SET points=points+?,points_lifetime=points_lifetime+? WHERE id=?')->execute([$amount,$amount,$card['id']]);
        txn((int)$card['id'],'points_add',$amount,'Bestellung '.$reference);
        $db->exec('COMMIT');
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
    check_achievements($memberId,$bid);
    fire_webhook($bid,'card.points',['card_id'=>(int)$card['id'],'member_id'=>$memberId,'amount'=>$amount,'source'=>'order','reference'=>$reference]);
    wallet_pass_update_for_card((int)$card['id']);
    return $amount;
}

function route_api(string $path, string $method): never
{
    $b = api_auth();
    $bid = (int)$b['id'];
    if(!rate_limit_take('api', (string)$b['api_key'], 120, 60)) rate_limit_stop();
    if(!in_array($method,['GET','HEAD','OPTIONS'],true)) require_plan_write((int)$b['user_id'],'api');
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];

    if(str_starts_with($path,'/api/v1/merchant/'))route_merchant_api($b,$path,$method,$input);

    /* GET /api/v1/business */
    if ($path === '/api/v1/business' && $method === 'GET') {
        api_json(['id' => $bid, 'name' => $b['name'], 'brand_color' => $b['brand_color'], 'join_code' => $b['join_code']]);
    }

    /* GET /api/v1/programs */
    if ($path === '/api/v1/programs' && $method === 'GET') {
        $stmt = db()->prepare('SELECT id, type, name, description, stamps_required, reward_text, active FROM programs WHERE business_id = ?');
        $stmt->execute([$bid]);
        api_json(['programs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /* GET /api/v1/members */
    if ($path === '/api/v1/members' && $method === 'GET') {
        $stmt = db()->prepare('SELECT * FROM members WHERE business_id = ? ORDER BY id DESC LIMIT 200');
        $stmt->execute([$bid]);
        $out = [];
        foreach ($stmt->fetchAll() as $m) {
            $out[] = member_api_payload($m);
        }
        api_json(['count' => count($out), 'members' => $out]);
    }

    /* POST /api/v1/members - Mitglied anlegen */
    if ($path === '/api/v1/members' && $method === 'POST') {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            api_json(['error' => 'name ist erforderlich'], 422);
        }
        $m = api_member_upsert($bid, strtolower(trim((string)($input['email'] ?? ''))), $name);
        api_json(member_api_payload($m), 201);
    }

    /* GET /api/v1/members/{code} */
    if (preg_match('#^/api/v1/members/([A-Fa-f0-9]{10})$#', $path, $m2) && $method === 'GET') {
        $m = api_member_by_code($bid, $m2[1]);
        if (!$m) {
            api_json(['error' => 'Mitglied nicht gefunden'], 404);
        }
        api_json(member_api_payload($m));
    }

    /* POST /api/v1/members/{code}/points - Punkte gutschreiben (Kasse/POS) */
    if (preg_match('#^/api/v1/members/([A-Fa-f0-9]{10})/points$#', $path, $m3) && $method === 'POST') {
        $m = api_member_by_code($bid, $m3[1]);
        if (!$m) {
            api_json(['error' => 'Mitglied nicht gefunden'], 404);
        }
        $stmt = db()->prepare("SELECT c.*, p.type AS program_type FROM cards c JOIN programs p ON p.id = c.program_id
            WHERE c.member_id = ? AND p.business_id = ? AND p.type = 'points' AND p.active = 1 LIMIT 1");
        $stmt->execute([$m['id'], $bid]);
        $card = $stmt->fetch();
        if (!$card) {
            api_json(['error' => 'Keine aktive Punktekarte'], 404);
        }
        $base = max(1, min(10000, (int)($input['amount'] ?? 0)));
        $mult = points_multiplier((int)$card['program_id']);
        $amount = $base * $mult;
        db()->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
            ->execute([$amount, $amount, $card['id']]);
        txn((int)$card['id'], 'points_add', $amount, '+' . $amount . ' Punkte (API)' . ($mult > 1 ? ' (Bonus-Zeit x' . $mult . ')' : ''));
        check_achievements((int)$m['id'], $bid);
        fire_webhook($bid, 'card.points', ['card_id' => (int)$card['id'], 'member_id' => (int)$m['id'], 'amount' => $amount]);
        wallet_pass_update_for_card((int)$card['id']);
        api_json(['member' => $m['code'], 'card_id' => (int)$card['id'], 'credited' => $amount, 'bonus_multiplier' => $mult,
            'points' => (int)db()->query('SELECT points FROM cards WHERE id = ' . (int)$card['id'])->fetchColumn()]);
    }

    /* POST /api/v1/members/{code}/stamp - Stempel vergeben (Kasse/POS) */
    if (preg_match('#^/api/v1/members/([A-Fa-f0-9]{10})/stamp$#', $path, $m4) && $method === 'POST') {
        $m = api_member_by_code($bid, $m4[1]);
        if (!$m) {
            api_json(['error' => 'Mitglied nicht gefunden'], 404);
        }
        $stmt = db()->prepare("SELECT c.*, p.type AS program_type, p.stamps_required, p.name AS program_name FROM cards c
            JOIN programs p ON p.id = c.program_id
            WHERE c.member_id = ? AND p.business_id = ? AND p.type = 'stamps' AND p.active = 1 LIMIT 1");
        $stmt->execute([$m['id'], $bid]);
        $card = $stmt->fetch();
        if (!$card) {
            api_json(['error' => 'Keine aktive Stempelkarte'], 404);
        }
        $req = (int)$card['stamps_required'];
        $stamps = (int)$card['stamps'] + 1;
        $pending = (int)$card['pending_rewards'];
        $earned = false;
        if ($stamps >= $req) {
            $pending++;
            $stamps -= $req;
            $earned = true;
        }
        db()->prepare('UPDATE cards SET stamps = ?, pending_rewards = ? WHERE id = ?')
            ->execute([$stamps, $pending, $card['id']]);
        txn((int)$card['id'], 'stamp_add', 1, '+1 Stempel (API) (' . $stamps . '/' . $req . ')');
        if ($earned) {
            txn((int)$card['id'], 'reward_earned', 1, 'Belohnung verdient: ' . $card['program_name']);
        }
        check_achievements((int)$m['id'], $bid);
        fire_webhook($bid, 'card.stamp', ['card_id' => (int)$card['id'], 'member_id' => (int)$m['id']]);
        wallet_pass_update_for_card((int)$card['id']);
        api_json(['member' => $m['code'], 'card_id' => (int)$card['id'], 'stamps' => $stamps,
            'stamps_required' => $req, 'pending_rewards' => $pending, 'reward_earned' => $earned]);
    }

    /* POST /api/v1/integrations/order - Bestellung (WooCommerce, POS, eigene Plugins) */
    if ($path === '/api/v1/integrations/order' && $method === 'POST') {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $name = trim((string)($input['name'] ?? ''));
        $total = (float)($input['total'] ?? 0);           // z.B. 24.90
        $currency = strtoupper(substr((string)($input['currency'] ?? 'EUR'), 0, 3));
        $reference = mb_substr((string)($input['reference'] ?? ''), 0, 80);
        if ((int)($b['points_per_cent'] ?? 0)>0 && $total>0 && $reference==='') api_json(['error'=>'reference ist bei Bestellpunkten erforderlich'],422);
        if ($email === '') {
            api_json(['error' => 'email ist erforderlich (Mitglied wird sonst nicht gefunden/angelegt)'], 422);
        }
        $m = api_member_upsert($bid, $email, $name);
        $credited = api_credit_order_points($b, (int)$m['id'], (int)round($total * 100), $reference);
        api_json([
            'member' => member_api_payload($m),
            'order' => ['total' => $total, 'currency' => $currency, 'reference' => $reference],
            'points_credited' => $credited,
        ]);
    }

    /* GET /api/v1/transactions */
    if ($path === '/api/v1/transactions' && $method === 'GET') {
        $stmt = db()->prepare('SELECT t.id, t.kind, t.amount, t.note, t.created_at, t.reversed_at,
            c.id AS card_id, m.code AS member_code, p.name AS program
            FROM transactions t JOIN cards c ON t.card_id = c.id
            JOIN members m ON c.member_id = m.id JOIN programs p ON c.program_id = p.id
            WHERE p.business_id = ? ORDER BY t.id DESC LIMIT 100');
        $stmt->execute([$bid]);
        api_json(['transactions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /* POST /api/v1/cards/{id}/points - Punkte gutschreiben (Karten-ID) */
    if (preg_match('#^/api/v1/cards/(\d+)/points$#', $path, $m5) && $method === 'POST') {
        $stmt = db()->prepare('SELECT c.*, p.type AS program_type, p.name AS program_name, p.business_id
            FROM cards c JOIN programs p ON p.id = c.program_id WHERE c.id = ? AND p.business_id = ?');
        $stmt->execute([(int)$m5[1], $bid]);
        $card = $stmt->fetch();
        if (!$card || $card['program_type'] !== 'points') {
            api_json(['error' => 'Punktekarte nicht gefunden'], 404);
        }
        $base = max(1, min(10000, (int)($input['amount'] ?? 0)));
        $mult = points_multiplier((int)$card['program_id']);
        $amount = $base * $mult;
        db()->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
            ->execute([$amount, $amount, $card['id']]);
        txn((int)$card['id'], 'points_add', $amount, '+' . $amount . ' Punkte (API)' . ($mult > 1 ? ' (Bonus-Zeit x' . $mult . ')' : ''));
        check_achievements((int)$card['member_id'], $bid);
        fire_webhook($bid, 'card.points', ['card_id' => (int)$card['id'], 'member_id' => (int)$card['member_id'], 'amount' => $amount]);
        wallet_pass_update_for_card((int)$card['id']);
        api_json(['card_id' => (int)$card['id'], 'credited' => $amount, 'bonus_multiplier' => $mult,
            'points' => (int)db()->query('SELECT points FROM cards WHERE id = ' . (int)$card['id'])->fetchColumn()]);
    }

    /* GET /api/v1/openapi.json - maschinenlesbare API-Beschreibung */
    if ($path === '/api/v1/openapi.json' && $method === 'GET') {
        api_json(openapi_spec());
    }

    api_json(['error' => 'Endpunkt nicht gefunden'], 404);
}

/* ------------------------------------------------------------------ */
/* Shopify-Webhook (orders/paid) - HMAC-geprüft, kein Session-Login    */
/* ------------------------------------------------------------------ */

function route_shopify_webhook(int $businessId): never
{
    $b = db()->prepare('SELECT * FROM businesses WHERE id = ?');
    $b->execute([$businessId]);
    $biz = $b->fetch();
    header('Content-Type: application/json');
    if (!$biz || ($biz['shopify_secret'] ?? '') === '' || ($biz['shopify_secret'] ?? null) === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Integration nicht eingerichtet']);
        exit;
    }
    $raw = (string)file_get_contents('php://input');
    $hmac = base64_encode(hash_hmac('sha256', $raw, (string)$biz['shopify_secret'], true));
    if (!hash_equals($hmac, (string)($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? ''))) {
        http_response_code(401);
        echo json_encode(['error' => 'Ungültige Signatur']);
        exit;
    }
    require_plan_write((int)$biz['user_id'],'integrations');
    $order = json_decode($raw, true) ?: [];
    $topic = (string)($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '');
    if (!in_array($topic, ['orders/paid', 'orders/create'], true)) {
        echo json_encode(['ignored' => true, 'topic' => $topic]);
        exit;
    }
    $email = strtolower(trim((string)($order['email'] ?? $order['contact_email'] ?? '')));
    $name = trim((string)($order['customer']['first_name'] ?? '') . ' ' . (string)($order['customer']['last_name'] ?? ''));
    $total = (float)($order['total_price'] ?? 0);
    if(empty($order['id'])){http_response_code(422);echo json_encode(['error'=>'Bestell-ID fehlt']);exit;}
    $reference = 'shopify-' . (string)$order['id'];
    if ($email === '') {
        echo json_encode(['ignored' => true, 'reason' => 'keine E-Mail in der Bestellung']);
        exit;
    }
    $m = api_member_upsert($businessId, $email, $name);
    $credited = api_credit_order_points($biz, (int)$m['id'], (int)round($total * 100), $reference);
    echo json_encode(['ok' => true, 'member_code' => $m['code'], 'points_credited' => $credited]);
    exit;
}

/* ------------------------------------------------------------------ */
/* API-Dokumentation (oeffentliche Seite + OpenAPI-Spec)               */
/* ------------------------------------------------------------------ */

function openapi_spec(): array
{
    $operation=['type'=>'string','pattern'=>'^[a-f0-9]{32}$','description'=>'Neue Kennung pro beabsichtigter Änderung; bei unklarer Antwort unverändert erneut senden.'];
    $body=static fn(array $properties,array $required)=>['required'=>true,'content'=>['application/json'=>['schema'=>['type'=>'object','properties'=>$properties,'required'=>$required]]]];
    $post=static fn(string $summary,array $properties,array $required)=>['summary'=>$summary,'requestBody'=>$body(['operation'=>$operation]+$properties,array_merge(['operation'],$required)),'responses'=>['200'=>['description'=>'Atomar gebucht oder gespeichertes Replay-Ergebnis'],'401'=>['description'=>'Schlüssel ungültig'],'402'=>['description'=>'Planrecht fehlt'],'422'=>['description'=>'Validierung, Guthaben oder Vorgangskonflikt']]];
    $id=['name'=>'id','in'=>'path','required'=>true,'schema'=>['type'=>'integer','minimum'=>1]];
    return [
        'openapi' => '3.0.0',
        'info' => ['title' => 'Loyalty SaaS REST API', 'version' => '1.0'],
        'servers' => [['url' => base_url() . '/api/v1']],
        'security' => [['bearerAuth' => []]],
        'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        'paths' => [
            '/merchant/summary'=>['get'=>['summary'=>'Aktuelle Unternehmenssalden, keine Umsatzstatistik','responses'=>['200'=>['description'=>'business, members, balances, as_of']]]],
            '/merchant/members'=>[
                'get'=>['summary'=>'Mitglieder suchen, Cursor-Paginierung mit 50 Ergebnissen','parameters'=>[['name'=>'q','in'=>'query','schema'=>['type'=>'string','maxLength'=>100]],['name'=>'after','in'=>'query','schema'=>['type'=>'integer','minimum'=>0]]],'responses'=>['200'=>['description'=>'members und next_after (null am Ende)']]],
                'post'=>$post('Mitglied ohne Marketingeinwilligung oder E-Mail-Versand anlegen',['name'=>['type'=>'string','minLength'=>1,'maxLength'=>120],'email'=>['type'=>'string','format'=>'email','maxLength'=>254]],['name','email'])],
            '/merchant/members/{code}'=>['parameters'=>[['name'=>'code','in'=>'path','required'=>true,'schema'=>['type'=>'string','pattern'=>'^[A-Fa-f0-9]{10}$']]],'get'=>['summary'=>'Mitglied mit Karten und Unternehmensprämien','responses'=>['200'=>['description'=>'Mitglied mit cards und rewards'],'404'=>['description'=>'Nicht im eigenen Unternehmen gefunden']]]],
            '/merchant/members/{id}/edit'=>['parameters'=>[$id],'post'=>$post('Nur Namen ändern, E-Mail muss unverändert bleiben',['name'=>['type'=>'string','maxLength'=>120],'email'=>['type'=>'string','format'=>'email']],['name','email'])],
            '/merchant/cards/{id}/action'=>['parameters'=>[$id],'post'=>$post('Konkrete aktive Karte atomar buchen. Keine externen Folge-Workflows.',['action'=>['type'=>'string','enum'=>['points_add','stamp','redeem_reward','redeem_stamp_reward']],'amount'=>['type'=>'integer','minimum'=>1,'maximum'=>10000],'reward_id'=>['type'=>'integer','minimum'=>1]],['action'])],

            '/business' => ['get' => ['summary' => 'Stammdaten des Business']],
            '/programs' => ['get' => ['summary' => 'Alle Programme']],
            '/members' => [
                'get' => ['summary' => 'Mitgliederliste (max. 200)'],
                'post' => ['summary' => 'Mitglied anlegen oder per E-Mail finden', 'requestBody' => ['name', 'email']],
            ],
            '/members/{code}' => ['get' => ['summary' => 'Mitglied inkl. Karten per Code']],
            '/members/{code}/points' => ['post' => ['summary' => 'Punkte gutschreiben', 'requestBody' => ['amount' => 'int 1-10000']]],
            '/members/{code}/stamp' => ['post' => ['summary' => 'Einen Stempel vergeben (Rollover-Belohnung automatisch)']],
            '/cards/{id}/points' => ['post' => ['summary' => 'Punkte auf eine konkrete Karte gutschreiben']],
            '/transactions' => ['get' => ['summary' => 'Letzte 100 Transaktionen']],
            '/integrations/order' => ['post' => ['summary' => 'Bestellung melden: Mitglied anlegen/finden + Auto-Punkte', 'requestBody' => ['email', 'name', 'total', 'currency', 'reference']]],
        ],
    ];
}

function route_api_docs(): never
{
    $base = base_url() . '/api/v1';
    $rows = [
        ['GET','/merchant/summary',t('Aktuelle Salden des Unternehmens'),'—'],
        ['GET','/merchant/members?q=&after=0',t('Mitglieder suchen, 50 pro Cursor-Seite'),'—'],
        ['GET','/merchant/members/{code}',t('Mitglied, aktive/inaktive Karten und Prämien'),'—'],
        ['POST','/merchant/members',t('Neu anlegen, kein Upsert, atomarer Wiederholschutz'),'{ "operation": "32 kleine Hexzeichen", "name": "Aylin", "email": "a@b.c" }'],
        ['POST','/merchant/members/{id}/edit',t('Namen ändern, unveränderte E-Mail erforderlich'),'{ "operation": "32 kleine Hexzeichen", "name": "Aylin", "email": "a@b.c" }'],
        ['POST','/merchant/cards/{id}/action',t('Punkte/Stempel oder bestätigte Prämieneinlösung, Details in docs/API.md'),'{ "operation": "32 kleine Hexzeichen", "action": "points_add", "amount": 5 }'],
        ['GET', '/business', t('Stammdaten des Business'), '—'],
        ['GET', '/programs', t('Alle Programme mit Typ und Konfiguration'), '—'],
        ['GET', '/members', t('Mitgliederliste mit Kartenständen'), '—'],
        ['POST', '/members', t('Mitglied anlegen (oder per E-Mail finden)'), '{ "name": "Aylin", "email": "a@b.c" }'],
        ['GET', '/members/{code}', t('Ein Mitglied per Code inkl. Karten'), '—'],
        ['POST', '/members/{code}/points', t('Punkte gutschreiben (Bonus-Zeiten gelten)'), '{ "amount": 50 }'],
        ['POST', '/members/{code}/stamp', t('Einen Stempel vergeben; Rollover-Belohnung automatisch'), '{}'],
        ['POST', '/cards/{id}/points', t('Punkte auf konkrete Karte'), '{ "amount": 50 }'],
        ['GET', '/transactions', t('Letzte 100 Transaktionen'), '—'],
        ['POST', '/integrations/order', t('Bestellung melden: Mitglied upserten + Auto-Punkte aus Umsatz (WooCommerce/POS)'), '{ "email": "a@b.c", "name": "Aylin", "total": 24.90, "currency": "EUR", "reference": "order-4711" }'],
    ];
    $trs = '';
    foreach ($rows as [$m, $p, $desc, $body]) {
        $trs .= '<tr><td><code class="method method-' . strtolower($m) . '">' . $m . '</code></td><td><code>' . h($p) . '</code></td><td>' . h($desc) . '</td><td><code class="muted">' . h($body) . '</code></td></tr>';
    }
    page(t('API-Dokumentation'), '
      <h1>' . t('REST API') . '</h1>
      <p class="muted">' . t('Basis-URL:') . ' <code>' . h($base) . '</code> · ' . t('Auth:') . ' <code>Authorization: Bearer DEIN_API_KEY</code><br>
      ' . t('Maschinenlesbar:') . ' <a href="' . h($base) . '/openapi.json">openapi.json</a> · ' . t('Ausführlich: docs/API.md im Repo.') . '</p>
      <h2>' . t('Beispiel') . '</h2>
      <pre class="code-block">curl -X POST ' . h($base) . '/members/ABC123DEF4/points \
  -H "Authorization: Bearer DEIN_API_KEY" \
  -H "Content-Type: application/json" \
  -d \'{"amount": 50}\'</pre>
      <h2>' . t('Endpunkte') . '</h2>
      <table class="table"><thead><tr><th></th><th>' . t('Pfad') . '</th><th>' . t('Beschreibung') . '</th><th>Body</th></tr></thead>
      <tbody>' . $trs . '</tbody></table>
      <h2>' . t('Webhooks') . '</h2>
      <p class="muted">' . t('Events: member.joined, card.stamp, card.points, card.redeem, card.visit, achievement.unlocked. Signatur-Header: X-Webhook-Signature (HMAC-SHA256 mit dem Webhook-Secret).') . '</p>');
    exit;
}
