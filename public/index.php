<?php
declare(strict_types=1);

/* ==================================================================
 * Loyalty SaaS - Front Controller
 * Document Root ist public/. App-Code liegt in app/, Daten in data/.
 * Start:  php -S 127.0.0.1:8099 -t public public/index.php
 * ================================================================== */

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_once ROOT_DIR . '/app/inc/roles.php';
require_once ROOT_DIR . '/app/inc/counter.php';
require_once ROOT_DIR . '/app/inc/tiers.php';
require_once ROOT_DIR . '/app/inc/reward-editor.php';
require_once ROOT_DIR . '/app/inc/engage.php';
require_once ROOT_DIR . '/app/inc/segments.php';
require_once ROOT_DIR . '/app/inc/business-settings.php';
require_once ROOT_DIR . '/app/inc/api.php';
require_once ROOT_DIR . '/app/inc/api-credit.php';
require_once ROOT_DIR . '/app/inc/merchant.php';
require_once ROOT_DIR . '/app/inc/analytics.php';
require_once ROOT_DIR . '/app/inc/monthly-summary.php';
require_once ROOT_DIR . '/app/inc/wallet.php';
require_once ROOT_DIR . '/app/inc/wallet-apns.php';
require_once ROOT_DIR . '/app/inc/wallet-nearby.php';
require_once ROOT_DIR . '/app/inc/wallet-nearby-privacy.php';
require_once ROOT_DIR . '/app/inc/wallet-push.php';
require_once ROOT_DIR . '/app/inc/wallet-push-delivery.php';
require_once ROOT_DIR . '/app/inc/wallet-push-ui.php';
require_once ROOT_DIR . '/app/inc/wallet-versions.php';
require_once ROOT_DIR . '/app/inc/images.php';
require_once ROOT_DIR . '/app/inc/billing.php';
require_once ROOT_DIR . '/app/inc/paypal.php';
require_once ROOT_DIR . '/app/inc/admin.php';
require_once ROOT_DIR . '/app/inc/admin-health.php';
require_once ROOT_DIR . '/app/inc/designer.php';
require_once ROOT_DIR . '/app/inc/cardlayout.php';
require_once ROOT_DIR . '/app/inc/vouchers.php';
require_once ROOT_DIR . '/app/inc/prepaid.php';
require_once ROOT_DIR . '/app/inc/mail.php';
require_once ROOT_DIR . '/app/inc/recovery.php';
require_once ROOT_DIR . '/app/inc/organization.php';
require_once ROOT_DIR . '/app/inc/qr.php';
require_once ROOT_DIR . '/app/inc/pwa.php';
require_once ROOT_DIR . '/app/inc/support.php';
require_once ROOT_DIR . '/app/inc/support-list.php';
require_once ROOT_DIR . '/app/inc/ai.php';
require_once ROOT_DIR . '/app/inc/templates.php';
require_once ROOT_DIR . '/app/inc/expiry.php';
require_once ROOT_DIR . '/app/inc/transfers.php';
require_once ROOT_DIR . '/app/inc/timezone.php';
require_once ROOT_DIR . '/app/inc/claims.php';

/* ------------------------------------------------------------------ */
/* Router                                                              */
/* ------------------------------------------------------------------ */

if (php_sapi_name() !== 'cli') {
/* Statische Dateien (z.B. /assets/style.css) direkt vom eingebauten Server ausliefern */
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}
$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

/* Kanäle ohne Session-CSRF (eigene Auth): REST API, Pass-Web-Service, Shop-Webhooks */
if (str_starts_with($path, '/api/v1/')) {
    route_api($path, $method);
}
if ($path === '/mcp') {
    route_mcp($method);
}
if (str_starts_with($path, '/api/pass/')) {
    route_pass_service($path, $method);
}
if (preg_match('#^/integrations/shopify/webhook/(\d+)$#', $path, $m) === 1) {
    route_shopify_webhook((int)$m[1]);
}

if ($path === '/paypal/webhook') route_paypal_webhook($method);
if ($path === '/stripe/webhook') route_stripe_webhook($method);

/* Alle übrigen POST-Requests: CSRF-Token Pflicht */
csrf_check();
auth_rate_guard($path,$method);
policy_browser_guard($path,$method);

