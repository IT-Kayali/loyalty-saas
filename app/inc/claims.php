<?php
declare(strict_types=1);
/**
 * Einloesecodes fuer Punkte-Praemien: Das Mitglied erzeugt in der Wallet einen kurzlebigen, einmaligen Code (mit QR)
 * fuer eine Praemie. An der Kasse bestaetigt Owner oder Team den Code, erst dann werden die Punkte abgebucht.
 * Opt-in je Punkteprogramm (programs.claim_minutes, 0 = aus). Keine Reservierung: gebucht wird beim Einloesen.
 */
const CLAIM_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const CLAIM_MAX_ACTIVE = 3;
const CLAIM_MAX_MINUTES = 1440;

function claim_new_code(): string
{
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= CLAIM_ALPHABET[random_int(0, strlen(CLAIM_ALPHABET) - 1)];
    return $s;
}
function claim_format(string $code): string { return substr($code, 0, 4) . '-' . substr($code, 4); }
function claim_normalize(string $in): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $in) ?? ''); }
function claim_url(string $code): string { return base_url() . '/claim/' . $code; }

/** active | expired | used | cancelled */
function claim_state(array $c): string
{
    if ($c['status'] === 'active' && $c['expires_at'] <= gmdate('Y-m-d H:i:s')) return 'expired';
    return (string)$c['status'];
}
function claim_state_label(string $s): string
{
    return ['active' => 'Gültig', 'expired' => 'Abgelaufen', 'used' => 'Eingelöst', 'cancelled' => 'Zurückgezogen'][$s] ?? $s;
}

/** Zeitzone des Unternehmens, dem die Praemie gehoert. */
function reward_tz(array $r): string
{
    $q = db()->prepare('SELECT p.business_id FROM programs p WHERE p.id=?');
    $q->execute([(int)($r['program_id'] ?? 0)]);
    $bid = (int)$q->fetchColumn();
    $q->closeCursor();
    return $bid > 0 ? biz_tz($bid) : 'UTC';
}
function reward_time(string $utc, string $tz): string { return tz_local($utc, $tz, 'd.m.Y H:i') . ' ' . tz_abbr($utc, $tz); }

/** Zeitfenster einer Praemie. null = innerhalb oder unbegrenzt, sonst Klartext-Grund (Zeiten in der Zeitzone des Unternehmens). */
function reward_window_error(array $r, ?string $now = null): ?string
{
    $now = $now ?? gmdate('Y-m-d H:i:s');
    $f = (string)($r['valid_from'] ?? '');
    $u = (string)($r['valid_until'] ?? '');
    if ($f === '' && $u === '') return null;
    $tz = reward_tz($r);
    if ($f !== '' && $now < $f) return 'Die Prämie „' . $r['name'] . '“ ist erst ab ' . reward_time($f, $tz) . ' verfügbar.';
    if ($u !== '' && $now >= $u) return 'Die Prämie „' . $r['name'] . '“ ist seit ' . reward_time($u, $tz) . ' nicht mehr verfügbar.';
    return null;
}
function reward_window_label(array $r): string
{
    $f = (string)($r['valid_from'] ?? '');
    $u = (string)($r['valid_until'] ?? '');
    if ($f === '' && $u === '') return '';
    $tz = reward_tz($r);
    return ($f !== '' ? 'ab ' . reward_time($f, $tz) : '') . ($f !== '' && $u !== '' ? ' ' : '') . ($u !== '' ? 'bis ' . reward_time($u, $tz) : '');
}

