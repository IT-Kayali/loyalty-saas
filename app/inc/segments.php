<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Etappe 5: Kunden-Segmente + E-Mail-Kampagnen                        */
/* Doku: "Segments" (dynamische Kundengruppen) + "Campaigns"           */
/* ------------------------------------------------------------------ */

function segment_rules(): array
{
    return [
        'all'           => t('Alle Mitglieder'),
        'new_days'      => t('Neu: beigetreten in den letzten X Tagen'),
        'active_days'   => t('Aktiv: Aktion in den letzten X Tagen'),
        'inactive_days' => t('Inaktiv: keine Aktion seit X Tagen'),
        'min_points'    => t('Mind. X Lifetime-Punkte'),
        'min_stamps'    => t('Mind. X Stempel gesamt'),
    ];
}

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
    $label = segment_rules()[$segment['rule']] ?? $segment['rule'];
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
    if (!$b || !isset(segment_rules()[$rule]) || trim($_POST['name'] ?? '') === '') {
        flash(t('Ungültige Eingabe.'), 'err');
        redirect('/dashboard');
    }
    db()->prepare('INSERT INTO segments (business_id, name, rule, param) VALUES (?,?,?,?)')
        ->execute([$b['id'], trim($_POST['name']), $rule, max(0, (int)$_POST['param'])]);
    flash(t('Segment angelegt.'));
    redirect('/business/' . (int)$b['id']);
}

function route_segment_delete(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if ($b) {
        db()->prepare('DELETE FROM segments WHERE id = ? AND business_id = ?')
            ->execute([(int)$_POST['segment_id'], $b['id']]);
        flash(t('Segment gelöscht.'));
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
        flash(t('Segment, Betreff und Text sind erforderlich.'), 'err');
        redirect($b ? '/business/' . (int)$b['id'] : '/dashboard');
    }
    db()->prepare('INSERT INTO campaigns (business_id, segment_id, name, subject, body) VALUES (?,?,?,?,?)')
        ->execute([$b['id'], $segment['id'], trim($_POST['name'] ?? '') ?: $subject, $subject, $bodyText]);
    flash(t('Kampagne als Entwurf angelegt.'));
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
        flash(t('Kampagne nicht gefunden oder bereits gesendet.'), 'err');
        redirect($b ? '/business/' . (int)$b['id'] : '/dashboard');
    }
    $seg = db()->prepare('SELECT * FROM segments WHERE id = ?');
    $seg->execute([$campaign['segment_id']]);
    $segment = $seg->fetch();
    $memberIds = $segment ? segment_members($segment) : [];

    $send = db()->prepare('INSERT INTO campaign_sends (campaign_id, member_id) VALUES (?,?)');
    $sent = 0;$emailQueued=0;
    foreach ($memberIds as $mid) {
        $send->execute([$campaign['id'], $mid]);
        member_message($mid, 'campaign', '✉️ ' . $campaign['subject'] . ' - ' . mb_strimwidth($campaign['body'], 0, 120, '…'));
        $mq=db()->prepare('SELECT email FROM members WHERE id=?');$mq->execute([$mid]);
        $emailQueued+=(int)queue_mail((int)$b['id'],$mid,(string)$mq->fetchColumn(),$campaign['subject'],$campaign['body'],'marketing','campaign:'.$campaign['id'].':'.$mid);
        $sent++;
    }
    db()->prepare("UPDATE campaigns SET status = 'sent', sent_at = datetime('now') WHERE id = ?")
        ->execute([$campaign['id']]);
    flash(t('Kampagne an {n} Mitglieder in die Wallet-Inbox gelegt.', ['n'=>$sent]).' '.$emailQueued.' E-Mails in der Warteschlange, noch nicht zugestellt.');
    redirect('/business/' . (int)$b['id']);
}