try {
    match (true) {
        $path === '/' => route_home(),
        $path === '/member/offline-snapshot' && $method==='GET' => route_offline_snapshot(),
        $path === '/billing/paypal' => route_paypal($method),
        $path === '/register' => route_register($method),
        $path === '/login' => route_login($method),
        $path === '/logout' => route_logout(),
        $path === '/account/password' => route_owner_password($method),
        $path === '/dashboard' => route_dashboard(),
        preg_match('#^/location/(\d+)/edit$#D',$path,$lm)===1 => route_location_edit((int)$lm[1],$method),
        preg_match('#^/network/(\d+)/delete$#D',$path,$nd)===1 => route_network_delete((int)$nd[1],$method),
        preg_match('#^/network/(\d+)/edit$#D',$path,$nm)===1 => route_network_edit((int)$nm[1],$method),
        preg_match('#^/support/([A-F0-9]{16})/export$#D',$path,$m)===1 && $method==='GET' => route_support_export($m[1]),
        $path === '/admin/support/export' => route_support_list_export($method),
        $path === '/contact' => route_support($method,false,true),
        ($path==='/support' || preg_match('#^/support/([A-F0-9]{16})$#D',$path)) => route_support($method,false),
        ($path==='/admin/support' || preg_match('#^/admin/support/([A-F0-9]{16})$#D',$path)) => route_support($method,true),
        $path === '/ai' => route_ai($method),
        (bool)preg_match('#^/ai/key/(\d+)$#D',$path,$aim) => route_ai_key((int)$aim[1],$method),
        (bool)preg_match('#^/ai/approval/([a-f0-9]{24})$#D',$path,$aim) => route_ai_approval($aim[1],$method),
        $path === '/billing' => route_billing($method),
        $path === '/admin/health/json' => route_admin_health_json($method),
        $path === '/admin/health' => route_admin_health($method),
        $path === '/admin/audit' => route_admin_audit($method),
        $path === '/admin' => route_admin($method),
        $path === '/admin/export' => route_admin_export(),
        $path === '/business/create' && $method === 'POST' => route_business_create(),
        preg_match('#^/business/(\d+)/settings$#',$path,$m)===1 && in_array($method,['GET','POST'],true) => route_business_settings((int)$m[1],$method),
        preg_match('#^/business/(\d+)$#', $path, $m) === 1 => route_business((int)$m[1]),
        $path === '/program/create' && $method === 'POST' => route_program_create(),
        $path === '/program/template' && $method === 'POST' => route_program_template(),
        preg_match('#^/program/(\d+)$#', $path, $m) === 1 => route_program((int)$m[1]),
        preg_match('#^/reward/(\d+)/edit$#D',$path,$m)===1 => route_reward_edit((int)$m[1],$method),
        $path === '/reward/create' && $method === 'POST' => route_reward_create(),
        preg_match('#^/tier/(\d+)/edit$#D',$path,$m)===1 => route_tier_edit((int)$m[1],$method),
        $path === '/tier/create' && $method === 'POST' => route_tier_create(),
        preg_match('#^/(tier|bonus)/(\d+)/delete$#D',$path,$m)===1 => route_program_rule_delete($m[1],(int)$m[2],$method),
        $path === '/tier/delete' && $method === 'POST' => route_tier_delete(),
        $path === '/program/expiry' && $method === 'POST' => route_program_expiry(),
        preg_match('#^/bonus/(\d+)/edit$#D',$path,$m)===1 => route_bonus_edit((int)$m[1],$method),
        $path === '/bonus/create' && $method === 'POST' => route_bonus_create(),
        $path === '/bonus/delete' && $method === 'POST' => route_bonus_delete(),
        preg_match('#^/achievement/(\d+)/edit$#',$path,$m)===1 && in_array($method,['GET','POST'],true) => route_achievement_edit((int)$m[1],$method),
        $path === '/achievement/create' && $method === 'POST' => route_achievement_create(),
        $path === '/achievement/delete' && $method === 'POST' => route_achievement_delete(),
        $path === '/business/referral' && $method === 'POST' => route_business_referral(),
        $path === '/business/timezone' && $method === 'POST' => route_business_timezone(),
        preg_match('#^/segment/(\d+)/edit$#',$path,$m)===1 && in_array($method,['GET','POST'],true) => route_segment_edit((int)$m[1],$method),
        $path === '/segment/create' && $method === 'POST' => route_segment_create(),
        $path === '/segment/delete' && $method === 'POST' => route_segment_delete(),
        preg_match('#^/campaign/(\d+)/edit$#',$path,$m)===1 && in_array($method,['GET','POST'],true) => route_campaign_edit((int)$m[1],$method),
        $path === '/campaign/create' && $method === 'POST' => route_campaign_create(),
        $path === '/campaign/send' && $method === 'POST' => route_campaign_send(),
        $path === '/campaign/delete' && $method === 'POST' => route_campaign_delete(),
        $path === '/apikey/regenerate' && $method === 'POST' => route_apikey_regenerate(),
        $path === '/webhook/create' && $method === 'POST' => route_webhook_create(),
        $path === '/webhook/delete' && $method === 'POST' => route_webhook_delete(),
        $path === '/webhook/retry' && $method === 'POST' => route_webhook_retry(),
        $path === '/webhook/notifications' && $method === 'POST' => route_webhook_notifications(),
        $path === '/webhook/ack' && $method === 'POST' => route_webhook_ack(),
        $path === '/integration/save' && $method === 'POST' => route_integration_save(),
        preg_match('#^/location/(\d+)/wallet-nearby$#D',$path,$m)===1 => route_wallet_location((int)$m[1],$method),
        preg_match('#^/business/(\d+)/monthly-summary\.csv$#D',$path,$m)===1 => route_monthly_summary_csv((int)$m[1],$method),
        preg_match('#^/business/(\d+)/monthly-summary$#D',$path,$m)===1 => route_monthly_summary((int)$m[1],$method),
        preg_match('#^/business/(\d+)/analytics$#', $path, $m) === 1 => route_analytics((int)$m[1]),
        preg_match('#^/program/(\d+)/design$#', $path, $m) === 1 => route_program_design((int)$m[1],$method),
        preg_match('#^/business/(\d+)/design$#', $path, $m) === 1 => route_design((int)$m[1], $method),
        preg_match('#^/business/(\d+)/qr$#', $path, $m) === 1 => route_qr_studio((int)$m[1]),
        preg_match('#^/uploads/([A-Za-z0-9._-]+)$#', $path, $m) === 1 => route_upload($m[1]),
        preg_match('#^/pass/apple/([A-F0-9]{10})\.pkpass$#', $path, $m) === 1 => route_pass_apple($m[1]),
        preg_match('#^/pass/google/([A-F0-9]{10})$#', $path, $m) === 1 => route_pass_google($m[1]),
        preg_match('#^/pass/stamps/([A-F0-9]{10})/([a-f0-9]{64})\.png$#', $path, $m) === 1 => route_stamp_strip($m[1], $m[2]),
        preg_match('#^/program/(\d+)/vouchers$#',$path,$m)===1 && $method==='POST' => route_voucher_program((int)$m[1]),
        preg_match('#^/program/(\d+)/voucher-report$#',$path,$m)===1 && $method==='GET' => route_voucher_report((int)$m[1]),
        preg_match('#^/program/(\d+)/voucher-print$#D',$path,$m)===1 => route_voucher_print((int)$m[1],$method),
        preg_match('#^/program/(\d+)/voucher-export$#',$path,$m)===1 => route_voucher_export((int)$m[1]),
        preg_match('#^/(?:staff/)?business/(\d+)/voucher-counter$#',$path,$m)===1 => route_voucher_counter((int)$m[1],$method),
        preg_match('#^/program/(\d+)/prepaid-settings$#',$path,$m)===1 && $method==='POST' => route_prepaid_settings((int)$m[1]),
        preg_match('#^/business/(\d+)/prepaid-export$#',$path,$m)===1 => route_prepaid_export((int)$m[1]),
        preg_match('#^/staff/prepaid/scan/([A-F0-9]{20})$#',$path,$m)===1 && $method==='GET' => route_prepaid_scan($m[1]),
        preg_match('#^/(?:staff/)?prepaid/sell$#',$path)===1 && $method==='POST' => route_prepaid_action('sell'),
        preg_match('#^/(?:staff/)?prepaid/(\d+)/(use|reverse)$#',$path,$m)===1 && $method==='POST' => route_prepaid_action($m[2],(int)$m[1]),
        preg_match('#^/business/(\d+)/mail$#',$path,$m)===1 => route_mail_admin((int)$m[1],$method),
        preg_match('#^/unsubscribe/(\d+)/([a-f0-9]{64})$#',$path,$m)===1 => route_unsubscribe((int)$m[1],$m[2],$method),
        $path==='/organization' => route_organization($method),
        preg_match('#^/business/(\d+)/team$#',$path,$m)===1 => route_team((int)$m[1],$method),
        $path==='/forgot-password' => route_forgot_password($method),
        $path==='/reset-password' => route_reset_password($method),
        $path==='/wallet/request' => route_member_requests($method),
        preg_match('#^/send/([a-f0-9]{32})$#',$path,$m)===1 => route_send_points($m[1],$method),
        $path==='/program/transfer' && $method==='POST' => route_program_transfer_setting(),
        $path==='/program/claims' && $method==='POST' => route_program_claims(),
        $path==='/reward/window' && $method==='POST' => route_reward_window(),
        $path==='/wallet/claim' && $method==='POST' => route_wallet_claim(),
        preg_match('#^/claim/([A-Za-z0-9-]{8,12})$#',$path,$m)===1 && $method==='GET' => route_claim_entry($m[1]),
        preg_match('#^/(?:staff/)?business/(\d+)/claim-counter$#',$path,$m)===1 => route_claim_counter((int)$m[1],$method),
        $path==='/member/profile' => route_member_profile($method),
        $path==='/member/password' => route_member_password($method),
        $path==='/member/wallet-nearby' => route_member_wallet_nearby($method),
        $path==='/member/wallet-push' => route_member_wallet_push($method),
        preg_match('#^/business/(\d+)/wallet-message$#D',$path,$m)===1 => route_wallet_message((int)$m[1],$method),
        preg_match('#^/business/(\d+)/wallet-push$#D',$path,$m)===1 => route_wallet_push_settings((int)$m[1],$method),
        preg_match('#^/business/(\d+)/wallet-nearby-notice$#D',$path,$m)===1 => route_wallet_nearby_notice((int)$m[1],$method),
        $path==='/member/preferences' => route_member_preferences($method),
        $path === '/api/docs' => route_api_docs(),
        $path === '/member/create' && $method === 'POST' => route_member_create(),
        preg_match('#^/member/(\d+)$#', $path, $m) === 1 => route_member((int)$m[1]),
        $path === '/card/issue' && $method === 'POST' => route_card_issue(),
        preg_match('#^/card/(\d+)/action$#', $path, $m) === 1 && $method === 'POST' => route_card_action((int)$m[1]),
        preg_match('#^/c/([A-F0-9]{10})$#', $path, $m) === 1 => route_public_card($m[1]),
        $path === '/staff/create' && $method === 'POST' => route_staff_create(),
        $path === '/staff/login' => route_staff_login($method),
        $path === '/staff/logout' => route_staff_logout(),
        $path === '/staff' => route_staff_counter(),
        $path === '/staff/scan' => route_staff_scan(),
        $path === '/staff/find' => route_staff_find(),
        preg_match('#^/staff/member/(\d+)$#', $path, $m) === 1 => route_staff_member((int)$m[1]),
        preg_match('#^/staff/card/(\d+)/action$#', $path, $m) === 1 && $method === 'POST' => route_staff_card_action((int)$m[1]),
        preg_match('#^/card/(\d+)/undo$#', $path, $m) === 1 && $method === 'POST' => route_card_undo((int)$m[1]),
        preg_match('#^/staff/card/(\d+)/undo$#', $path, $m) === 1 && $method === 'POST' => route_staff_card_undo((int)$m[1]),
        $path === '/member/login' => route_member_login($method),
        $path === '/member/logout' => route_member_logout(),
        $path === '/wallet' => route_wallet(),
        $path === '/member/export' => route_member_export(),
        $path === '/member/delete' && $method === 'POST' => route_member_delete(),
        preg_match('#^/join/([A-F0-9]{8})$#', $path, $m) === 1 => route_join($m[1], $method),
        default => not_found(),
    };
} catch (Throwable $e) {
    application_exception($e);
}
}

