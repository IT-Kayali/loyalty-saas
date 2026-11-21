<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Etappe 5: Kunden-Segmente + E-Mail-Kampagnen                        */
/* Doku: "Segments" (dynamische Kundengruppen) + "Campaigns"           */
/* ------------------------------------------------------------------ */

const SEGMENT_RULES = [
    'all'           => 'Alle Mitglieder',
    'new_days'      => 'Neu: beigetreten in den letzten X Tagen',
    'active_days'   => 'Aktiv: Aktion in den letzten X Tagen',
    'inactive_days' => 'Inaktiv: keine Aktion seit X Tagen',
    'min_points'    => 'Mind. X Lifetime-Punkte',
    'min_stamps'    => 'Mind. X Stempel gesamt',
];

/** Wertet ein Segment aus und liefert die Mitglieder-IDs. */
function segment_members(array $segment): array
{
    $bid = (int)$segment['business_id'];
    $param = (int)$segment['param'];
    switch ($segment['rule']) {
        case 'all':
            $stmt = db()->prepare('SELECT id FROM members WHERE business_id = ?');
            $stmt->execute([$bid]);
            break;
        case 'new_days':
            $stmt = db()->prepare("SELECT id FROM members WHERE business_id = ? AND created_at >= datetime('now', ?)");
            $stmt->execute([$bid, '-' . $param . ' days']);
            break;
        case 'active_days':
            $stmt = db()->prepare("SELECT DISTINCT c.member_id AS id FROM transactions t
                JOIN cards c ON t.card_id = c.id JOIN programs p ON c.program_id = p.id
                WHERE p.business_id = ? AND t.created_at >= datetime('now', ?)");
            $stmt->execute([$bid, '-' . $param . ' days']);
            break;
        case 'inactive_days':
            $stmt = db()->prepare("SELECT m.id FROM members m WHERE m.business_id = ? AND m.id NOT IN (
                SELECT DISTINCT c.member_id FROM transactions t
                JOIN cards c ON t.card_id = c.id JOIN programs p ON c.program_id = p.id
                WHERE p.business_id = ? AND t.created_at >= datetime('now', ?))");
            $stmt->execute([$bid, $bid, '-' . $param . ' days']);
            break;
        case 'min_points':
            $stmt = db()->prepare("SELECT c.member_id AS id FROM cards c
                JOIN programs p ON c.program_id = p.id
                WHERE p.business_id = ? GROUP BY c.member_id HAVING SUM(c.points_lifetime) >= CAST(? AS INTEGER)");
            $stmt->execute([$bid, $param]);
            break;
        case 'min_stamps':
            $stmt = db()->prepare("SELECT c.member_id AS id FROM transactions t
                JOIN cards c ON t.card_id = c.id JOIN programs p ON c.program_id = p.id
                WHERE p.business_id = ? AND t.kind = 'stamp_add' AND t.reversed_at IS NULL
                GROUP BY c.member_id HAVING COUNT(*) >= CAST(? AS INTEGER)");
            $stmt->execute([$bid, $param]);
            break;
        default:
            return [];
    }
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

/** Kurzbeschreibung eines Segments fuer Listen. */
function segment_label(array $segment): string
{
    $label = SEGMENT_RULES[$segment['rule']] ?? $segment['rule'];
    return str_replace('X', (string)(int)$segment['param'], $label);
}

/* ------------------------------------------------------------------ */
/* Owner: Segmente                                                     */
/* ------------------------------------------------------------------ */

function route_segment_create(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $rule = $_POST['rule'] ?? '';
    if (!$b || !isset(SEGMENT_RULES[$rule]) || trim($_POST['name'] ?? '') === '') {
        flash('Ungueltige Eingabe.', 'err');
        redirect('/dashboard');
    }
    db()->prepare('INSERT INTO segments (business_id, name, rule, param) VALUES (?,?,?,?)')
        ->execute([$b['id'], trim($_POST['name']), $rule, max(0, (int)$_POST['param'])]);
    flash('Segment angelegt.');
    redirect('/business/' . (int)$b['id']);
}

function route_segment_delete(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if ($b) {
        db()->prepare('DELETE FROM segments WHERE id = ? AND business_id = ?')
            ->execute([(int)$_POST['segment_id'], $b['id']]);
        flash('Segment geloescht.');
        redirect('/business/' . (int)$b['id']);
    }
    redirect('/dashboard');
}

/* ------------------------------------------------------------------ */
/* Owner: Kampagnen                                                    */
/* ------------------------------------------------------------------ */

function route_campaign_create(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $seg = db()->prepare('SELECT * FROM segments WHERE id = ? AND business_id = ?');
    $seg->execute([(int)$_POST['segment_id'], $b ? $b['id'] : 0]);
    $segment = $seg->fetch();
    $subject = trim($_POST['subject'] ?? '');
    $bodyText = trim($_POST['body'] ?? '');
    if (!$b || !$segment || $subject === '' || $bodyText === '') {
        flash('Segment, Betreff und Text sind erforderlich.', 'err');
        redirect($b ? '/business/' . (int)$b['id'] : '/dashboard');
    }
    db()->prepare('INSERT INTO campaigns (business_id, segment_id, name, subject, body) VALUES (?,?,?,?,?)')
        ->execute([$b['id'], $segment['id'], trim($_POST['name'] ?? '') ?: $subject, $subject, $bodyText]);
    flash('Kampagne als Entwurf angelegt.');
    redirect('/business/' . (int)$b['id']);
}

/**
 * "Sendet" eine Kampagne: ohne SMTP-Server wird lokal zugestellt -
 * jeder Empfaenger bekommt die Nachricht in seine Wallet-Inbox,
 * der Versand wird pro Mitglied protokolliert (campaign_sends).
 */
function route_campaign_send(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $c = db()->prepare('SELECT * FROM campaigns WHERE id = ? AND business_id = ?');
    $c->execute([(int)$_POST['campaign_id'], $b ? $b['id'] : 0]);
    $campaign = $c->fetch();
    if (!$b || !$campaign || $campaign['status'] === 'sent') {
        flash('Kampagne nicht gefunden oder bereits gesendet.', 'err');
        redirect($b ? '/business/' . (int)$b['id'] : '/dashboard');
    }
    $seg = db()->prepare('SELECT * FROM segments WHERE id = ?');
    $seg->execute([$campaign['segment_id']]);
    $segment = $seg->fetch();
    $memberIds = $segment ? segment_members($segment) : [];

    $send = db()->prepare('INSERT INTO campaign_sends (campaign_id, member_id) VALUES (?,?)');
    $sent = 0;
    foreach ($memberIds as $mid) {
        $send->execute([$campaign['id'], $mid]);
        member_message($mid, 'campaign', '✉️ ' . $campaign['subject'] . ' - ' . mb_strimwidth($campaign['body'], 0, 120, '…'));
        $sent++;
    }
    db()->prepare("UPDATE campaigns SET status = 'sent', sent_at = datetime('now') WHERE id = ?")
        ->execute([$campaign['id']]);
    flash('Kampagne an ' . $sent . ' Mitglieder zugestellt (lokal, in die Wallet-Inbox).');
    redirect('/business/' . (int)$b['id']);
}

function route_campaign_delete(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if ($b) {
        db()->prepare('DELETE FROM campaigns WHERE id = ? AND business_id = ?')
            ->execute([(int)$_POST['campaign_id'], $b['id']]);
        flash('Kampagne geloescht.');
        redirect('/business/' . (int)$b['id']);
    }
    redirect('/dashboard');
}

/* ------------------------------------------------------------------ */
/* HTML-Bausteine fuer die Business-Seite                              */
/* ------------------------------------------------------------------ */

function segments_html(int $businessId): string
{
    $stmt = db()->prepare('SELECT * FROM segments WHERE business_id = ? ORDER BY name');
    $stmt->execute([$businessId]);
    $list = '';
    foreach ($stmt->fetchAll() as $s) {
        $count = count(segment_members($s));
        $list .= '<li><strong>' . h($s['name']) . '</strong> <span class="muted">' . h(segment_label($s))
            . ' - aktuell ' . $count . ' Mitglieder</span>'
            . ' <form method="post" action="/segment/delete" style="display:inline">'
            . '<input type="hidden" name="business_id" value="' . $businessId . '">'
            . '<input type="hidden" name="segment_id" value="' . (int)$s['id'] . '">'
            . '<button class="btn ghost small">Loeschen</button></form></li>';
    }
    $list = $list ? '<ul>' . $list . '</ul>' : '<p class="muted">Noch keine Segmente.</p>';
    $opts = '';
    foreach (SEGMENT_RULES as $key => $label) {
        $opts .= '<option value="' . $key . '">' . h($label) . '</option>';
    }
    return $list . '
      <details class="panel">
        <summary>Neues Segment anlegen</summary>
        <form method="post" action="/segment/create" class="form inline-form">
          <input type="hidden" name="business_id" value="' . $businessId . '">
          <label>Name <input name="name" required maxlength="80" placeholder="Stammkunden"></label>
          <label>Regel <select name="rule">' . $opts . '</select></label>
          <label>Wert (X) <input type="number" name="param" value="30" min="0" max="100000"></label>
          <button class="btn">Anlegen</button>
        </form>
      </details>';
}

function campaigns_html(int $businessId): string
{
    $stmt = db()->prepare('SELECT c.*, s.name AS segment_name,
        (SELECT COUNT(*) FROM campaign_sends cs WHERE cs.campaign_id = c.id) AS sent_count
        FROM campaigns c JOIN segments s ON s.id = c.segment_id
        WHERE c.business_id = ? ORDER BY c.id DESC');
    $stmt->execute([$businessId]);
    $list = '';
    foreach ($stmt->fetchAll() as $c) {
        $status = $c['status'] === 'sent'
            ? '<span class="badge">gesendet an ' . (int)$c['sent_count'] . '</span>'
            : '<span class="badge" style="background:#eee;color:#666">Entwurf</span>';
        $list .= '<li>' . $status . ' <strong>' . h($c['name']) . '</strong> - ' . h($c['subject'])
            . ' <span class="muted">Segment: ' . h($c['segment_name']) . '</span>'
            . ' <form method="post" action="/campaign/send" style="display:inline">'
            . '<input type="hidden" name="business_id" value="' . $businessId . '">'
            . '<input type="hidden" name="campaign_id" value="' . (int)$c['id'] . '">'
            . ($c['status'] === 'sent' ? '' : '<button class="btn small">Senden</button>')
            . '</form>'
            . ' <form method="post" action="/campaign/delete" style="display:inline">'
            . '<input type="hidden" name="business_id" value="' . $businessId . '">'
            . '<input type="hidden" name="campaign_id" value="' . (int)$c['id'] . '">'
            . '<button class="btn ghost small">Loeschen</button></form></li>';
    }
    $list = $list ? '<ul>' . $list . '</ul>' : '<p class="muted">Noch keine Kampagnen.</p>';

    $segStmt = db()->prepare('SELECT * FROM segments WHERE business_id = ? ORDER BY name');
    $segStmt->execute([$businessId]);
    $segOpts = '';
    foreach ($segStmt->fetchAll() as $s) {
        $segOpts .= '<option value="' . (int)$s['id'] . '">' . h($s['name']) . ' (' . count(segment_members($s)) . ')</option>';
    }
    $form = $segOpts === ''
        ? '<p class="muted">Lege zuerst ein Segment an.</p>'
        : '<details class="panel">
        <summary>Neue Kampagne anlegen</summary>
        <form method="post" action="/campaign/create" class="form">
          <input type="hidden" name="business_id" value="' . $businessId . '">
          <label>Name <input name="name" maxlength="80" placeholder="Herbst-Aktion"></label>
          <label>Segment <select name="segment_id">' . $segOpts . '</select></label>
          <label>Betreff <input name="subject" required maxlength="120" placeholder="Doppelte Punkte am Wochenende!"></label>
          <label>Text <textarea name="body" required rows="4" maxlength="2000" placeholder="Liebe Stammkunden, ..."></textarea></label>
          <button class="btn">Entwurf speichern</button>
        </form>
      </details>';
    return $list . $form;
}