function route_campaign_delete(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if ($b) {
        db()->prepare('DELETE FROM campaigns WHERE id = ? AND business_id = ?')
            ->execute([(int)$_POST['campaign_id'], $b['id']]);
        flash(t('Kampagne gelöscht.'));
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
            . ' - ' . t('aktuell {n} Mitglieder', ['n' => $count]) . '</span>'
            . ' <a class="btn ghost small" href="/segment/' . (int)$s['id'] . '/edit">Bearbeiten</a>'
            . ' <form method="post" action="/segment/delete" style="display:inline">' . csrf_field()
            . '<input type="hidden" name="business_id" value="' . $businessId . '">'
            . '<input type="hidden" name="segment_id" value="' . (int)$s['id'] . '">'
            . '<button class="btn ghost small">' . t('Löschen') . '</button></form></li>';
    }
    $list = $list ? '<ul>' . $list . '</ul>' : '<p class="muted">' . t('Noch keine Segmente.') . '</p>';
    $opts = '';
    foreach (segment_rules() as $key => $label) {
        $opts .= '<option value="' . $key . '">' . h($label) . '</option>';
    }
    return $list . '
      <details class="panel">
        <summary>' . t('Neues Segment anlegen') . '</summary>
        <form method="post" action="/segment/create" class="form inline-form">' . csrf_field() . '
          <input type="hidden" name="business_id" value="' . $businessId . '">
          <label>' . t('Name') . ' <input name="name" required maxlength="80" placeholder="' . t('Stammkunden') . '"></label>
          <label>' . t('Regel') . ' <select name="rule">' . $opts . '</select></label>
          <label>' . t('Wert (X)') . ' <input type="number" name="param" value="30" min="0" max="100000"></label>
          <button class="btn">' . t('Anlegen') . '</button>
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
            ? '<span class="badge">' . t('gesendet an {n}', ['n' => (int)$c['sent_count']]) . '</span>'
            : '<span class="badge badge-draft">' . t('Entwurf') . '</span>';
        $list .= '<li>' . $status . ' <strong>' . h($c['name']) . '</strong> - ' . h($c['subject'])
            . ' <span class="muted">' . t('Segment:') . ' ' . h($c['segment_name']) . '</span>'
            . ' <form method="post" action="/campaign/send" style="display:inline">' . csrf_field()
            . '<input type="hidden" name="business_id" value="' . $businessId . '">'
            . '<input type="hidden" name="campaign_id" value="' . (int)$c['id'] . '">'
            . ($c['status'] === 'sent' ? '' : '<button class="btn small">' . t('Senden') . '</button>')
            . '</form>'
            . ' <form method="post" action="/campaign/delete" style="display:inline">' . csrf_field()
            . '<input type="hidden" name="business_id" value="' . $businessId . '">'
            . '<input type="hidden" name="campaign_id" value="' . (int)$c['id'] . '">'
            . '<button class="btn ghost small">' . t('Löschen') . '</button></form></li>';
    }
    $list = $list ? '<ul>' . $list . '</ul>' : '<p class="muted">' . t('Noch keine Kampagnen.') . '</p>';

    $segStmt = db()->prepare('SELECT * FROM segments WHERE business_id = ? ORDER BY name');
    $segStmt->execute([$businessId]);
    $segOpts = '';
    foreach ($segStmt->fetchAll() as $s) {
        $segOpts .= '<option value="' . (int)$s['id'] . '">' . h($s['name']) . ' (' . count(segment_members($s)) . ')</option>';
    }
    $form = $segOpts === ''
        ? '<p class="muted">' . t('Lege zuerst ein Segment an.') . '</p>'
        : '<details class="panel">
        <summary>' . t('Neue Kampagne anlegen') . '</summary>
        <form method="post" action="/campaign/create" class="form">' . csrf_field() . '
          <input type="hidden" name="business_id" value="' . $businessId . '">
          <label>' . t('Name') . ' <input name="name" maxlength="80" placeholder="' . t('Herbst-Aktion') . '"></label>
          <label>' . t('Segment') . ' <select name="segment_id">' . $segOpts . '</select></label>
          <label>' . t('Betreff') . ' <input name="subject" required maxlength="120" placeholder="' . t('Doppelte Punkte am Wochenende!') . '"></label>
          <label>' . t('Text') . ' <textarea name="body" required rows="4" maxlength="2000" placeholder="' . t('Liebe Stammkunden, ...') . '"></textarea></label>
          <button class="btn">' . t('Entwurf speichern') . '</button>
        </form>
      </details>';
    return $list . $form;
}

