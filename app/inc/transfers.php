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

/** Restkontingente der letzten 24 Stunden (null = unbegrenzt). */
function transfer_allowance(int $programId, int $fromCard, int $toCard): array
{
    $db = db();
    $p = $db->prepare('SELECT transfer_out_daily,transfer_in_daily,transfer_count_daily FROM programs WHERE id=?');$p->execute([$programId]);$p = $p->fetch() ?: [];
    $sum = function (string $col, int $card) use ($db, $programId): int {
        $q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM point_transfers WHERE program_id=? AND $col=? AND created_at > datetime('now','-1 day')");$q->execute([$programId, $card]);return (int)$q->fetchColumn();
    };
    $cnt = $db->prepare("SELECT COUNT(*) FROM point_transfers WHERE program_id=? AND from_card=? AND created_at > datetime('now','-1 day')");$cnt->execute([$programId, $fromCard]);
    $out = (int)($p['transfer_out_daily'] ?? 0);$in = (int)($p['transfer_in_daily'] ?? 0);$cm = (int)($p['transfer_count_daily'] ?? 0);
    return [
        'out_left' => $out > 0 ? max(0, $out - $sum('from_card', $fromCard)) : null,
        'in_left' => $in > 0 ? max(0, $in - $sum('to_card', $toCard)) : null,
        'count_left' => $cm > 0 ? max(0, $cm - (int)$cnt->fetchColumn()) : null,
        'count_max' => $cm,
    ];
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
        $lim = transfer_allowance((int)$link['program_id'], (int)$from['id'], (int)$to['id']);
        if ($lim['count_left'] !== null && $lim['count_left'] < 1) throw new InvalidArgumentException('Tageslimit erreicht: höchstens ' . $lim['count_max'] . ' Übertragungen in 24 Stunden.');
        if ($lim['out_left'] !== null && $amount > $lim['out_left']) throw new InvalidArgumentException('Tageslimit: du kannst in 24 Stunden noch ' . $lim['out_left'] . ' Punkte senden.');
        if ($lim['in_left'] !== null && $amount > $lim['in_left']) throw new InvalidArgumentException('Der Empfänger kann heute höchstens noch ' . $lim['in_left'] . ' Punkte erhalten.');
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
    else {
        $t = db()->prepare('SELECT id FROM cards WHERE member_id=? AND program_id=?');$t->execute([$link['member_id'], $link['program_id']]);
        $lim = transfer_allowance((int)$link['program_id'], (int)$mine['id'], (int)$t->fetchColumn());
        $cap = min((int)$mine['points'], TRANSFER_MAX_AMOUNT, $lim['out_left'] ?? PHP_INT_MAX, $lim['in_left'] ?? PHP_INT_MAX);
        $hint = [];
        if ($lim['out_left'] !== null) $hint[] = 'Du kannst in 24 Stunden noch ' . $lim['out_left'] . ' Punkte senden.';
        if ($lim['count_left'] !== null) $hint[] = 'Noch ' . $lim['count_left'] . ' Übertragungen in 24 Stunden.';
        if ($lim['in_left'] !== null && $lim['in_left'] < (int)$mine['points']) $hint[] = 'Der Empfänger kann noch ' . $lim['in_left'] . ' Punkte erhalten.';
        if ($cap < 1 || ($lim['count_left'] !== null && $lim['count_left'] < 1)) $body .= '<p>Dein Guthaben: <strong>' . (int)$mine['points'] . ' Punkte</strong></p><p class="notice">' . h($hint ? implode(' ', $hint) : 'Aktuell ist keine Übertragung möglich.') . '</p>';
        else $body .= '<p>Dein Guthaben: <strong>' . (int)$mine['points'] . ' Punkte</strong></p>' . ($hint ? '<p class="muted">' . h(implode(' ', $hint)) . '</p>' : '') . '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="op" value="' . bin2hex(random_bytes(16)) . '">
      <label>Anzahl Punkte<input type="number" name="amount" min="1" max="' . $cap . '" required></label>
      <label class="check"><input type="checkbox" name="confirm" value="1"> Ich möchte diese Punkte von meiner Karte an das Mitglied senden. Das lässt sich nicht rückgängig machen.</label><button class="btn">Punkte senden</button></form>';
    }
    page('Punkte senden', '<div class="auth-box">' . $body . '</div>');
    exit;
}

