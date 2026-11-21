<?php
declare(strict_types=1);
/**
 * Mitglieder-Punkteanfragen: Ein Mitglied erzeugt einen Anfrage-Link, ein anderes Mitglied desselben
 * Punkteprogramms sendet darueber Punkte von seinem Guthaben. Opt-in je Punkteprogramm (programs.points_transfer).
 * Lifetime-Punkte (Stufen) aendern sich nicht. Jede Uebertragung steht einmalig in point_transfers (op ist eindeutig).
 */
const TRANSFER_MAX_LINKS = 5;
const TRANSFER_MAX_AMOUNT = 10000;

function transfer_link_url(string $token): string { return base_url() . '/send/' . $token; }

/** Karten, auf denen das Mitglied (E-Mail-Konto) Punkte empfangen kann: eigene Karten in Programmen mit aktivem Transfer. */
function transfer_receive_cards(string $email): array
{
    $q = db()->prepare("SELECT c.id AS card_id, c.points, p.id AS program_id, p.name AS program_name, m.id AS member_id, b.name AS business
        FROM members m JOIN cards c ON c.member_id=m.id JOIN programs p ON p.id=c.program_id JOIN businesses b ON b.id=p.business_id
        WHERE lower(m.email)=lower(?) AND p.type='points' AND p.active=1 AND p.points_transfer=1 ORDER BY b.name,p.name");
    $q->execute([$email]);
    return $q->fetchAll();
}

function route_member_requests(string $method): never
{
    $email = require_member();
    $cards = transfer_receive_cards($email);
    $byProgram = [];
    foreach ($cards as $c) $byProgram[(int)$c['program_id']] = $c;
    $rows = db()->prepare('SELECT l.*, p.name AS program_name FROM point_request_links l LEFT JOIN programs p ON p.id=l.program_id
        WHERE l.member_id IN (SELECT id FROM members WHERE lower(email)=lower(?)) ORDER BY l.id DESC');
    $rows->execute([$email]);
    $links = $rows->fetchAll();
    if ($method === 'POST') {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'create') {
                $pid = (int)($_POST['program_id'] ?? 0);
                if (!isset($byProgram[$pid])) throw new InvalidArgumentException('Für diese Karte sind keine Punkteanfragen möglich.');
                if (count($links) >= TRANSFER_MAX_LINKS) throw new InvalidArgumentException('Höchstens ' . TRANSFER_MAX_LINKS . ' Anfrage-Links. Lösche einen alten Link.');
                db()->prepare('INSERT INTO point_request_links(member_id,program_id,token) VALUES(?,?,?)')->execute([$byProgram[$pid]['member_id'], $pid, bin2hex(random_bytes(16))]);
                flash('Anfrage-Link erstellt.');
            } elseif ($action === 'delete') {
                $ids = array_map('intval', array_column($links, 'id'));
                $id = (int)($_POST['link_id'] ?? 0);
                if (!in_array($id, $ids, true)) throw new InvalidArgumentException('Link nicht gefunden.');
                db()->prepare('DELETE FROM point_request_links WHERE id=?')->execute([$id]);
                flash('Link gelöscht.');
            } else throw new InvalidArgumentException('Ungültige Aktion.');
        } catch (InvalidArgumentException $e) { flash($e->getMessage(), 'err'); }
        redirect('/wallet/request');
    }
    $opts = '';
    foreach ($cards as $c) $opts .= '<option value="' . (int)$c['program_id'] . '">' . h($c['business'] . ' · ' . $c['program_name']) . ' (' . (int)$c['points'] . ' P.)</option>';
    $body = '<p><a href="/wallet" class="muted">← Wallet</a></p><h1>Punkte anfordern</h1>
      <p class="muted">Teile den Link mit einer Person, die bei demselben Geschäft sammelt. Sie bestimmt selbst, wie viele Punkte sie dir sendet. Jeder, der den Link öffnet und angemeldet ist, kann dir Punkte senden. Teile ihn nur mit Menschen, die du kennst.</p>';
    $body .= $opts ? '<form method="post" class="form inline-form panel">' . csrf_field() . '<input type="hidden" name="action" value="create"><label>Karte<select name="program_id">' . $opts . '</select></label><button class="btn">Link erstellen</button></form>'
        : '<p class="notice">Aktuell bietet keines deiner Programme Punkteanfragen an, oder du hast dort noch keine Karte.</p>';
    $body .= '<h2>Deine Links</h2>';
    if (!$links) $body .= '<p class="muted">Noch keine Links.</p>';
    foreach ($links as $l) {
        $body .= '<section class="panel"><strong>' . h((string)$l['program_name']) . '</strong><p><code>' . h(transfer_link_url($l['token'])) . '</code></p><p class="muted">' . (int)$l['uses'] . ' Mal genutzt · ' . h(substr($l['created_at'], 0, 10)) . '</p>
          <form method="post" onsubmit="return confirm(\'Link löschen?\')">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="link_id" value="' . (int)$l['id'] . '"><button class="btn ghost">Löschen</button></form></section>';
    }
    page('Punkte anfordern', $body);
    exit;
}