/** Formularkonflikte erkennen, kein Empfänger-Snapshot. */
function segment_revision(array $s): string
{
    return hash('sha256',json_encode([(int)$s['id'],(int)$s['business_id'],(string)$s['name'],(string)$s['rule'],(int)$s['param']],JSON_UNESCAPED_UNICODE));
}
function route_segment_edit(int $id,string $method): never
{
    $u=require_login();
    $q=db()->prepare('SELECT s.* FROM segments s JOIN businesses b ON b.id=s.business_id WHERE s.id=? AND b.user_id=?');$q->execute([$id,$u['id']]);$s=$q->fetch();if(!$s)not_found();
    header('Cache-Control: private, no-store');
    $saved=$s;$revision=segment_revision($s);$preview=null;
    if($method==='POST') {
        $action=(string)($_POST['action'] ?? 'save');
        if(!in_array($action,['save','preview'],true)){http_response_code(422);exit('Ungültige Aktion.');}
        $name=trim((string)($_POST['name'] ?? ''));$rule=(string)($_POST['rule'] ?? '');$param=(string)($_POST['param'] ?? '');
        if($name==='' || mb_strlen($name)>80 || !isset(segment_rules()[$rule]) || !preg_match('/^[0-9]{1,6}$/D',$param) || (int)$param>100000) {
            flash('Name mit 1 bis 80 Zeichen, gültige Regel und ganzen Wert von 0 bis 100000 angeben.','err');redirect('/segment/'.$id.'/edit');
        }
        if($action==='preview') {
            $revision=(string)($_POST['revision'] ?? '');
            if(!hash_equals(segment_revision($saved),$revision)){flash('Segment wurde inzwischen geändert. Aktuellen Stand prüfen und erneut bearbeiten.','err');redirect('/segment/'.$id.'/edit');}
            $s=array_merge($s,['name'=>$name,'rule'=>$rule,'param'=>(int)$param]);
            $preview=count(segment_members($s));
        } else {
        db()->exec('BEGIN IMMEDIATE');
        try {
            $q=db()->prepare('SELECT * FROM segments WHERE id=? AND business_id=?');$q->execute([$id,$s['business_id']]);$fresh=$q->fetch();
            if(!$fresh || !hash_equals(segment_revision($fresh),(string)($_POST['revision'] ?? '')))throw new InvalidArgumentException('Segment wurde inzwischen geändert. Aktuellen Stand prüfen und erneut bearbeiten.');
            db()->prepare('UPDATE segments SET name=?,rule=?,param=? WHERE id=? AND business_id=?')->execute([$name,$rule,(int)$param,$id,$s['business_id']]);
            audit_log((int)$u['id'],'segment.update',['segment_id'=>$id,'business_id'=>(int)$s['business_id']]);db()->exec('COMMIT');
        } catch(Throwable $e) {
            db()->exec('ROLLBACK');if($e instanceof InvalidArgumentException){flash($e->getMessage(),'err');redirect('/segment/'.$id.'/edit');}throw $e;
        }
        flash('Segment gespeichert. Es wurden keine Nachrichten versendet.');redirect('/segment/'.$id.'/edit');
        }
    }
    $opts='';foreach(segment_rules() as $key=>$label)$opts.='<option value="'.h($key).'"'.($s['rule']===$key?' selected':'').'>'.h($label).'</option>';
    $q=db()->prepare("SELECT COUNT(*) FROM campaigns WHERE segment_id=? AND status!='sent'");$q->execute([$id]);$drafts=(int)$q->fetchColumn();
    page('Segment bearbeiten','<p><a href="/business/'.(int)$s['business_id'].'">← Unternehmen</a></p><h1>Segment bearbeiten</h1><section class="panel"><p><strong>'.count(segment_members($saved)).'</strong> Mitglieder passen zur aktuell gespeicherten Regel. Die Zielgruppe ist dynamisch, kein fester Empfängerbestand.</p><p class="muted">'.$drafts.' noch nicht gesendete Kampagnen sind verknüpft. Sie nutzen beim späteren Senden die dann gültige Regel. Bereits versendete Nachrichten und Versandprotokolle bleiben unverändert. Speichern sendet keine Nachrichten.</p>'.($preview!==null?'<div class="notice segment-preview"><h2>Ungespeicherte Vorschau</h2><p><strong>'.$preview.'</strong> Mitglieder bei der letzten Prüfung für '.h(segment_label($s)).'. Nach weiteren Eingaben erneut prüfen. Segment und Kampagnen bleiben unverändert. Es wurden keine Nachrichten versendet. Erst „Segment speichern“ übernimmt diese Regel.</p></div>':'').'<form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.h($revision).'"><label>Name<input name="name" maxlength="80" value="'.h($s['name']).'" required></label><label>Regel<select name="rule">'.$opts.'</select></label><label>Wert (X)<input type="number" name="param" min="0" max="100000" step="1" value="'.(int)$s['param'].'" required></label><p class="muted">Bei „Alle Mitglieder“ wird der Wert ignoriert. Tagesregeln verwenden UTC. Prüfe die neue Anzahl ohne Speichern über „Vorschau prüfen“. Die Zahl kann sich bis zu einem späteren Versand ändern.</p><div class="btn-row"><button class="btn ghost" name="action" value="preview">Vorschau prüfen</button><button class="btn" name="action" value="save">Segment speichern</button></div></form></section>',$u);exit;
}