function route_program_transfer_setting(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if (!$p || $p['type'] !== 'points') { flash(t('Ungültige Eingabe.'), 'err'); redirect('/dashboard'); }
    $on = isset($_POST['enabled']) ? 1 : 0;
    $lim = [];
    foreach (['transfer_out_daily' => 1000000, 'transfer_in_daily' => 1000000, 'transfer_count_daily' => 1000] as $k => $max) {
        $v = (int)($_POST[$k] ?? ($p[$k] ?? 0));
        if ($v < 0 || $v > $max) { flash('Limits zwischen 0 (unbegrenzt) und ' . $max . '.', 'err'); redirect('/program/' . (int)$p['id']); }
        $lim[$k] = $v;
    }
    db()->prepare('UPDATE programs SET points_transfer=?,transfer_out_daily=?,transfer_in_daily=?,transfer_count_daily=? WHERE id=?')
        ->execute([$on, $lim['transfer_out_daily'], $lim['transfer_in_daily'], $lim['transfer_count_daily'], $p['id']]);
    audit_log((int)$u['id'], 'points.transfer.set', ['program_id' => (int)$p['id'], 'enabled' => $on] + $lim);
    flash($on ? 'Punkteanfragen zwischen Mitgliedern gespeichert (aktiv).' : 'Punkteanfragen aus. Bestehende Links funktionieren nicht mehr.');
    redirect('/program/' . (int)$p['id']);
}

function points_transfer_form_html(array $p): string
{
    $n = fn(string $k, int $d): int => (int)($p[$k] ?? $d);
    $h = '<h2>Punkteanfragen zwischen Mitgliedern</h2><p class="muted">Mitglieder können Anfrage-Links teilen und von anderen Mitgliedern Punkte erhalten. Das verschiebt nur Guthaben, Lifetime-Punkte und Stufen bleiben. Standard: aus. Limits gelten pro Karte und gleitend über 24 Stunden, 0 heißt unbegrenzt.</p>
      <form method="post" action="/program/transfer" class="form inline-form panel">' . csrf_field() . '<input type="hidden" name="program_id" value="' . (int)$p['id'] . '">
        <label class="check"><input type="checkbox" name="enabled" value="1"' . ((int)($p['points_transfer'] ?? 0) ? ' checked' : '') . '> Aktiv</label>
        <label>Senden je Karte (Punkte)<input type="number" name="transfer_out_daily" min="0" max="1000000" value="' . $n('transfer_out_daily', 500) . '"></label>
        <label>Empfangen je Karte (Punkte)<input type="number" name="transfer_in_daily" min="0" max="1000000" value="' . $n('transfer_in_daily', 1000) . '"></label>
        <label>Übertragungen je Sender<input type="number" name="transfer_count_daily" min="0" max="1000" value="' . $n('transfer_count_daily', 5) . '"></label>
        <button class="btn">Speichern</button></form>';
    if (!empty($p['id'])) {
        $q = db()->prepare("SELECT t.created_at,t.amount,mf.name AS from_name,mt.name AS to_name FROM point_transfers t JOIN cards cf ON cf.id=t.from_card JOIN members mf ON mf.id=cf.member_id JOIN cards ct ON ct.id=t.to_card JOIN members mt ON mt.id=ct.member_id WHERE t.program_id=? ORDER BY t.id DESC LIMIT 20");
        $q->execute([$p['id']]);$rows = $q->fetchAll();
        if ($rows) {
            $h .= '<h3>Letzte Übertragungen</h3><div class="table-wrap"><table><tr><th>UTC</th><th>Von</th><th>An</th><th>Punkte</th></tr>';
            foreach ($rows as $r) $h .= '<tr><td>' . h($r['created_at']) . '</td><td>' . h($r['from_name']) . '</td><td>' . h($r['to_name']) . '</td><td>' . (int)$r['amount'] . '</td></tr>';
            $h .= '</table></div>';
        }
    }
    return $h;
}