/** Ergebnis: [Fehlertext|null]. Ausfuehrung atomar und je op genau einmal. */
function transfer_points(int $linkId, int $fromCardId, int $amount, string $op): void
{
    $db = db();
    if ($amount < 1 || $amount > TRANSFER_MAX_AMOUNT) throw new InvalidArgumentException('Anzahl zwischen 1 und ' . TRANSFER_MAX_AMOUNT . '.');
    if (!preg_match('/^[a-f0-9]{16,64}$/', $op)) throw new InvalidArgumentException('Ungültige Anfrage.');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $dup = $db->prepare('SELECT 1 FROM point_transfers WHERE op=?');$dup->execute([$op]);
        if ($dup->fetch()) { $db->exec('COMMIT'); return; }
        $l = $db->prepare('SELECT * FROM point_request_links WHERE id=?');$l->execute([$linkId]);$link = $l->fetch();
        if (!$link || !(int)$link['active']) throw new InvalidArgumentException('Link nicht gültig.');
        $f = $db->prepare('SELECT c.*,p.points_transfer,p.active,p.type,p.business_id,m.id AS mid FROM cards c JOIN programs p ON p.id=c.program_id JOIN members m ON m.id=c.member_id WHERE c.id=?');$f->execute([$fromCardId]);$from = $f->fetch();
        if (!$from || $from['type'] !== 'points' || !(int)$from['active'] || !(int)$from['points_transfer'] || (int)$from['program_id'] !== (int)$link['program_id'])
            throw new InvalidArgumentException('Diese Karte passt nicht zur Anfrage.');
        $t = $db->prepare('SELECT c.id,c.member_id FROM cards c WHERE c.member_id=? AND c.program_id=?');$t->execute([$link['member_id'], $link['program_id']]);$to = $t->fetch();
        if (!$to) throw new InvalidArgumentException('Der Empfänger hat keine Karte mehr.');
        if ((int)$to['id'] === (int)$from['id'] || (int)$to['member_id'] === (int)$from['member_id']) throw new InvalidArgumentException('Du kannst dir nicht selbst Punkte senden.');
        if ((int)$from['points'] < $amount) throw new InvalidArgumentException('Nicht genug Punkte.');
        $db->prepare('UPDATE cards SET points=points-? WHERE id=? AND points>=?')->execute([$amount, $from['id'], $amount]);
        $db->prepare('UPDATE cards SET points=points+? WHERE id=?')->execute([$amount, $to['id']]);
        $db->prepare('INSERT INTO point_transfers(op,link_id,program_id,from_card,to_card,amount)VALUES(?,?,?,?,?,?)')->execute([$op, $linkId, $link['program_id'], $from['id'], $to['id'], $amount]);
        $db->prepare('UPDATE point_request_links SET uses=uses+1 WHERE id=?')->execute([$linkId]);
        txn((int)$from['id'], 'points_transfer_out', -$amount, 'Punkte gesendet', ['kind' => 'owner']);
        txn((int)$to['id'], 'points_transfer_in', $amount, 'Punkte erhalten', ['kind' => 'owner']);
        member_message((int)$to['member_id'], 'transfer', '🎁 Du hast ' . $amount . ' Punkte von einem Mitglied erhalten.');
        $db->exec('COMMIT');
    } catch (Throwable $e) { try { $db->exec('ROLLBACK'); } catch (Throwable) {} throw $e; }
}

