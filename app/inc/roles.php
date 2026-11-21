<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Staff-Rolle (Theke) und Member-Rolle (Kunden-Wallet)                */
/* ------------------------------------------------------------------ */

function current_staff(): ?array
{
    if (empty($_SESSION['staff_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT s.*, b.name AS business_name, b.brand_color FROM staff s
        JOIN businesses b ON b.id = s.business_id WHERE s.id = ? AND s.active = 1');
    $stmt->execute([$_SESSION['staff_id']]);
    $s=$stmt->fetch();
    if($s && (int)$s['session_version']!==(int)($_SESSION['staff_session_version'] ?? 0)){unset($_SESSION['staff_id']);return null;}
    if($s && $s['location_id']){$q=db()->prepare('SELECT active FROM locations WHERE id=? AND business_id=?');$q->execute([$s['location_id'],$s['business_id']]);if(!$q->fetchColumn())return null;}
    return $s ?: null;
}

function require_staff(): array
{
    $s = current_staff();
    if (!$s) {
        redirect('/staff/login');
    }
    return $s;
}

function current_member_email(): ?string
{
    $email=$_SESSION['member_email'] ?? null;if(!$email)return null;
    $q=db()->prepare('SELECT MAX(session_version) FROM members WHERE lower(email)=lower(?)');$q->execute([$email]);$version=$q->fetchColumn();
    if($version===null || (int)$version!==(int)($_SESSION['member_session_version'] ?? 0)){unset($_SESSION['member_email']);return null;}
    return $email;
}

function require_member(): string
{
    $e = current_member_email();
    if (!$e) {
        redirect('/member/login');
    }
    return $e;
}

/** Alle Mitglieds-Zeilen (ueber Businesses hinweg) zu einer E-Mail. */
function member_rows_by_email(string $email): array
{
    $stmt = db()->prepare('SELECT m.*, b.name AS business_name, b.brand_color, b.join_code, b.card_design,
        b.referral_points_referrer, b.referral_points_referee FROM members m
        JOIN businesses b ON b.id = m.business_id WHERE lower(m.email) = lower(?) ORDER BY m.created_at');
    $stmt->execute([$email]);
    return $stmt->fetchAll();
}

/* ------------------------------------------------------------------ */
/* Owner: Staff-Zugang anlegen                                         */
/* ------------------------------------------------------------------ */

function route_staff_create(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $name = trim($_POST['name'] ?? '');
    $pin = (string)($_POST['pin'] ?? '');
    if (!$b || $name === '' || !preg_match('/^\d{6,12}$/D',$pin)) {
        flash(t('Name und PIN (6 bis 12 Ziffern) erforderlich.'), 'err');
        redirect('/dashboard');
    }
    db()->prepare("INSERT INTO staff (business_id, name, pin_hash,role) VALUES (?,?,?,'cashier')")
        ->execute([$b['id'], $name, password_hash($pin, PASSWORD_DEFAULT)]);
    flash(t('Staff-Zugang für {name} angelegt.', ['name' => $name]));
    redirect('/business/' . (int)$b['id']);
}

/* ------------------------------------------------------------------ */
/* Staff-Portal                                                        */
/* ------------------------------------------------------------------ */

function route_staff_login(string $method): never
{
    if ($method === 'GET') {
        $b = (int)($_GET['b'] ?? 0);
        page(t('Staff-Login'), '
        <div class="auth-box">
          <h1>' . t('Staff-Portal') . '</h1>' . demo_login_hint() . '
          <p class="muted">' . t('Theken-Zugang: Kunden finden, Stempel und Punkte vergeben, einlösen.') . '</p>
          <form method="post" action="/staff/login" class="form">' . csrf_field() . '
            <label>' . t('Business-Nr.') . ' <input name="business_id" required inputmode="numeric" value="' . ($b ?: '') . '"></label>
            <label>' . t('Dein Name') . ' <input name="name" required></label>
            <label>PIN <input type="password" name="pin" required></label>
            <button class="btn">' . t('Anmelden') . '</button>
          </form>
          <p class="muted"><a href="/login">' . t('Betreiber-Login') . '</a> · <a href="/member/login">' . t('Kunden-Login') . '</a></p>
          ' . lang_switcher() . '
        </div>');
        exit;
    }
    $stmt = db()->prepare('SELECT * FROM staff WHERE business_id = ? AND name = ? AND active = 1');
    $stmt->execute([(int)($_POST['business_id'] ?? 0), trim($_POST['name'] ?? '')]);
    $s = $stmt->fetch();
    if (!$s || !password_verify((string)($_POST['pin'] ?? ''), $s['pin_hash'])) {
        flash(t('Zugangsdaten stimmen nicht.'), 'err');
        redirect('/staff/login');
    }
    session_regenerate_id(true);
    $_SESSION['staff_id'] = (int)$s['id'];
    $_SESSION['staff_session_version']=(int)$s['session_version'];
    redirect('/staff');
}

function route_staff_logout(): never
{
    unset($_SESSION['staff_id']);
    redirect('/staff/login');
}

function route_staff_counter(): never
{
    $s = require_staff();
    $q = trim($_GET['q'] ?? '');
    $body = '<h1>' . t('Theke') . ' · ' . h($s['business_name']) . '</h1>
      <p class="muted">' . t('Angemeldet als') . ' ' . h($s['name']) . ' · <a href="/staff/logout">' . t('Abmelden') . '</a> · ' . lang_switcher() . '</p>
      <form method="get" action="/staff" class="form inline-form">
        <label>' . t('Kunde finden') . ' <input name="q" value="' . h($q) . '" placeholder="' . t('Name, E-Mail oder Code') . '" autofocus></label>
        <button class="btn">' . t('Suchen') . '</button>
        <a class="btn accent" href="/staff/scan">' . t('QR scannen') . '</a>
      </form>';

    $body.='<p><a href="/staff/business/'.(int)$s['business_id'].'/voucher-counter">Code-Gutschein prüfen</a> · <a href="/staff/business/'.(int)$s['business_id'].'/claim-counter">Prämien-Code einlösen</a></p><p class="muted">Erlaubte Aktionen: '.h(implode(', ',staff_permissions($s)) ?: 'Nur lesen').'</p>';
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = db()->prepare('SELECT * FROM members WHERE business_id = ? AND (name LIKE ? OR email LIKE ? OR code LIKE ?) ORDER BY name LIMIT 20');
        $stmt->execute([$s['business_id'], $like, $like, strtoupper($like)]);
        $rows = '';
        foreach ($stmt->fetchAll() as $m) {
            $rows .= '<tr><td><a href="/staff/member/' . (int)$m['id'] . '">' . h($m['name']) . '</a></td>
              <td class="muted">' . h($m['email']) . '</td><td><code>' . h($m['code']) . '</code></td></tr>';
        }
        $body .= $rows
            ? '<table class="table"><thead><tr><th>' . t('Name') . '</th><th>' . t('E-Mail') . '</th><th>Code</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            : '<p class="muted">' . t('Niemand gefunden.') . '</p>';
    } else {
        $recent = db()->prepare('SELECT m.*, MAX(t.id) AS last_txn FROM members m
            JOIN cards c ON c.member_id = m.id JOIN transactions t ON t.card_id = c.id
            WHERE m.business_id = ? GROUP BY m.id ORDER BY last_txn DESC LIMIT 5');
        $recent->execute([$s['business_id']]);
        $rows = '';
        foreach ($recent->fetchAll() as $m) {
            $rows .= '<tr><td><a href="/staff/member/' . (int)$m['id'] . '">' . h($m['name']) . '</a></td>
              <td><code>' . h($m['code']) . '</code></td></tr>';
        }
        if ($rows) {
            $body .= '<h2>' . t('Zuletzt an der Theke') . '</h2>
              <table class="table"><tbody>' . $rows . '</tbody></table>';
        }
    }
    page(t('Theke'), $body, null, $s['brand_color']);
    exit;
}

function route_staff_member(int $id): never
{
    $s = require_staff();
    $stmt = db()->prepare('SELECT * FROM members WHERE id = ? AND business_id = ?');
    $stmt->execute([$id, $s['business_id']]);
    $m = $stmt->fetch();
    if (!$m) {
        not_found();
    }
    $programs = db()->prepare('SELECT * FROM programs WHERE business_id = ? AND active = 1');
    $programs->execute([$s['business_id']]);

    $body = '<p><a href="/staff" class="muted">← ' . t('Theke') . '</a></p>
      <h1>' . h($m['name']) . '</h1>
      <p class="muted">' . h($m['email']) . ' · <code>' . h($m['code']) . '</code></p>'
      . member_panels($id, $programs->fetchAll(), '/staff/card');

    page($m['name'] . ' · ' . t('Theke'), $body, null, $s['brand_color']);
    exit;
}

function route_staff_card_action(int $cardId): never
{
    $s = require_staff();
    $stmt = db()->prepare('SELECT c.*, p.type AS program_type, p.stamps_required, p.name AS program_name
        FROM cards c JOIN programs p ON p.id = c.program_id
        WHERE c.id = ? AND p.business_id = ?');
    $stmt->execute([$cardId, $s['business_id']]);
    $card = $stmt->fetch();
    if (!$card) {
        not_found();
    }
    $action=(string)($_POST['action'] ?? '');
    require_staff_permission(in_array($action,['stamp','points_add'],true)?'award':'redeem',$s);
    do_card_action($card,$action);
    redirect('/staff/member/' . (int)$card['member_id']);
}

/* ------------------------------------------------------------------ */
/* Member-Login & Shared Wallet                                        */
/* ------------------------------------------------------------------ */

function route_member_login(string $method): never
{
    if ($method === 'GET') {
        page(t('Kunden-Login'), '
        <div class="auth-box">
          <h1>' . t('Meine Karten') . '</h1>' . demo_login_hint() . '
          <p class="muted">' . t('Dein Wallet: alle Treuekarten, Punkte und Belohnungen auf einen Blick.') . '</p>
          <form method="post" action="/member/login" class="form">' . csrf_field() . '
            <label>' . t('E-Mail') . ' <input type="email" name="email" required></label>
            <label>' . t('Passwort') . ' <input type="password" name="password" required></label>
            <button class="btn">' . t('Anmelden') . '</button>
          </form>
          <p class="muted"><a href="/login">' . t('Betreiber-Login') . '</a> · <a href="/staff/login">' . t('Staff-Login') . '</a></p>
          ' . lang_switcher() . '
        </div>');
        exit;
    }
    $email = strtolower(trim($_POST['email'] ?? ''));
    $stmt = db()->prepare('SELECT * FROM members WHERE lower(email) = ? AND password_hash IS NOT NULL');
    $stmt->execute([$email]);
    $ok = false;
    foreach ($stmt->fetchAll() as $m) {
        if (password_verify((string)($_POST['password'] ?? ''), $m['password_hash'])) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        flash(t('E-Mail oder Passwort falsch.'), 'err');
        redirect('/member/login');
    }
    session_regenerate_id(true);
    $_SESSION['member_email'] = $email;
    $versionQ=db()->prepare('SELECT MAX(session_version) FROM members WHERE lower(email)=lower(?)');$versionQ->execute([$email]);$_SESSION['member_session_version']=(int)$versionQ->fetchColumn();
    redirect('/wallet');
}

function route_member_logout(): never
{
    unset($_SESSION['member_email']);
    redirect('/member/login');
}

/** Read-only Kartenansicht eines Mitglieds (Wallet & oeffentliche Karte). */
function wallet_cards_html(int $memberId): string
{
    $stmt = db()->prepare('SELECT c.*, p.type, p.name AS program_name, p.description, p.stamps_required,
        p.reward_text, p.voucher_value, p.pass_visits,p.card_design
        FROM cards c JOIN programs p ON p.id = c.program_id
        WHERE c.member_id = ? AND p.active = 1 ORDER BY c.created_at');
    $stmt->execute([$memberId]);
    $designQ = db()->prepare('SELECT b.* FROM businesses b JOIN members m ON m.business_id=b.id WHERE m.id=?');
    $designQ->execute([$memberId]);
    $business=$designQ->fetch() ?: [];
    $design=business_design($business);
    $body = '';
    foreach ($stmt->fetchAll() as $c) {
        $design=program_design($c,$business);
        $body .= '<section class="loyal-card designed-card" style="'.h(card_face_style($design)).'">';
        if ($c['type'] === 'stamps') {
            $req = (int)$c['stamps_required'];
            $dots = '';
            for ($i = 1; $i <= $req; $i++) {
                $dots .= stamp_html($design, $i <= $c['stamps']);
            }
            $body .= '<h2>' . h($c['program_name']) . '</h2>
              <div class="stamp-row">' . $dots . '</div>
              <p>' . (int)$c['stamps'] . ' / ' . $req . ' ' . t('Stempel') . '</p>'
              . ($c['pending_rewards'] > 0
                  ? '<p class="reward-pending">★ ' . (int)$c['pending_rewards'] . 'x ' . h($c['reward_text']) . ' – ' . t('zum Einlösen bereit!') . '</p>'
                  : '<p class="muted">' . h($c['reward_text']) . '</p>');
        } elseif ($c['type'] === 'points') {
            $body .= '<h2>' . h($c['program_name']) . '</h2>
              <p class="big-number">' . (int)$c['points'] . ' <span>' . t('Punkte') . '</span></p>'
              . tier_html((int)$c['program_id'], (int)($c['points_lifetime'] ?? 0)) . points_expiry_notice_html((int)$c['id']);
            $rewards = db()->prepare('SELECT * FROM rewards WHERE program_id = ? ORDER BY points_cost');
            $rewards->execute([$c['program_id']]);
            foreach ($rewards->fetchAll() as $r) {
                $okk = $c['points'] >= $r['points_cost'];
                $body .= '<p class="' . ($okk ? 'reward-pending' : 'muted') . '">' . ($okk ? '✓ ' : '') . h($r['name']) . ' · ' . (int)$r['points_cost'] . ' ' . t('Punkte') . '</p>';
            }
        } elseif ($c['type'] === 'voucher') {
            $body .= '<h2>' . h($c['program_name']) . '</h2>
              <p class="big-number">' . h($c['voucher_value']) . '</p>
              <p class="muted">' . (int)($c['uses_left'] ?? 0) . ' ' . t('Nutzungen übrig') . '</p>';
        } else {
            $body .= '<h2>' . h($c['program_name']) . '</h2>
              <p class="big-number">' . (int)($c['visits_left'] ?? 0) . ' <span>' . t('Besuche übrig') . '</span></p>';
        }
        $body .= '</section>';
    }
    $body.=prepaid_member_html($memberId,'',true);
    return $body;
}

function route_wallet(): never
{
    $email = require_member();
    $rows = member_rows_by_email($email);
    if (!$rows) {
        unset($_SESSION['member_email']);
        flash(t('Kein Kundenkonto gefunden.'), 'err');
        redirect('/member/login');
    }
    $firstName = explode(' ', trim($rows[0]['name']))[0];
    $brand = $rows[0]['brand_color'] ?: '#E4572E';

    $body = '<div class="member-view">
      <p class="brand-tag">' . t('Mein Wallet') . '</p>
      <h1>' . t('Hallo, {name}!', ['name' => $firstName]) . '</h1>
      <p class="muted">' . h($email) . ' · <a href="/member/logout">' . t('Abmelden') . '</a> · ' . lang_switcher() . '</p><p><a href="/member/preferences">E-Mail-Einwilligung & Geburtstag</a> · <a href="/wallet/request">Punkte anfordern</a></p>';

    $body.='<section class="panel"><h2>Karten offline vorzeigen</h2><p>Nur auf deinem eigenen Gerät speichern. Kundencodes und letzte Salden bleiben hier bis zu 24 Stunden lesbar, auch ohne Anmeldung. Keine Buchungen oder Einlösungen offline.</p><label><input id="offline-consent" type="checkbox"> Dies ist mein privates Gerät, ich möchte eine lokale Kopie.</label><p><button type="button" class="btn" id="offline-save">Karten auf diesem Gerät speichern</button> <button type="button" class="btn ghost" id="offline-clear">Lokale Kopie löschen</button></p><p id="offline-status" role="status"></p><p><a href="/offline.html">Gespeicherte Karten öffnen / Installationshilfe</a></p></section>';

    /* Inbox: Meilensteine, Referral-Boni etc. (letzte 5 je Konto) */
    $msgQ = db()->prepare('SELECT mm.text, mm.created_at FROM member_messages mm
        WHERE mm.member_id IN (' . implode(',', array_map('intval', array_column($rows, 'id'))) . ')
        ORDER BY mm.id DESC LIMIT 5');
    $msgQ->execute();
    $msgs = $msgQ->fetchAll();
    if ($msgs) {
        $body .= '<div class="panel" style="text-align:left;margin-bottom:16px">';
        foreach ($msgs as $msg) {
            $body .= '<p style="margin:4px 0">' . h($msg['text']) . ' <span class="muted" style="font-size:12px">'
                . h(substr($msg['created_at'], 0, 16)) . '</span></p>';
        }
        $body .= '</div>';
    }

    $body .= member_vouchers_html(array_map('intval', array_column($rows, 'id')));
    $claimsHtml = [];
    foreach ($rows as $m0) $claimsHtml[(int)$m0['id']] = member_claims_html((int)$m0['id']);

    foreach ($rows as $m) {
        $body .= '<h2 class="wallet-biz">' . h($m['business_name']) . '</h2>';
        $cards = wallet_cards_html((int)$m['id']);
        $body .= $cards ?: '<p class="muted">' . t('Noch keine Karten bei diesem Geschäft.') . '</p>';
        $body .= $claimsHtml[(int)$m['id']] ?? '';
        $body .= '<p><a href="/c/' . h($m['code']) . '" target="_blank">' . t('Karte zum Vorzeigen (QR)') . '</a></p>';
        $body .= wallet_buttons($m['code']);

        /* Achievements: freigeschaltet + naechster Fortschritt */
        $achQ = db()->prepare('SELECT a.*, ma.unlocked_at FROM achievements a
            LEFT JOIN member_achievements ma ON ma.achievement_id = a.id AND ma.member_id = ?
            WHERE a.business_id = ? AND a.active = 1 ORDER BY a.threshold');
        $achQ->execute([(int)$m['id'], (int)$m['business_id']]);
        $achs = $achQ->fetchAll();
        if ($achs) {
            $metrics = member_metrics((int)$m['id'], (int)$m['business_id']);
            $body .= '<div class="panel" style="text-align:left"><strong>' . t('Meilensteine') . '</strong><ul style="margin:6px 0;padding-left:20px">';
            foreach ($achs as $a) {
                $value = $metrics[$a['metric']] ?? 0;
                if ($a['unlocked_at']) {
                    $body .= '<li>🏆 ' . h($a['name']) . ' <span class="muted">' . t('erreicht') . '</span></li>';
                } else {
                    $body .= '<li class="muted">' . h($a['name']) . ' – ' . min($value, (int)$a['threshold']) . '/' . (int)$a['threshold']
                        . ' ' . h(metric_label($a['metric'])) . '</li>';
                }
            }
            $body .= '</ul></div>';
        }

        /* Referral-Link */
        if ((int)$m['referral_points_referrer'] > 0 || (int)$m['referral_points_referee'] > 0) {
            $refLink = '/join/' . h($m['join_code']) . '?ref=' . h($m['code']);
            $body .= '<div class="panel" style="text-align:left">🤝 <strong>' . t('Freunde werben:') . '</strong> '
                . t('{a} Punkte für dich, {b} für deine Freunde.', ['a' => (int)$m['referral_points_referrer'], 'b' => (int)$m['referral_points_referee']])
                . ' ' . t('Dein Link:') . ' <code>' . $refLink . '</code></div>';
        }
    }

    $body .= '<details class="panel" style="text-align:left">
        <summary>' . t('Konto &amp; Datenschutz') . '</summary>
        <p class="muted">' . t('Deine Daten liegen beim jeweiligen Geschäft. Du kannst sie exportieren oder komplett löschen.') . '</p>
        <div class="btn-row">
          <a class="btn ghost small" href="/member/export">' . t('Daten exportieren (JSON)') . '</a>
          <form method="post" action="/member/delete" onsubmit="return confirm(\'' . t('Wirklich alle Kundenkonten zu dieser E-Mail löschen?') . '\')">' . csrf_field() . '
            <button class="btn ghost small" style="color:#b3261e;border-color:#b3261e">' . t('Konto löschen') . '</button>
          </form>
        </div>
      </details></div>';

    page(t('Mein Wallet'), $body, null, $brand);
    exit;
}

function route_member_export(): never
{
    $email = require_member();
    $rows = member_rows_by_email($email);
    $out = ['email' => $email, 'exportiert_am' => date('c'), 'konten' => []];
    foreach ($rows as $m) {
        $cards = db()->prepare('SELECT c.*, p.name AS program_name, p.type FROM cards c JOIN programs p ON p.id = c.program_id WHERE c.member_id = ?');
        $cards->execute([$m['id']]);
        $cardList = [];
        foreach ($cards->fetchAll() as $c) {
            $tx = db()->prepare('SELECT kind, amount, note, created_at FROM transactions WHERE card_id = ? ORDER BY id');
            $tx->execute([$c['id']]);
            $c['verlauf'] = $tx->fetchAll();
            $cardList[] = $c;
        }
        $out['konten'][] = [
            'geschaeft' => $m['business_name'],
            'name' => $m['name'],
            'code' => $m['code'],
            'angemeldet_seit' => $m['created_at'],
            'karten' => $cardList,
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="meine-treuedaten.json"');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function route_member_delete(): never
{
    $email = require_member();
    $cleanup=db()->prepare('DELETE FROM mail_outbox WHERE member_id IN (SELECT id FROM members WHERE lower(email)=lower(?))');$cleanup->execute([$email]);
    db()->prepare("DELETE FROM password_resets WHERE email=? AND kind='member'")->execute([$email]);
    $GLOBALS['privacy_delete_authorized']=true;
    $stmt = db()->prepare('DELETE FROM members WHERE lower(email) = lower(?)');
    $stmt->execute([$email]);
    unset($_SESSION['member_email']);
    flash(t('Deine Kundenkonten wurden gelöscht.'));
    redirect('/member/login');
}

/* ------------------------------------------------------------------ */
/* Oeffentlicher Beitritt (Join-Link / QR)                             */
/* ------------------------------------------------------------------ */

function route_join(string $joinCode, string $method): never
{
    $stmt = db()->prepare('SELECT * FROM businesses WHERE join_code = ?');
    $stmt->execute([$joinCode]);
    $b = $stmt->fetch();
    if (!$b) {
        not_found();
    }
    $ref = strtoupper(preg_replace('/[^A-F0-9]/', '', (string)($_GET['ref'] ?? $_POST['ref'] ?? '')));
    if ($method === 'GET') {
        page(t('Beitreten:') . ' ' . $b['name'], '
        <div class="auth-box">
          <h1>' . h($b['name']) . '</h1>
          <p class="muted">' . t('Werde Mitglied und sammle Stempel, Punkte und Belohnungen - digital auf dem Handy.') . '</p>'
          . ($ref !== '' ? '<p class="notice">' . t('Du wurdest von einem Freund eingeladen - Willkommensbonus inklusive!') . ' 🤝</p>' : '') . '
          <form method="post" class="form">' . csrf_field() . '
            <input type="hidden" name="ref" value="' . h($ref) . '">
            <label>' . t('Name') . ' <input name="name" required maxlength="80"></label>
            <label>' . t('E-Mail (für dein Wallet)') . ' <input type="email" name="email" maxlength="190"></label>
            <label>' . t('Passwort (für dein Wallet, min. 8 Zeichen)') . ' <input type="password" name="password" required minlength="8"></label>
            <label class="check"><input type="checkbox" name="marketing_consent" value="1"> Ich möchte Marketing-E-Mails dieses Unternehmens erhalten. Jederzeit abbestellbar, freiwillig.</label>
            <button class="btn">' . t('Jetzt beitreten') . '</button>
          </form>
          ' . lang_switcher() . '
        </div>', null, $b['brand_color']);
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if ($name === '' || strlen($pw) < 8) {
        flash(t('Name und Passwort (min. 8 Zeichen) erforderlich.'), 'err');
        redirect('/join/' . $joinCode);
    }
    db()->prepare('INSERT INTO members (business_id, name, email, code, password_hash) VALUES (?,?,?,?,?)')
        ->execute([$b['id'], $name, $email, member_code(), password_hash($pw, PASSWORD_DEFAULT)]);
    $memberId = (int)db()->lastInsertId();
    if(!empty($_POST['marketing_consent']))db()->prepare("UPDATE members SET marketing_consent=1,consent_source='join-v1',consent_at=datetime('now') WHERE id=?")->execute([$memberId]);

    /* Alle aktiven Programme direkt in die Wallet legen */
    $programs = db()->prepare('SELECT * FROM programs WHERE business_id = ? AND active = 1');
    $programs->execute([$b['id']]);
    foreach ($programs->fetchAll() as $p) {
        $uses = $p['type'] === 'voucher' ? (int)$p['uses_per_customer'] : null;
        $visits = $p['type'] === 'pass' ? (int)$p['pass_visits'] : null;
        db()->prepare('INSERT OR IGNORE INTO cards (program_id, member_id, uses_left, visits_left) VALUES (?,?,?,?)')
            ->execute([$p['id'], $memberId, $uses, $visits]);
    }

    /* Referral-Boni gutschreiben, falls ueber Freunde-werben-Link beigetreten */
    credit_referral($b, $memberId, $ref);
    fire_webhook((int)$b['id'], 'member.joined', ['member_id' => $memberId, 'name' => $name]);

    $code = db()->query("SELECT code FROM members WHERE id = $memberId")->fetchColumn();
    if ($email !== '') {
        session_regenerate_id(true);
    $_SESSION['member_email'] = $email;
    $versionQ=db()->prepare('SELECT MAX(session_version) FROM members WHERE lower(email)=lower(?)');$versionQ->execute([$email]);$_SESSION['member_session_version']=(int)$versionQ->fetchColumn();
        flash(t('Willkommen bei {business}! Deine Karten sind bereit.', ['business' => $b['name']]));
        redirect('/wallet');
    }
    redirect('/c/' . $code);
}