function not_found(): never
{
    http_response_code(404);
    page('404', '<h1>404</h1><p>' . t('Seite nicht gefunden.') . ' <a href="/">' . t('Zur Startseite') . '</a></p>');
    exit;
}

/* ------------------------------------------------------------------ */
/* Uploads (Logos etc. - liegen außerhalb von public/)                 */
/* ------------------------------------------------------------------ */

function route_upload(string $file): never
{
    $file = basename($file);
    $full = (string)config('upload_dir', ROOT_DIR . '/data/uploads') . '/' . $file;
    if (!is_file($full)) {
        not_found();
    }
    $mime = mime_content_type($full) ?: 'application/octet-stream';
    if (!str_starts_with($mime, 'image/')) {
        not_found();
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}

/* ------------------------------------------------------------------ */
/* Auth                                                                */
/* ------------------------------------------------------------------ */

function route_home(): never
{
    if(($hint=demo_login_hint())!==''){page('Loyalty Demo','<div class="auth-box"><h1>Loyalty Demo</h1>'.$hint.'<p><a class="btn" href="/login">Zum Betreiber-Login</a></p><p><a href="/dashboard">Zum Dashboard, wenn bereits angemeldet</a></p></div>');exit;}
    redirect(current_user() ? '/dashboard' : '/login');
}

function route_register(string $method): never
{
    if (platform_setting('registration_open','1')!=='1') { http_response_code(403);page('Registrierung geschlossen','<h1>Registrierung derzeit geschlossen</h1>');exit; }
    if ($method === 'GET') {
        page(t('Registrieren'), '
        <div class="auth-box">
          <h1>' . t('Konto erstellen') . '</h1>
          <p class="muted">' . t('Verwalte Treueprogramme für deine Unternehmen.') . '</p>
          <form method="post" action="/register" class="form">' . csrf_field() . '
            <label>' . t('Name') . ' <input name="name" required maxlength="80"></label>
            <label>' . t('E-Mail') . ' <input type="email" name="email" required maxlength="190"></label>
            <label>' . t('Passwort') . ' <input type="password" name="password" required minlength="8"></label>
            <button class="btn">' . t('Registrieren') . '</button>
          </form>
          <p class="muted">' . t('Schon ein Konto?') . ' <a href="/login">' . t('Anmelden') . '</a></p>
        </div>');
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        flash(t('Bitte Name, gültige E-Mail und ein Passwort mit mindestens 8 Zeichen angeben.'), 'err');
        redirect('/register');
    }
    $exists = db()->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        flash(t('Diese E-Mail ist bereits registriert.'), 'err');
        redirect('/register');
    }
    db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?,?,?)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)db()->lastInsertId();
    flash(t('Willkommen! Lege jetzt dein erstes Unternehmen an.'));
    redirect('/dashboard');
}

function route_login(string $method): never
{
    if ($method === 'GET') {
        page(t('Anmelden'), '
        <div class="auth-box">
          <h1><span class="logo-mark"></span> Loyalty</h1>' . demo_login_hint() . '
          <p class="muted">' . t('Digitale Stempelkarten, Punkte, Gutscheine und Mehrfachkarten für deine Kunden.') . '</p>
          <form method="post" action="/login" class="form">' . csrf_field() . '
            <label>' . t('E-Mail') . ' <input type="email" name="email" required></label>
            <label>' . t('Passwort') . ' <input type="password" name="password" required></label>
            <button class="btn">' . t('Anmelden') . '</button>
          </form>
          <p class="muted">' . t('Noch kein Konto?') . ' <a href="/register">' . t('Registrieren') . '</a></p>
          <p><a href="/forgot-password">Passwort vergessen?</a></p><p class="muted"><a href="/staff/login">' . t('Staff-Login') . '</a> · <a href="/member/login">' . t('Kunden-Login') . '</a></p>
          ' . lang_switcher() . '
        </div>');
        exit;
    }
    $email = strtolower(trim($_POST['email'] ?? ''));
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
        flash(t('E-Mail oder Passwort falsch.'), 'err');
        redirect('/login');
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['user_session_version']=(int)$u['session_version'];
    audit_log((int)$u['id'], 'auth.login');
    redirect('/dashboard');
}

function route_logout(): never
{
    session_destroy();
    redirect('/login');
}

/* ------------------------------------------------------------------ */
/* Dashboard & Businesses                                              */
/* ------------------------------------------------------------------ */

function route_dashboard(): never
{
    $u = require_login();
    $stmt = db()->prepare(
        'SELECT b.*,
            (SELECT count(*) FROM programs p WHERE p.business_id = b.id AND p.active = 1) AS programs,
            (SELECT count(*) FROM members m WHERE m.business_id = b.id) AS members
         FROM businesses b WHERE b.user_id = ? ORDER BY b.created_at DESC'
    );
    $stmt->execute([$u['id']]);
    $rows = $stmt->fetchAll();

    $cards = '';
    foreach ($rows as $b) {
        $cards .= '<a class="biz-card" href="/business/' . (int)$b['id'] . '" style="--brand:' . h($b['brand_color']) . '">
            <strong>' . h($b['name']) . '</strong>
            <span class="muted">' . t('{programs} {programs_label} · {members} {members_label}', [
                'programs' => (int)$b['programs'],
                'programs_label' => (int)$b['programs'] === 1 ? t('Programm') : t('Programme'),
                'members' => (int)$b['members'],
                'members_label' => (int)$b['members'] === 1 ? t('Mitglied') : t('Mitglieder'),
            ]) . '</span>
        </a>';
    }
    if ($rows) {
        $cards = '<div class="grid">' . $cards . '</div>';
    } else {
        $cards = '<p class="muted">' . t('Noch keine Unternehmen. Lege dein erstes an.') . '</p>';
    }

    page(t('Dashboard'), '
      <h1>' . t('Deine Unternehmen') . '</h1>
      ' . $cards . '
      <details class="panel" ' . ($rows ? '' : 'open') . '>
        <summary>' . t('Neues Unternehmen anlegen') . '</summary>
        <form method="post" action="/business/create" class="form inline-form">' . csrf_field() . '
          <label>' . t('Name') . ' <input name="name" required maxlength="80" placeholder="Foundry Coffee"></label>
          <label>' . t('Markenfarbe') . ' <input type="color" name="brand_color" value="#E4572E"></label>
          <button class="btn">' . t('Anlegen') . '</button>
        </form>
      </details>', $u);
    exit;
}

function route_business_create(): never
{
    $u = require_login();
    $name = trim($_POST['name'] ?? '');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['brand_color'] ?? '') ? $_POST['brand_color'] : '#E4572E';
    if ($name === '') {
        flash(t('Name fehlt.'), 'err');
        redirect('/dashboard');
    }
    db()->prepare('INSERT INTO businesses (user_id, name, brand_color) VALUES (?,?,?)')
        ->execute([$u['id'], $name, $color]);
    flash(t('Unternehmen angelegt.'));
    redirect('/business/' . (int)db()->lastInsertId());
}