/** Erzeugt einen Code. $memberIds: Mitglieds-IDs des angemeldeten Kontos. */
function claim_create(array $memberIds, int $cardId, int $rewardId): array
{
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $q = $db->prepare("SELECT c.*, p.claim_minutes, p.type, p.active FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.id=?");
        $q->execute([$cardId]);
        $card = $q->fetch();
        if (!$card || !in_array((int)$card['member_id'], $memberIds, true) || $card['type'] !== 'points' || !(int)$card['active'] || (int)$card['claim_minutes'] <= 0) {
            throw new InvalidArgumentException('Für diese Karte sind keine Einlösecodes möglich.');
        }
        $r = $db->prepare('SELECT * FROM rewards WHERE id=? AND program_id=?');
        $r->execute([$rewardId, $card['program_id']]);
        $reward = $r->fetch();
        if (!$reward) throw new InvalidArgumentException('Prämie nicht gefunden.');
        if ($werr = reward_window_error($reward)) throw new InvalidArgumentException($werr);
        if ((int)$card['points'] < (int)$reward['points_cost']) throw new InvalidArgumentException('Nicht genug Punkte für diese Prämie.');
        $act = $db->prepare("SELECT reward_id FROM reward_claims WHERE card_id=? AND status='active' AND expires_at>?");
        $act->execute([$cardId, gmdate('Y-m-d H:i:s')]);
        $open = $act->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($rewardId, array_map('intval', $open), true)) throw new InvalidArgumentException('Für diese Prämie hast du schon einen gültigen Code.');
        if (count($open) >= CLAIM_MAX_ACTIVE) throw new InvalidArgumentException('Höchstens ' . CLAIM_MAX_ACTIVE . ' gültige Codes gleichzeitig. Ziehe einen zurück oder warte, bis er abläuft.');
        $exp = gmdate('Y-m-d H:i:s', time() + (int)$card['claim_minutes'] * 60);
        if ((string)($reward['valid_until'] ?? '') !== '' && $reward['valid_until'] < $exp) $exp = $reward['valid_until'];
        for ($try = 0; $try < 5; $try++) {
            $code = claim_new_code();
            $ins = $db->prepare("INSERT OR IGNORE INTO reward_claims(card_id,reward_id,reward_name,points_cost,code,expires_at) VALUES(?,?,?,?,?,?)");
            $ins->execute([$cardId, $rewardId, $reward['name'], (int)$reward['points_cost'], $code, $exp]);
            if ($ins->rowCount() === 1) {
                $db->exec('COMMIT');
                return ['code' => $code, 'expires_at' => $exp, 'reward' => $reward['name']];
            }
        }
        throw new RuntimeException('Code konnte nicht erzeugt werden.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}

/** Code laden, nur im Unternehmen $businessId. */
function claim_load(int $businessId, string $code): ?array
{
    $q = db()->prepare("SELECT rc.*, c.points, c.member_id, m.name AS member_name, m.code AS member_code, p.name AS program_name
        FROM reward_claims rc JOIN cards c ON c.id=rc.card_id JOIN programs p ON p.id=c.program_id JOIN members m ON m.id=c.member_id
        WHERE rc.code=? AND p.business_id=?");
    $q->execute([claim_normalize($code), $businessId]);
    return $q->fetch() ?: null;
}

/** Loest den Code ein: Punkte abbuchen, Code verbrauchen. Genau einmal. Wirft InvalidArgumentException mit Klartext. */
function claim_redeem(int $businessId, string $code, array $actor): array
{
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $c = claim_load($businessId, $code);
        if (!$c) throw new InvalidArgumentException('Code nicht gefunden.');
        $state = claim_state($c);
        if ($state !== 'active') throw new InvalidArgumentException('Code ' . strtolower(claim_state_label($state)) . '. Es wurde nichts abgebucht.');
        $rw = $db->prepare('SELECT * FROM rewards WHERE id=?');
        $rw->execute([(int)$c['reward_id']]);
        if (($rwRow = $rw->fetch()) && ($werr = reward_window_error($rwRow))) throw new InvalidArgumentException($werr . ' Es wurde nichts abgebucht.');
        if ((int)$c['points'] < (int)$c['points_cost']) throw new InvalidArgumentException('Das Mitglied hat nicht mehr genug Punkte (' . (int)$c['points'] . ' von ' . (int)$c['points_cost'] . '). Es wurde nichts abgebucht.');
        $u = $db->prepare("UPDATE reward_claims SET status='used', used_at=datetime('now'), used_by=? WHERE id=? AND status='active'");
        $u->execute([$actor['kind'] . ':' . (int)$actor['id'], $c['id']]);
        if ($u->rowCount() !== 1) throw new InvalidArgumentException('Code wurde gerade eingelöst.');
        $db->prepare('UPDATE cards SET points=points-? WHERE id=?')->execute([$c['points_cost'], $c['card_id']]);
        txn((int)$c['card_id'], 'points_redeem', (int)$c['points_cost'], t('Eingelöst:') . ' ' . $c['reward_name'] . ' (−' . (int)$c['points_cost'] . ' ' . t('Punkte') . ', Code ' . claim_format($c['code']) . ')', $actor);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
    check_achievements((int)$c['member_id'], $businessId);
    fire_webhook($businessId, 'card.redeem', ['card_id' => (int)$c['card_id'], 'member_id' => (int)$c['member_id'], 'action' => 'redeem_claim']);
    wallet_pass_update_for_card((int)$c['card_id']);
    return $c;
}

/** GET /claim/{code}: Ziel des QR. Leitet angemeldete Teams/Owner zur Kasse, alle anderen bekommen nichts Persoenliches zu sehen. */
function route_claim_entry(string $code): never
{
    $code = claim_normalize($code);
    $q = db()->prepare("SELECT p.business_id FROM reward_claims rc JOIN cards c ON c.id=rc.card_id JOIN programs p ON p.id=c.program_id WHERE rc.code=?");
    $q->execute([$code]);
    $bid = (int)$q->fetchColumn();
    $staff = current_staff();
    if ($bid && $staff && (int)$staff['business_id'] === $bid) redirect('/staff/business/' . $bid . '/claim-counter?code=' . $code);
    $u = current_user();
    if ($bid && $u && own_business((int)$u['id'], $bid)) redirect('/business/' . $bid . '/claim-counter?code=' . $code);
    page('Einlösecode', '<div class="auth-box"><h1>' . t('Einlösecode') . '</h1><p>' . t('Dieser Code wird an der Kasse vom Team bestätigt. Bitte dort vorzeigen.') . '</p><p><a href="/staff/login">' . t('Team-Anmeldung') . '</a></p></div>');
    exit;
}

function route_claim_counter(int $bid, string $method): never
{
    $a = business_actor($bid);
    actor_permission($a, 'redeem');
    $q = db()->prepare('SELECT * FROM businesses WHERE id=?');
    $q->execute([$bid]);
    $b = $q->fetch();
    if (!$b) not_found();
    $base = ($a['kind'] === 'staff' ? '/staff' : '') . '/business/' . $bid . '/claim-counter';
    $btz = biz_tz($bid);
    if ($method === 'POST') {
        try {
            $code = (string)($_POST['code'] ?? '');
            if (($_POST['action'] ?? '') === 'redeem') {
                $c = claim_redeem($bid, $code, $a);
                flash('Prämie "' . $c['reward_name'] . '" für ' . $c['member_name'] . ' eingelöst, ' . (int)$c['points_cost'] . ' Punkte abgebucht.');
                redirect($base);
            }
            redirect($base . '?code=' . claim_normalize($code));
        } catch (InvalidArgumentException $e) {
            flash($e->getMessage(), 'err');
            redirect($base);
        }
    }
    $body = '<p><a href="' . ($a['kind'] === 'owner' ? '/business/' . $bid : '/staff') . '">← Zurück</a></p><h1>Prämien-Code einlösen</h1><p class="muted">Unternehmen: ' . h($b['name']) . '. Der Code kommt aus der Wallet des Mitglieds (Anzeige oder QR). Erst die Bestätigung bucht die Punkte ab.</p>';
    if ($a['kind'] === 'staff') $body .= '<p><a class="btn ghost small" href="/staff/scan">Kunden- oder Prämien-QR scannen</a></p>';
    $body .= '<form method="post" action="' . $base . '" class="form inline-form">' . csrf_field() . '<input type="hidden" name="action" value="preview"><label>Code<input name="code" value="' . h(claim_format(claim_normalize((string)($_GET['code'] ?? '')))) . '" maxlength="12" autocomplete="off" autocapitalize="characters" required></label><button class="btn">Prüfen</button></form>';
    $want = claim_normalize((string)($_GET['code'] ?? ''));
    if ($want !== '') {
        $c = claim_load($bid, $want);
        if (!$c) {
            $body .= '<section class="panel"><p>Code nicht gefunden.</p></section>';
        } else {
            $st = claim_state($c);
            $enough = (int)$c['points'] >= (int)$c['points_cost'];
            $body .= '<section class="panel claim-preview"><h2>' . h($c['reward_name']) . '</h2><p>Mitglied: <strong>' . h($c['member_name']) . '</strong> (' . h($c['member_code']) . ')</p><p>Kosten: ' . (int)$c['points_cost'] . ' Punkte, Guthaben jetzt: ' . (int)$c['points'] . '</p><p>Status: <strong>' . h(claim_state_label($st)) . '</strong>, gültig bis ' . h(reward_time((string)$c['expires_at'], $btz)) . '</p>';
            $rwq = db()->prepare('SELECT * FROM rewards WHERE id=?');
            $rwq->execute([(int)$c['reward_id']]);
            $rwr = $rwq->fetch();
            $werr = $rwr ? reward_window_error($rwr) : null;
            if ($rwr && reward_window_label($rwr)) $body .= '<p>Zeitfenster der Prämie: ' . h(reward_window_label($rwr)) . '</p>';
            if ($werr && $st === 'active') {
                $body .= '<p class="claim-window-err"><strong>Außerhalb des Zeitfensters:</strong> ' . h($werr) . '</p>';
            } elseif ($st === 'active' && $enough) {
                $body .= '<form method="post" action="' . $base . '">' . csrf_field() . '<input type="hidden" name="action" value="redeem"><input type="hidden" name="code" value="' . h($c['code']) . '"><button class="btn accent">Einlösen und ' . (int)$c['points_cost'] . ' Punkte abbuchen</button></form>';
            } elseif ($st === 'active') {
                $body .= '<p class="muted">Nicht genug Punkte. Einlösen ist nicht möglich.</p>';
            }
            $body .= '</section>';
        }
    }
    $r = db()->prepare("SELECT rc.*, m.name AS member_name FROM reward_claims rc JOIN cards c ON c.id=rc.card_id JOIN programs p ON p.id=c.program_id JOIN members m ON m.id=c.member_id WHERE p.business_id=? ORDER BY rc.id DESC LIMIT 20");
    $r->execute([$bid]);
    $rows = '';
    foreach ($r->fetchAll() as $x) {
        $rows .= '<tr><td data-label="Code"><code>' . h(claim_format($x['code'])) . '</code></td><td data-label="Mitglied">' . h($x['member_name']) . '</td><td data-label="Prämie">' . h($x['reward_name']) . '</td><td data-label="Status">' . h(claim_state_label(claim_state($x))) . '</td><td data-label="Erzeugt">' . h(tz_local((string)$x['created_at'], $btz, 'd.m.Y H:i')) . '</td></tr>';
    }
    $body .= '<h2>Letzte Codes</h2>' . ($rows ? '<div class="table-wrap"><table><thead><tr><th>Code</th><th>Mitglied</th><th>Prämie</th><th>Status</th><th>Erzeugt</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="muted">Noch keine Codes.</p>');
    page('Prämien-Code einlösen', $body, $a['kind'] === 'owner' ? $a['user'] : null);
    exit;
}

/** Wallet: Codes und Erzeugen-Buttons fuer ein Mitglied. */
function member_claims_html(int $memberId): string
{
    $q = db()->prepare("SELECT c.id AS card_id, c.points, p.id AS program_id, p.name, p.claim_minutes FROM cards c JOIN programs p ON p.id=c.program_id
        WHERE c.member_id=? AND p.type='points' AND p.active=1 AND p.claim_minutes>0 ORDER BY p.name");
    $q->execute([$memberId]);
    $out = '';
    foreach ($q->fetchAll() as $c) {
        $act = db()->prepare("SELECT * FROM reward_claims WHERE card_id=? AND status='active' AND expires_at>? ORDER BY id DESC");
        $act->execute([$c['card_id'], gmdate('Y-m-d H:i:s')]);
        $claims = $act->fetchAll();
        $out .= '<section class="panel claim-box"><h3>' . h($c['name']) . ': Prämie einlösen</h3><p class="muted">Erzeuge einen Code und zeige ihn an der Kasse. Er gilt ' . (int)$c['claim_minutes'] . ' Minuten. Die Punkte werden erst beim Einlösen abgebucht.</p>';
        foreach ($claims as $x) {
            $left = max(1, (int)ceil((strtotime($x['expires_at'] . ' UTC') - time()) / 60));
            $out .= '<div class="claim-active"><div class="qr-box" data-qr="' . h(claim_url($x['code'])) . '" data-qr-size="160"></div><div><p class="claim-code">' . h(claim_format($x['code'])) . '</p><p>' . h($x['reward_name']) . ' · ' . (int)$x['points_cost'] . ' Punkte</p><p class="muted">Noch ' . $left . ' Min. gültig</p>'
                . '<form method="post" action="/wallet/claim">' . csrf_field() . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="claim_id" value="' . (int)$x['id'] . '"><button class="btn ghost small">Zurückziehen</button></form></div></div>';
        }
        $rw = db()->prepare('SELECT * FROM rewards WHERE program_id=? ORDER BY points_cost');
        $rw->execute([$c['program_id']]);
        $have = array_map('intval', array_column($claims, 'reward_id'));
        foreach ($rw->fetchAll() as $r) {
            if ((int)$c['points'] < (int)$r['points_cost'] || in_array((int)$r['id'], $have, true)) continue;
            if ((string)($r['valid_until'] ?? '') !== '' && reward_window_error($r) && $r['valid_until'] <= gmdate('Y-m-d H:i:s')) continue;
            if ($werr = reward_window_error($r)) { $out .= '<p class="muted claim-soon">„' . h($r['name']) . '“: ' . h(reward_window_label($r)) . '</p>'; continue; }
            $out .= '<form method="post" action="/wallet/claim" class="claim-new">' . csrf_field() . '<input type="hidden" name="action" value="create"><input type="hidden" name="card_id" value="' . (int)$c['card_id'] . '"><input type="hidden" name="reward_id" value="' . (int)$r['id'] . '"><button class="btn small">Code für „' . h($r['name']) . '“ (' . (int)$r['points_cost'] . ' Punkte)</button>' . (reward_window_label($r) ? '<span class="muted claim-window">' . h(reward_window_label($r)) . '</span>' : '') . '</form>';
        }
        $out .= '</section>';
    }
    return $out;
}

function route_wallet_claim(): never
{
    $email = require_member();
    $rows = member_rows_by_email($email);
    $ids = array_map('intval', array_column($rows, 'id'));
    try {
        if (($_POST['action'] ?? '') === 'create') {
            $r = claim_create($ids, (int)($_POST['card_id'] ?? 0), (int)($_POST['reward_id'] ?? 0));
            flash('Code ' . claim_format($r['code']) . ' für „' . $r['reward'] . '“ erzeugt.');
        } elseif (($_POST['action'] ?? '') === 'cancel') {
            $q = db()->prepare("UPDATE reward_claims SET status='cancelled' WHERE id=? AND status='active' AND card_id IN (SELECT id FROM cards WHERE member_id IN (" . ($ids ? implode(',', $ids) : '0') . "))");
            $q->execute([(int)($_POST['claim_id'] ?? 0)]);
            flash($q->rowCount() === 1 ? 'Code zurückgezogen.' : 'Code nicht gefunden.', $q->rowCount() === 1 ? 'ok' : 'err');
        } else throw new InvalidArgumentException('Ungültige Aktion.');
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('/wallet');
}

/** Owner: Einstellung je Punkteprogramm. */
function route_program_claims(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if (!$p || $p['type'] !== 'points') { flash(t('Ungültige Eingabe.'), 'err'); redirect('/dashboard'); }
    $back = '/program/' . (int)$p['id'];
    $min = (int)($_POST['minutes'] ?? 0);
    if ($min < 0 || $min > CLAIM_MAX_MINUTES) { flash('Minuten zwischen 0 (aus) und ' . CLAIM_MAX_MINUTES . '.', 'err'); redirect($back); }
    db()->prepare('UPDATE programs SET claim_minutes=? WHERE id=?')->execute([$min, $p['id']]);
    audit_log((int)$u['id'], 'claims.set', ['program_id' => (int)$p['id'], 'minutes' => $min]);
    flash($min > 0 ? 'Einlösecodes aktiv, gültig ' . $min . ' Minuten.' : 'Einlösecodes aus.');
    redirect($back);
}

function program_claims_form_html(array $p): string
{
    $m = (int)($p['claim_minutes'] ?? 0);
    return '<h2>Einlösecodes</h2><p class="muted">Mitglieder erzeugen in der Wallet einen kurzlebigen Code (mit QR) für eine Prämie. Das Team bestätigt ihn an der Kasse, erst dann werden die Punkte abgebucht. 0 = aus.</p>
      <form method="post" action="/program/claims" class="form inline-form panel">' . csrf_field() . '<input type="hidden" name="program_id" value="' . (int)$p['id'] . '">
        <label>Gültigkeit in Minuten (0 = aus) <input type="number" name="minutes" value="' . $m . '" min="0" max="' . CLAIM_MAX_MINUTES . '"></label>
        <button class="btn">Speichern</button>
        <a href="/business/' . (int)$p['business_id'] . '/claim-counter">Code an der Kasse einlösen</a></form>';
}

/** POST /reward/window: Zeitfenster einer Praemie setzen (Owner). */
function route_reward_window(): never
{
    $u = require_login();
    $q = db()->prepare('SELECT r.*, p.id AS pid FROM rewards r JOIN programs p ON p.id=r.program_id WHERE r.id=?');
    $q->execute([(int)($_POST['reward_id'] ?? 0)]);
    $r = $q->fetch();
    $p = $r ? own_program((int)$u['id'], (int)$r['pid']) : null;
    if (!$p) { flash(t('Ungültige Eingabe.'), 'err'); redirect('/dashboard'); }
    $back = '/program/' . (int)$p['id'];
    $tz = reward_tz($r);
    $f = tz_input_to_utc((string)($_POST['valid_from'] ?? ''), $tz);
    $to = tz_input_to_utc((string)($_POST['valid_until'] ?? ''), $tz);
    if ($f === false || $to === false) { flash('Datum ungültig.', 'err'); redirect($back); }
    if ($f !== null && $to !== null && $to <= $f) { flash('Das Ende muss nach dem Start liegen.', 'err'); redirect($back); }
    db()->prepare('UPDATE rewards SET valid_from=?, valid_until=? WHERE id=?')->execute([$f, $to, $r['id']]);
    audit_log((int)$u['id'], 'reward.window', ['reward_id' => (int)$r['id'], 'valid_from' => $f, 'valid_until' => $to]);
    flash($f === null && $to === null ? 'Zeitfenster entfernt.' : 'Zeitfenster gespeichert (' . $tz . ').');
    redirect($back);
}

function reward_windows_form_html(array $p): string
{
    $q = db()->prepare('SELECT * FROM rewards WHERE program_id=? ORDER BY points_cost');
    $q->execute([$p['id']]);
    $rows = '';
    $tz = biz_tz((int)$p['business_id']);
    foreach ($q->fetchAll() as $r) {
        $v = fn($x) => $x ? h(tz_local((string)$x, $tz, 'Y-m-d\\TH:i')) : '';
        $now = reward_window_error($r);
        $rows .= '<form method="post" action="/reward/window" class="form inline-form panel reward-window">' . csrf_field() . '<input type="hidden" name="reward_id" value="' . (int)$r['id'] . '"><strong>' . h($r['name']) . '</strong>'
            . '<label>Ab <input type="datetime-local" name="valid_from" value="' . $v($r['valid_from']) . '"></label><label>Bis <input type="datetime-local" name="valid_until" value="' . $v($r['valid_until']) . '"></label><button class="btn">Speichern</button>'
            . (reward_window_label($r) ? '<span class="muted">' . ($now ? 'Gerade nicht verfügbar' : 'Gerade verfügbar') . '</span>' : '') . '</form>';
    }
    return $rows === '' ? '' : '<h2>Zeitfenster für Prämien</h2><p class="muted">Optional je Prämie: Einlösen und Codes nur zwischen Start und Ende. Zeiten in der Zeitzone des Unternehmens: ' . h($tz) . ' (änderbar auf der Unternehmensseite). Leer = immer. Ein Code läuft spätestens am Ende des Fensters ab.</p>' . $rows;
}