function route_send_points(string $token, string $method): never
{
    $email = require_member();
    $l = db()->prepare('SELECT l.*,p.name AS program_name,p.points_transfer,p.active AS pactive,b.name AS business FROM point_request_links l JOIN programs p ON p.id=l.program_id JOIN businesses b ON b.id=p.business_id WHERE l.token=?');
    $l->execute([$token]);$link = $l->fetch();
    if (!$link || !(int)$link['active'] || !(int)$link['points_transfer'] || !(int)$link['pactive']) not_found();
    $q = db()->prepare('SELECT c.id,c.points FROM cards c JOIN members m ON m.id=c.member_id WHERE lower(m.email)=lower(?) AND c.program_id=?');
    $q->execute([$email, $link['program_id']]);$mine = $q->fetch();
    $self = $mine && (int)db()->query('SELECT member_id FROM cards WHERE id=' . (int)$mine['id'])->fetchColumn() === (int)$link['member_id'];
    $msg = '';
    if ($method === 'POST' && $mine && !$self) {
        try {
            if (!isset($_POST['confirm'])) throw new InvalidArgumentException('Bitte bestätige, dass die Punkte von deiner Karte abgezogen werden.');
            transfer_points((int)$link['id'], (int)$mine['id'], (int)($_POST['amount'] ?? 0), (string)($_POST['op'] ?? ''));
            flash('Punkte gesendet.');redirect('/wallet');
        } catch (InvalidArgumentException $e) { flash($e->getMessage(), 'err'); redirect('/send/' . $token); }
    }
    $body = '<h1>Punkte senden</h1><p class="muted">' . h($link['business'] . ' · ' . $link['program_name']) . '</p>';
    if (!$mine) $body .= '<p class="notice">Du hast bei diesem Geschäft keine Karte in diesem Programm und kannst keine Punkte senden.</p>';
    elseif ($self) $body .= '<p class="notice">Das ist dein eigener Link. Teile ihn mit jemand anderem.</p>';
    else $body .= '<p>Dein Guthaben: <strong>' . (int)$mine['points'] . ' Punkte</strong></p><form method="post" class="form">' . csrf_field() . '<input type="hidden" name="op" value="' . bin2hex(random_bytes(16)) . '">
      <label>Anzahl Punkte<input type="number" name="amount" min="1" max="' . min((int)$mine['points'], TRANSFER_MAX_AMOUNT) . '" required></label>
      <label class="check"><input type="checkbox" name="confirm" value="1"> Ich möchte diese Punkte von meiner Karte an das Mitglied senden. Das lässt sich nicht rückgängig machen.</label><button class="btn">Punkte senden</button></form>';
    page('Punkte senden', '<div class="auth-box">' . $body . '</div>');
    exit;
}

function route_program_transfer_setting(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if (!$p || $p['type'] !== 'points') { flash(t('Ungültige Eingabe.'), 'err'); redirect('/dashboard'); }
    $on = isset($_POST['enabled']) ? 1 : 0;
    db()->prepare('UPDATE programs SET points_transfer=? WHERE id=?')->execute([$on, $p['id']]);
    audit_log((int)$u['id'], 'points.transfer.set', ['program_id' => (int)$p['id'], 'enabled' => $on]);
    flash($on ? 'Punkteanfragen zwischen Mitgliedern aktiv.' : 'Punkteanfragen aus. Bestehende Links funktionieren nicht mehr.');
    redirect('/program/' . (int)$p['id']);
}

function points_transfer_form_html(array $p): string
{
    return '<h2>Punkteanfragen zwischen Mitgliedern</h2><p class="muted">Mitglieder können Anfrage-Links teilen und von anderen Mitgliedern Punkte erhalten. Das verschiebt nur Guthaben, Lifetime-Punkte und Stufen bleiben. Standard: aus.</p>
      <form method="post" action="/program/transfer" class="form inline-form panel">' . csrf_field() . '<input type="hidden" name="program_id" value="' . (int)$p['id'] . '">
        <label class="check"><input type="checkbox" name="enabled" value="1"' . ((int)($p['points_transfer'] ?? 0) ? ' checked' : '') . '> Aktiv</label><button class="btn">Speichern</button></form>';
}