function route_business(int $id): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], $id);
    if (!$b) {
        not_found();
    }

    $programs = db()->prepare('SELECT * FROM programs WHERE business_id = ? AND active = 1 ORDER BY created_at DESC');
    $programs->execute([$id]);
    $plist = '';
    foreach ($programs->fetchAll() as $p) {
        $plist .= '<a class="prog-card" href="/program/' . (int)$p['id'] . '">
          <span class="badge">' . h(type_label($p['type'])) . '</span>
          <strong>' . h($p['name']) . '</strong>
          <span class="muted">' . h($p['description']) . '</span>
        </a>';
    }
    $plist = $plist ? '<div class="grid">' . $plist . '</div>' : '<p class="muted">' . t('Noch keine Programme.') . '</p>';

    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $members = db()->prepare(
            'SELECT * FROM members WHERE business_id = ? AND (name LIKE ? OR email LIKE ? OR code LIKE ?) ORDER BY name LIMIT 50'
        );
        $like = '%' . $q . '%';
        $members->execute([$id, $like, $like, strtoupper($like)]);
    } else {
        $members = db()->prepare('SELECT * FROM members WHERE business_id = ? ORDER BY created_at DESC LIMIT 50');
        $members->execute([$id]);
    }

    $staffQ = db()->prepare('SELECT * FROM staff WHERE business_id = ? AND active = 1 ORDER BY name');
    $staffQ->execute([$id]);
    $staffList = '';
    foreach ($staffQ->fetchAll() as $s) {
        $staffList .= '<li>' . h($s['name']) . '</li>';
    }
    $staffList = $staffList ? '<ul>' . $staffList . '</ul>' : '<p class="muted">' . t('Noch keine Staff-Zugänge.') . '</p>';

    $achQ = db()->prepare('SELECT * FROM achievements WHERE business_id = ? ORDER BY active DESC, threshold');
    $achQ->execute([$id]);
    $achList = '';
    foreach ($achQ->fetchAll() as $a) {
        $achList .= '<li>'.((int)$a['active']===1?'🏆 ':'<span class="badge">Pausiert</span> ').'<strong>' . h($a['name']) . '</strong> – ' . h(metric_label($a['metric']))
            . ' ' . t('ab') . ' ' . (int)$a['threshold']
            . ((int)$a['reward_points'] > 0 ? ' (+' . (int)$a['reward_points'] . ' ' . t('Punkte') . ')' : '')
            . ' <a class="btn ghost small" href="/achievement/'.(int)$a['id'].'/edit">Prüfen / Bearbeiten</a>'
            . ' <form method="post" action="/achievement/delete" style="display:inline">' . csrf_field()
            . '<input type="hidden" name="business_id" value="' . $id . '">'
            . '<input type="hidden" name="achievement_id" value="' . (int)$a['id'] . '">'
            . '<button class="btn ghost small">' . t('Löschen') . '</button></form></li>';
    }
    $achList = $achList ? '<ul class="achievement-list">' . $achList . '</ul>' : '<p class="muted">' . t('Noch keine Meilensteine.') . '</p>';
    $metricOpts = '';
    foreach (METRIC_LABELS as $key => $label) {
        $metricOpts .= '<option value="' . $key . '">' . h(metric_label($key)) . '</option>';
    }
    $mlist = '';
    foreach ($members->fetchAll() as $m) {
        $mlist .= '<tr><td><a href="/member/' . (int)$m['id'] . '">' . h($m['name']) . '</a></td>
          <td class="muted">' . h($m['email']) . '</td>
          <td><code>' . h($m['code']) . '</code></td>
          <td><a class="btn small ghost" href="/c/' . h($m['code']) . '" target="_blank">' . t('Kundenkarte') . '</a></td></tr>';
    }
    $mlist = $mlist
        ? '<div class="table-scroll"><table class="table"><thead><tr><th>' . t('Name') . '</th><th>' . t('E-Mail') . '</th><th>Code</th><th></th></tr></thead><tbody>' . $mlist . '</tbody></table></div>'
        : '<p class="muted">' . t('Noch keine Mitglieder.') . '</p>';

    page($b['name'], '
      <p><a href="/dashboard" class="muted">← ' . t('Alle Unternehmen') . '</a></p>
      <h1>' . h($b['name']) . '</h1><p><a class="btn ghost" href="/business/'.(int)$b['id'].'/mail">E-Mail & Automationen</a> <a class="btn ghost" href="/business/'.(int)$b['id'].'/team">Team & Rechte</a></p>
      <p class="btn-row">
        <a class="btn ghost small" href="/business/' . $id . '/settings">Unternehmensname</a>
        <a class="btn ghost small" href="/business/' . $id . '/analytics">' . t('Analytics') . '</a>
        <a class="btn ghost small" href="/business/' . $id . '/design">' . t('Karten-Design') . '</a>
        <a class="btn ghost small" href="/business/' . $id . '/qr">' . t('QR-Studio') . '</a>
      </p>

      <h2>' . t('Treueprogramme') . '</h2>
      ' . $plist . templates_panel_html((int)$b['id']) . '
      <details class="panel">
        <summary>' . t('Neues Programm anlegen') . '</summary>
        <form method="post" action="/program/create" class="form" id="program-form">' . csrf_field() . '
          <input type="hidden" name="business_id" value="' . $id . '">
          <label>' . t('Typ') . '
            <select name="type" id="type-select">
              <option value="stamps">' . t('Stempelkarte') . '</option>
              <option value="points">' . t('Punkte &amp; Prämien') . '</option>
              <option value="voucher">' . t('Gutschein') . '</option>
              <option value="pass">' . t('Mehrfachkarte (Prepaid)') . '</option>
            </select>
          </label>
          <label>' . t('Name') . ' <input name="name" required maxlength="80" placeholder="' . t('Dein nächster Kaffee') . '"></label>
          <label>' . t('Beschreibung') . ' <input name="description" maxlength="190" placeholder="' . t('Sammle 6 Stempel. Verdiene einen Kaffee.') . '"></label>
          <label class="t stamps">' . t('Benötigte Stempel') . ' <input type="number" name="stamps_required" value="6" min="2" max="50"></label>
          <label class="t stamps">' . t('Belohnung') . ' <input name="reward_text" maxlength="120" placeholder="' . t('1 Kaffee gratis') . '"></label>
          <label class="t voucher">' . t('Gutscheinwert') . ' <input name="voucher_value" maxlength="60" placeholder="' . t('20 % Rabatt') . '"></label>
          <label class="t voucher">' . t('Nutzungen pro Kunde') . ' <input type="number" name="uses_per_customer" value="1" min="1" max="100"></label>
          <label class="t pass">' . t('Enthaltene Besuche') . ' <input type="number" name="pass_visits" value="10" min="1" max="500"></label>
          <button class="btn">' . t('Programm anlegen') . '</button>
        </form>
      </details>

      <h2>' . t('Mitglieder') . '</h2>
      <form method="get" action="/business/' . $id . '" class="form inline-form">
        <label>' . t('Kunde finden') . ' <input name="q" value="' . h($q) . '" placeholder="' . t('Name, E-Mail oder Code') . '"></label>
        <button class="btn ghost">' . t('Suchen') . '</button>
      </form>
      ' . $mlist . '
      <details class="panel">
        <summary>' . t('Neues Mitglied anlegen') . '</summary>
        <form method="post" action="/member/create" class="form inline-form">' . csrf_field() . '
          <input type="hidden" name="business_id" value="' . $id . '">
          <label>' . t('Name') . ' <input name="name" required maxlength="80"></label>
          <label>' . t('E-Mail (optional)') . ' <input type="email" name="email" maxlength="190"></label>
          <label>' . t('Login-Passwort (optional)') . ' <input type="password" name="password" minlength="8" autocomplete="new-password"></label>
          <button class="btn">' . t('Anlegen') . '</button>
        </form>
      </details>

      <h2>' . t('Team (Staff)') . '</h2>
      ' . $staffList . '
      <details class="panel">
        <summary>' . t('Staff-Zugang anlegen') . '</summary>
        <form method="post" action="/staff/create" class="form inline-form">' . csrf_field() . '
          <input type="hidden" name="business_id" value="' . $id . '">
          <label>' . t('Name') . ' <input name="name" required maxlength="80"></label>
          <label>PIN <input type="password" name="pin" required minlength="6" maxlength="12" pattern="[0-9]{6,12}"></label>
          <button class="btn">' . t('Anlegen') . '</button>
        </form>
      </details>

      <h2>' . t('Meilensteine (Achievements)') . '</h2>
      ' . $achList . '
      <details class="panel">
        <summary>' . t('Neuen Meilenstein anlegen') . '</summary><p>Name: 1 bis 80 Zeichen. Schwelle: 1 bis 100000, Bonus: 0 bis 100000, jeweils ganze Zahlen. Anlegen vergibt nichts rückwirkend; erst die nächste unterstützte Kartenaktion prüft die Regel. Bonus nur mit vorhandener Punktekarte.</p>
        <form method="post" action="/achievement/create" class="form inline-form">' . csrf_field() . '
          <input type="hidden" name="business_id" value="' . $id . '">
          <label>' . t('Name') . ' <input name="name" required maxlength="80" placeholder="' . t('Stammkunde') . '"></label>
          <label>' . t('Messgröße') . ' <select name="metric">' . $metricOpts . '</select></label>
          <label>' . t('Schwelle') . ' <input type="number" name="threshold" value="10" min="1" max="100000" step="1" required></label>
          <label>' . t('Bonus-Punkte, 0 = kein Bonus') . ' <input type="number" name="reward_points" value="0" min="0" max="100000" step="1" required></label>
          <button class="btn">' . t('Anlegen') . '</button>
        </form>
      </details>

      <h2>' . t('Freunde werben (Referrals)') . '</h2>
      <p class="muted">' . t('Bonus-Punkte, wenn ein bestehender Kunde einen neuen Kunden wirbt (Beitritt über seinen persönlichen Link im Wallet).') . '</p>
      <form method="post" action="/business/referral" class="form inline-form">' . csrf_field() . '
        <input type="hidden" name="business_id" value="' . $id . '">
        <label>' . t('Punkte für den Werber') . ' <input type="number" name="referrer" value="' . (int)($b['referral_points_referrer'] ?? 0) . '" min="0" max="100000"></label>
        <label>' . t('Punkte für den Neukunden') . ' <input type="number" name="referee" value="' . (int)($b['referral_points_referee'] ?? 0) . '" min="0" max="100000"></label>
        <button class="btn ghost">' . t('Speichern') . '</button>
      </form>
      ' . business_timezone_form_html($b) . '
      <h2>' . t('API &amp; Integrationen') . '</h2>
      ' . api_html($b) . '

      <h2>' . t('Kunden-Segmente') . '</h2>
      ' . segments_html($id) . '

      <h2>' . t('E-Mail-Kampagnen') . '</h2>
      <p class="muted">' . t('Kampagnen gehen an ein Segment. Ohne SMTP-Anbindung erfolgt die Zustellung lokal in die Wallet-Inbox der Mitglieder (inkl. Versand-Protokoll).') . '</p>
      ' . campaigns_html($id) . '
      <p class="muted">' . t('Staff-Portal') . ': <a href="/staff/login?b=' . $id . '">/staff/login?b=' . $id . '</a> · ' . t('Kunden-Beitritt') . ': <a href="/join/' . h($b['join_code'] ?? '') . '" target="_blank">/join/' . h($b['join_code'] ?? '') . '</a></p>
      <script>
      (function(){
        var sel = document.getElementById("type-select");
        function toggle(){
          var t = sel.value;
          document.querySelectorAll("#program-form .t").forEach(function(el){
            el.style.display = el.classList.contains(t) ? "" : "none";
          });
        }
        sel.addEventListener("change", toggle); toggle();
      })();
      </script>', $u, $b['brand_color']);
    exit;
}

/* ------------------------------------------------------------------ */
/* Programme & Rewards                                                 */
/* ------------------------------------------------------------------ */

function route_program_create(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $type = $_POST['type'] ?? '';
    if (!$b || !in_array($type, ['stamps', 'points', 'voucher', 'pass'], true) || trim($_POST['name'] ?? '') === '') {
        flash(t('Ungültige Eingabe.'), 'err');
        redirect('/dashboard');
    }
    db()->prepare(
        'INSERT INTO programs (business_id, type, name, description, stamps_required, reward_text, voucher_value, uses_per_customer, pass_visits)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $b['id'], $type, trim($_POST['name']), trim($_POST['description'] ?? ''),
        max(2, (int)($_POST['stamps_required'] ?? 6)),
        trim($_POST['reward_text'] ?? ''),
        trim($_POST['voucher_value'] ?? ''),
        max(1, (int)($_POST['uses_per_customer'] ?? 1)),
        max(1, (int)($_POST['pass_visits'] ?? 10)),
    ]);
    flash(t('Programm angelegt.'));
    redirect('/program/' . (int)db()->lastInsertId());
}

function route_program(int $id): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], $id);
    if (!$p) {
        not_found();
    }
    $b = own_business((int)$u['id'], (int)$p['business_id']);

    $body = '<p><a href="/business/' . (int)$b['id'] . '" class="muted">← ' . h($b['name']) . '</a></p>
      <h1><span class="badge">' . h(type_label($p['type'])) . '</span> ' . h($p['name']) . '</h1>
      <p class="muted">' . h($p['description']) . '</p>';

    $body.='<p><a class="btn ghost" href="/program/'.$id.'/design">Programmdesign</a></p>';
    if ($p['type'] === 'stamps') {
        $body .= '<p>' . t('Ziel:') . ' <strong>' . (int)$p['stamps_required'] . ' ' . t('Stempel') . '</strong> · ' . t('Belohnung:') . ' <strong>' . h($p['reward_text']) . '</strong></p>';
    } elseif ($p['type'] === 'voucher') {
        $body .= '<p>' . t('Wert:') . ' <strong>' . h($p['voucher_value']) . '</strong> · ' . t('Nutzungen pro Kunde:') . ' <strong>' . (int)$p['uses_per_customer'] . '</strong></p>';
    } elseif ($p['type'] === 'pass') {
        $body .= '<p>' . t('Paket:') . ' <strong>' . (int)$p['pass_visits'] . ' ' . t('Besuche') . '</strong> (' . t('Bezahlung erfolgt im Geschäft') . ')</p>';
    }

    if ($p['type'] === 'points') {
        $rewards = db()->prepare('SELECT * FROM rewards WHERE program_id = ? ORDER BY points_cost');
        $rewards->execute([$id]);
        $rlist = '';
        foreach ($rewards->fetchAll() as $r) {
            $rlist .= '<li>' . h($r['name']) . ' <span class="muted">· ' . (int)$r['points_cost'] . ' ' . t('Punkte') . '</span> <a class="btn ghost small" href="/reward/' . (int)$r['id'] . '/edit">Bearbeiten</a></li>';
        }
        $body .= '<h2>' . t('Prämien') . '</h2>' . ($rlist ? '<ul class="reward-list">' . $rlist . '</ul>' : '<p class="muted">' . t('Noch keine Prämien.') . '</p>') . '
        <form method="post" action="/reward/create" class="form inline-form panel">' . csrf_field() . '
          <input type="hidden" name="program_id" value="' . $id . '">
          <label>' . t('Prämie') . ' <input name="name" required maxlength="120" placeholder="' . t('Maniküre gratis') . '"></label>
          <label>' . t('Punktekosten') . ' <input type="number" name="points_cost" value="100" min="1" max="100000"></label>
          <button class="btn">' . t('Hinzufügen') . '</button>
        </form>';

        /* Membership Tiers */
        $tiers = db()->prepare('SELECT * FROM tiers WHERE program_id = ? ORDER BY min_points');
        $tiers->execute([$id]);
        $tlist = '';
        foreach ($tiers->fetchAll() as $tier) {
            $tlist .= '<li><strong>' . h($tier['name']) . '</strong> <span class="muted">' . t('ab') . ' ' . (int)$tier['min_points'] . ' ' . t('Punkten') . ($tier['benefit'] ? ' · ' . h($tier['benefit']) : '') . '</span>
              <a class="btn ghost small" href="/tier/' . (int)$tier['id'] . '/edit">Bearbeiten</a> <a class="btn ghost small" href="/tier/' . (int)$tier['id'] . '/delete">Löschen prüfen</a></li>';
        }
        $body .= '<h2>' . t('Mitgliedsstufen (Tiers)') . '</h2>' . ($tlist ? '<ul class="tier-list">' . $tlist . '</ul>' : '<p class="muted">' . t('Noch keine Stufen.') . '</p>') . '
        <form method="post" action="/tier/create" class="form inline-form panel">' . csrf_field() . '
          <input type="hidden" name="program_id" value="' . $id . '">
          <label>' . t('Stufe') . ' <input name="name" required maxlength="40" placeholder="Gold"></label>
          <label>' . t('Ab Punkten (Lifetime)') . ' <input type="number" name="min_points" value="500" min="0" max="1000000"></label>
          <label>' . t('Vorteil') . ' <input name="benefit" maxlength="120" placeholder="' . t('10 % auf alles') . '"></label>
          <button class="btn">' . t('Hinzufügen') . '</button>
        </form>';

        /* Bonus Hours */
        $wdNames = weekday_names();
        $bonus = db()->prepare('SELECT * FROM bonus_hours WHERE program_id = ? ORDER BY weekday, start_hour');
        $bonus->execute([$id]);
        $blist = '';
        foreach ($bonus->fetchAll() as $bh) {
            $blist .= '<li>' . $wdNames[(int)$bh['weekday']] . ' ' . (int)$bh['start_hour'] . '–' . (int)$bh['end_hour'] . ' ' . t('Uhr') . ': <strong>x' . (int)$bh['multiplier'] . ' ' . t('Punkte') . '</strong>
              <a class="btn ghost small" href="/bonus/' . (int)$bh['id'] . '/edit">Bearbeiten</a> <a class="btn ghost small" href="/bonus/' . (int)$bh['id'] . '/delete">Löschen prüfen</a></li>';
        }
        $wdOpts = '';
        foreach ($wdNames as $i => $n) {
            $wdOpts .= '<option value="' . $i . '">' . h($n) . '</option>';
        }
        $body .= '<h2>' . t('Bonus-Zeiten') . '</h2><p class="muted">Serverzeitzone: ' . h(date_default_timezone_get()) . '. Unternehmenszeitzone gilt hier nicht. Überschneidung: höchster Multiplikator, keine Multiplikation.</p>' . ($blist ? '<ul class="bonus-list">' . $blist . '</ul>' : '<p class="muted">' . t('Keine Bonus-Zeiten. Punkte-Multiplikator in ruhigen Stunden.') . '</p>') . '
        <form method="post" action="/bonus/create" class="form inline-form panel">' . csrf_field() . '
          <input type="hidden" name="program_id" value="' . $id . '">
          <label>' . t('Wochentag') . ' <select name="weekday">' . $wdOpts . '</select></label>
          <label>' . t('Von') . ' <input type="number" name="start_hour" value="14" min="0" max="23"></label>
          <label>' . t('Bis') . ' <input type="number" name="end_hour" value="16" min="1" max="24"></label>
          <label>' . t('Multiplikator') . ' <input type="number" name="multiplier" value="2" min="2" max="10"></label>
          <button class="btn">' . t('Hinzufügen') . '</button>
        </form>' . points_expiry_form_html($p) . points_transfer_form_html($p) . program_claims_form_html($p) . reward_windows_form_html($p);
    }

    if($p['type']==='voucher')$body.=voucher_form_html($p);
    if($p['type']==='pass')$body.=prepaid_program_html($p);
    $cards = db()->prepare(
        'SELECT c.*, m.name AS member_name, m.code FROM cards c JOIN members m ON m.id = c.member_id
         WHERE c.program_id = ? ORDER BY c.created_at DESC LIMIT 100'
    );
    $cards->execute([$id]);
    $rows = '';
    foreach ($cards->fetchAll() as $c) {
        $state = match ($p['type']) {
            'stamps' => $c['stamps'] . '/' . (int)$p['stamps_required'] . ' ' . t('Stempel') . ($c['pending_rewards'] ? ' · ' . $c['pending_rewards'] . ' ' . t('Belohnung offen') : ''),
            'points' => $c['points'] . ' ' . t('Punkte'),
            'voucher' => ($c['uses_left'] ?? 0) . ' ' . t('Nutzungen übrig'),
            'pass' => ($c['visits_left'] ?? 0) . ' ' . t('Besuche übrig'),
        };
        $rows .= '<tr><td><a href="/member/' . (int)$c['member_id'] . '">' . h($c['member_name']) . '</a></td><td>' . h($state) . '</td></tr>';
    }
    $body .= '<h2>' . t('Ausgestellte Karten') . '</h2>' . ($rows
        ? '<table class="table"><thead><tr><th>' . t('Mitglied') . '</th><th>' . t('Stand') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        : '<p class="muted">' . t('Noch keine Karten ausgestellt. Öffne ein Mitglied und stelle eine Karte aus.') . '</p>');

    page($p['name'], $body, $u, $b['brand_color']);
    exit;
}

/* ------------------------------------------------------------------ */
/* Mitglieder & Karten                                                 */
/* ------------------------------------------------------------------ */

function route_member_create(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $name = trim($_POST['name'] ?? '');
    if (!$b || $name === '') {
        flash(t('Ungültige Eingabe.'), 'err');
        redirect('/dashboard');
    }
    $pw = (string)($_POST['password'] ?? '');
    $pwHash = strlen($pw) >= 8 ? password_hash($pw, PASSWORD_DEFAULT) : null;
    db()->prepare('INSERT INTO members (business_id, name, email, code, password_hash) VALUES (?,?,?,?,?)')
        ->execute([$b['id'], $name, trim($_POST['email'] ?? ''), member_code(), $pwHash]);
    flash(t('Mitglied angelegt.') . ($pwHash ? ' ' . t('Kunden-Login ist aktiv.') : ''));
    redirect('/member/' . (int)db()->lastInsertId());
}

function route_member(int $id): never
{
    $u = require_login();
    $m = own_member((int)$u['id'], $id);
    if (!$m) {
        not_found();
    }
    $b = own_business((int)$u['id'], (int)$m['business_id']);
    $programs = db()->prepare('SELECT * FROM programs WHERE business_id = ? AND active = 1');
    $programs->execute([$b['id']]);

    $body = '<p><a href="/business/' . (int)$b['id'] . '" class="muted">← ' . h($b['name']) . '</a></p>
      <h1>' . h($m['name']) . '</h1>
      <p class="muted">' . h($m['email']) . ' · ' . t('Kundenkarte') . ': <a href="/c/' . h($m['code']) . '" target="_blank">/c/' . h($m['code']) . '</a></p>'
      . '<p><a href="/business/'.(int)$b['id'].'/wallet-message?member_id='.$id.'">Wallet-Mitteilung für dieses Mitglied prüfen</a></p>'
      . member_panels($id, $programs->fetchAll(), '/card');

    page($m['name'], $body, $u, $b['brand_color']);
    exit;
}

/** Karten-Panels eines Mitglieds; $actionBase ist '/card' (Owner) oder '/staff/card' (Staff). */
function member_panels(int $id, array $programs, string $actionBase): string
{
    $body = '';
    $cardStmt = db()->prepare('SELECT * FROM cards WHERE member_id = ? AND program_id = ?');
    $txnStmt = db()->prepare('SELECT * FROM transactions WHERE card_id = ? ORDER BY id DESC LIMIT 8');

    foreach ($programs as $p) {
        $cardStmt->execute([$id, $p['id']]);
        $card = $cardStmt->fetch();
        $body .= '<section class="panel card-panel">';
        $body .= '<h2><span class="badge">' . h(type_label($p['type'])) . '</span> ' . h($p['name']) . '</h2>';

        if (!$card) {
            $body .= '<form method="post" action="/card/issue">' . csrf_field() . '
              <input type="hidden" name="member_id" value="' . $id . '">
              <input type="hidden" name="program_id" value="' . (int)$p['id'] . '">
              <button class="btn ghost">' . t('Karte ausstellen') . '</button></form></section>';
            continue;
        }

        $cid = (int)$card['id'];
        $actionUrl = $actionBase . '/' . $cid . '/action';
        if ($p['type'] === 'stamps') {
            $req = (int)$p['stamps_required'];
            $dots = '';
            for ($i = 1; $i <= $req; $i++) {
                $dots .= '<span class="dot ' . ($i <= $card['stamps'] ? 'filled' : '') . '"></span>';
            }
            $body .= '<div class="stamp-row">' . $dots . '</div>
              <p><strong>' . (int)$card['stamps'] . ' / ' . $req . '</strong> ' . t('Stempel') . ' · ' . t('Offene Belohnungen:') . ' <strong>' . (int)$card['pending_rewards'] . '</strong></p>
              <form method="post" action="' . $actionUrl . '" class="btn-row">' . csrf_field() . '
                <button class="btn" name="action" value="stamp">+ ' . t('Stempel') . '</button>
                ' . ($card['pending_rewards'] > 0
                    ? '<button class="btn accent" name="action" value="redeem_stamp_reward">' . t('Belohnung einlösen') . '</button>' : '') . '
              </form>';
        } elseif ($p['type'] === 'points') {
            $rewards = db()->prepare('SELECT * FROM rewards WHERE program_id = ? ORDER BY points_cost');
            $rewards->execute([$p['id']]);
            $opts = '';
            foreach ($rewards->fetchAll() as $r) {
                $disabled = $card['points'] < $r['points_cost'] ? ' disabled' : '';
                $opts .= '<button class="btn ghost small" name="action" value="redeem_reward:' . (int)$r['id'] . '"' . $disabled . '>'
                    . h($r['name']) . ' (' . (int)$r['points_cost'] . ' P.)</button>';
            }
            $body .= '<p class="big-number">' . (int)$card['points'] . ' <span>' . t('Punkte') . '</span></p>
              ' . tier_html((int)$p['id'], (int)($card['points_lifetime'] ?? 0)) . points_expiry_notice_html((int)$card['id']) . '
              <form method="post" action="' . $actionUrl . '" class="form inline-form">' . csrf_field() . '
                <input type="number" name="amount" value="10" min="1" max="10000" style="width:6rem">
                <button class="btn" name="action" value="points_add">' . t('Punkte gutschreiben') . '</button>
              </form>
              ' . ($opts ? '<form method="post" action="' . $actionUrl . '" class="btn-row">' . csrf_field() . $opts . '</form>' : '');
        } elseif ($p['type'] === 'voucher') {
            $body .= '<p class="big-number">' . (int)($card['uses_left'] ?? 0) . ' <span>' . t('Nutzungen übrig') . '</span></p>
              <p class="muted">' . h($p['voucher_value']) . '</p>
              ' . (($card['uses_left'] ?? 0) > 0
                  ? '<form method="post" action="' . $actionUrl . '">' . csrf_field() . '<button class="btn accent" name="action" value="voucher_use">' . t('Gutschein einlösen') . '</button></form>'
                  : '<p class="muted">' . t('Keine Nutzungen mehr.') . '</p>');
        } elseif ($p['type'] === 'pass') {
            $body .= '<p class="big-number">' . (int)($card['visits_left'] ?? 0) . ' <span>' . t('Besuche übrig') . '</span></p>
              ' . (($card['visits_left'] ?? 0) > 0
                  ? '<form method="post" action="' . $actionUrl . '">' . csrf_field() . '<button class="btn" name="action" value="visit">' . t('Besuch bestätigen') . '</button></form>'
                  : '<p class="muted">' . t('Karte aufgebraucht.') . '</p>');
        }

        $txnStmt->execute([$cid]);
        $txns = $txnStmt->fetchAll();
        if ($txns) {
            $body .= '<details class="txlog"><summary>' . t('Verlauf') . '</summary><ul>';
            foreach ($txns as $txnRow) {
                $body .= '<li><span class="muted">' . h($txnRow['created_at']) . '</span> ' . h($txnRow['note']);
                if ($txnRow['reversed_at'] ?? null) {
                    $body .= ' <span class="muted">(' . t('storniert') . ')</span>';
                } elseif (!in_array($txnRow['kind'], ['reversal', 'reward_earned', 'points_expire', 'points_transfer_in', 'points_transfer_out'], true) && ($actionBase!=='/staff/card' || (($counterStaff=current_staff()) && in_array('reverse',staff_permissions($counterStaff),true) && (int)$txnRow['staff_id']===(int)$counterStaff['id']))) {
                    $body .= ' <form method="post" action="' . $actionBase . '/' . $cid . '/undo" class="undo-form" onsubmit="return confirm(\'' . t('Diese Aktion stornieren?') . '\')">' . csrf_field() . '<button class="undo-btn" name="txn_id" value="' . (int)$txnRow['id'] . '" title="' . t('Stornieren') . '">↩</button></form>';
                }
                $body .= '</li>';
            }
            $body .= '</ul></details>';
        }
        $body .= '</section>';
    }
    $body.=prepaid_member_html($id,$actionBase==='/staff/card'?'/staff':'');
    return $body;
}

function route_card_issue(): never
{
    $u = require_login();
    $m = own_member((int)$u['id'], (int)($_POST['member_id'] ?? 0));
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if (!$m || !$p) {
        not_found();
    }
    $uses = $p['type'] === 'voucher' ? (int)$p['uses_per_customer'] : null;
    $visits = $p['type'] === 'pass' ? (int)$p['pass_visits'] : null;
    db()->prepare('INSERT OR IGNORE INTO cards (program_id, member_id, uses_left, visits_left) VALUES (?,?,?,?)')
        ->execute([$p['id'], $m['id'], $uses, $visits]);
    flash(t('Karte ausgestellt.'));
    redirect('/member/' . (int)$m['id']);
}

function route_card_action(int $cardId): never
{
    $u = require_login();
    $card = own_card((int)$u['id'], $cardId);
    if (!$card) {
        not_found();
    }
    do_card_action($card, (string)($_POST['action'] ?? ''));
    redirect('/member/' . (int)$card['member_id']);
}

/** Führt eine Karten-Aktion aus (Owner- und Staff-Flow). Flasht das Ergebnis. */
function do_card_action(array $card, string $action): void
{
    $cardId = (int)$card['id'];
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    $fresh=$db->prepare('SELECT c.*, p.type AS program_type,p.stamps_required,p.name AS program_name FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.id=?');
    $fresh->execute([$cardId]);$card=$fresh->fetch();
    if(!$card){$db->exec('ROLLBACK');return;}

    if(PHP_SAPI!=='cli' && str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/staff/'))require_staff_permission(in_array($action,['stamp','points_add'],true)?'award':'redeem');
    switch (true) {
        case $action === 'stamp' && $card['program_type'] === 'stamps':
            $req = (int)$card['stamps_required'];
            $stamps = (int)$card['stamps'] + 1;
            $pending = (int)$card['pending_rewards'];
            $earned = false;
            if ($stamps >= $req) {
                $pending++;
                $stamps -= $req;
                $earned = true;
            }
            $db->prepare('UPDATE cards SET stamps = ?, pending_rewards = ? WHERE id = ?')
                ->execute([$stamps, $pending, $cardId]);
            txn($cardId, 'stamp_add', 1, '+1 ' . t('Stempel') . ' (' . $stamps . '/' . $req . ')');
            if ($earned) {
                txn($cardId, 'reward_earned', 1, t('Belohnung verdient:') . ' ' . $card['program_name']);
                flash(t('Stempel vergeben – Belohnung verdient!'));
            } else {
                flash(t('Stempel vergeben.'));
            }
            break;

        case $action === 'redeem_stamp_reward' && $card['program_type'] === 'stamps' && $card['pending_rewards'] > 0:
            $db->prepare('UPDATE cards SET pending_rewards = pending_rewards - 1 WHERE id = ?')->execute([$cardId]);
            txn($cardId, 'reward_redeem', 1, t('Belohnung eingelöst'));
            flash(t('Belohnung eingelöst.'));
            break;

        case $action === 'points_add' && $card['program_type'] === 'points':
            $base = max(1, min(10000, (int)($_POST['amount'] ?? 0)));
            $mult = points_multiplier((int)$card['program_id']);
            $amount = $base * $mult;
            $db->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
                ->execute([$amount, $amount, $cardId]);
            txn($cardId, 'points_add', $amount, '+' . $amount . ' ' . t('Punkte') . ($mult > 1 ? ' (' . t('Bonus-Zeit') . ' x' . $mult . ')' : ''));
            flash('+' . $amount . ' ' . t('Punkte') . ($mult > 1 ? ' – ' . t('Bonus-Zeit') . ' x' . $mult . ' ' . t('aktiv!') : '') . '.');
            break;

        case str_starts_with($action, 'redeem_reward:') && $card['program_type'] === 'points':
            $rewardId = (int)substr($action, strlen('redeem_reward:'));
            $r = $db->prepare('SELECT * FROM rewards WHERE id = ? AND program_id = ?');
            $r->execute([$rewardId, $card['program_id']]);
            $reward = $r->fetch();
            if (!$reward || $card['points'] < $reward['points_cost']) {
                flash(t('Nicht genug Punkte.'), 'err');
                break;
            }
            if ($werr = reward_window_error($reward)) {
                flash($werr, 'err');
                break;
            }
            $db->prepare('UPDATE cards SET points = points - ? WHERE id = ?')->execute([$reward['points_cost'], $cardId]);
            txn($cardId, 'points_redeem', (int)$reward['points_cost'], t('Eingelöst:') . ' ' . $reward['name'] . ' (−' . (int)$reward['points_cost'] . ' ' . t('Punkte') . ')');
            flash(t('Prämie eingelöst:') . ' ' . $reward['name']);
            break;

        case $action === 'voucher_use' && $card['program_type'] === 'voucher' && ($card['uses_left'] ?? 0) > 0:
            $db->prepare('UPDATE cards SET uses_left = uses_left - 1 WHERE id = ?')->execute([$cardId]);
            txn($cardId, 'voucher_use', 1, t('Gutschein eingelöst'));
            flash(t('Gutschein eingelöst.'));
            break;

        case $action === 'visit' && $card['program_type'] === 'pass' && ($card['visits_left'] ?? 0) > 0:
            $db->prepare('UPDATE cards SET visits_left = visits_left - 1 WHERE id = ?')->execute([$cardId]);
            txn($cardId, 'visit', 1, t('Besuch bestätigt'));
            flash(t('Besuch bestätigt.'));
            break;

        default:
            flash(t('Aktion nicht möglich.'), 'err');
    }

    $db->exec('COMMIT');

    /* Meilensteine pruefen (nach dem Commit) */
    $biz = $db->prepare('SELECT business_id FROM programs WHERE id = ?');
    $biz->execute([$card['program_id']]);
    $bid = $biz->fetchColumn();
    if ($bid) {
        check_achievements((int)$card['member_id'], (int)$bid);
        $eventMap = [
            'stamp' => 'card.stamp', 'points_add' => 'card.points',
            'redeem_stamp_reward' => 'card.redeem', 'voucher_use' => 'card.redeem',
            'visit' => 'card.visit',
        ];
        $baseAction = explode(':', $action)[0];
        if (str_starts_with($action, 'redeem_reward:')) {
            $baseAction = 'redeem_stamp_reward';
        }
        if (isset($eventMap[$baseAction])) {
            fire_webhook((int)$bid, $eventMap[$baseAction], [
                'card_id' => $cardId, 'member_id' => (int)$card['member_id'], 'action' => $baseAction,
            ]);
        }
        /* Wallet-Pässe aktualisieren (Apple Push / Google Update) */
        wallet_pass_update_for_card($cardId);
    }
}

/* ------------------------------------------------------------------ */
/* Öffentliche Kundenkarte                                             */
/* ------------------------------------------------------------------ */

function route_public_card(string $code): never
{
    $stmt = db()->prepare(
        'SELECT m.*, b.name AS business_name, b.brand_color, b.card_design FROM members m
         JOIN businesses b ON b.id = m.business_id WHERE m.code = ?'
    );
    $stmt->execute([$code]);
    $m = $stmt->fetch();
    if (!$m) {
        not_found();
    }
    $design = business_design($m);

    $firstName = explode(' ', trim($m['name']))[0];
    $body = '<div class="member-view">';
    if ($design['logo'] !== '') {
        $body .= '<img class="member-logo" src="' . h(upload_url($design['logo'])) . '" alt="' . h($m['business_name']) . '">';
    }
    $body .= '<p class="brand-tag">' . h($design['header'] !== '' ? $design['header'] : $m['business_name']) . '</p>
      <h1>' . t('Hallo, {name}!', ['name' => $firstName]) . '</h1>';

    $cardsHtml = wallet_cards_html((int)$m['id']);
    $hasCards = $cardsHtml !== '';
    $body .= $cardsHtml;

    if (!$hasCards) {
        $body .= '<p class="muted">' . t('Noch keine Karten. Zeige diesen Code beim nächsten Besuch.') . '</p>';
    }

    $cardUrl = base_url() . '/c/' . $m['code'];
    $logoArg = ($design['qr_logo'] && $design['logo'] !== '') ? upload_url($design['logo']) : '';
    $body .= '<div class="qr-wrap"><div class="qr-box" data-qr="' . h($cardUrl) . '" data-qr-logo="' . h($logoArg) . '" data-qr-size="180"></div>
      <p class="muted">Code: <code>' . h($m['code']) . '</code></p></div>';

    /* Wallet-Pässe */
    $body .= '<div class="wallet-add">
        <a class="wallet-btn wallet-btn-apple" href="/pass/apple/' . h($m['code']) . '.pkpass"> Apple Wallet</a>
        <a class="wallet-btn wallet-btn-google" href="/pass/google/' . h($m['code']) . '">G &#8231; Wallet</a>
      </div>';

    if ($design['footer'] !== '') {
        $body .= '<p class="member-footer muted">' . h($design['footer']) . '</p>';
    }
    $body .= '</div>';

    page($m['business_name'], $body, null, $m['brand_color']);
    exit;
}

/* ------------------------------------------------------------------ */
/* Hilfsanzeigen                                                       */
/* ------------------------------------------------------------------ */

function weekday_names(): array
{
    return [
        t('Sonntag'), t('Montag'), t('Dienstag'), t('Mittwoch'),
        t('Donnerstag'), t('Freitag'), t('Samstag'),
    ];
}

function metric_label(string $key): string
{
    return t(METRIC_LABELS[$key] ?? $key);
}
