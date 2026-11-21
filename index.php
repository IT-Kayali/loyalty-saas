<?php
declare(strict_types=1);

session_start();

const DB_PATH = __DIR__ . '/data/app.sqlite';

/* ------------------------------------------------------------------ */
/* Datenbank                                                           */
/* ------------------------------------------------------------------ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        init_schema($pdo);
    }
    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS businesses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        brand_color TEXT NOT NULL DEFAULT '#E4572E',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS programs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        type TEXT NOT NULL CHECK (type IN ('stamps','points','voucher','pass')),
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        stamps_required INTEGER NOT NULL DEFAULT 6,
        reward_text TEXT NOT NULL DEFAULT '',
        voucher_value TEXT NOT NULL DEFAULT '',
        uses_per_customer INTEGER NOT NULL DEFAULT 1,
        pass_visits INTEGER NOT NULL DEFAULT 10,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS rewards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        points_cost INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        email TEXT NOT NULL DEFAULT '',
        code TEXT NOT NULL UNIQUE,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS cards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        stamps INTEGER NOT NULL DEFAULT 0,
        points INTEGER NOT NULL DEFAULT 0,
        pending_rewards INTEGER NOT NULL DEFAULT 0,
        uses_left INTEGER,
        visits_left INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE (program_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
        kind TEXT NOT NULL,
        amount INTEGER NOT NULL DEFAULT 0,
        note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS staff (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        pin_hash TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS tiers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        min_points INTEGER NOT NULL DEFAULT 0,
        benefit TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS bonus_hours (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
        weekday INTEGER NOT NULL,
        start_hour INTEGER NOT NULL,
        end_hour INTEGER NOT NULL,
        multiplier INTEGER NOT NULL DEFAULT 2
    );
    CREATE TABLE IF NOT EXISTS achievements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        metric TEXT NOT NULL,
        threshold INTEGER NOT NULL DEFAULT 10,
        reward_points INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS member_achievements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        achievement_id INTEGER NOT NULL REFERENCES achievements(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        unlocked_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE (achievement_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS member_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        kind TEXT NOT NULL DEFAULT 'info',
        text TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS segments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        rule TEXT NOT NULL,
        param INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        segment_id INTEGER NOT NULL REFERENCES segments(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        sent_at TEXT
    );
    CREATE TABLE IF NOT EXISTS campaign_sends (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        sent_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE (campaign_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS webhooks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        url TEXT NOT NULL,
        events TEXT NOT NULL,
        secret TEXT NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS webhook_deliveries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        webhook_id INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
        event TEXT NOT NULL,
        payload TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        response TEXT NOT NULL DEFAULT '',
        delivered_at TEXT
    );
    SQL);

    /* Migrationen für bestehende Datenbanken */
    ensure_column($pdo, 'members', 'password_hash', 'TEXT');
    ensure_column($pdo, 'businesses', 'join_code', 'TEXT');
    ensure_column($pdo, 'transactions', 'reversed_at', 'TEXT');
    ensure_column($pdo, 'cards', 'points_lifetime', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'businesses', 'referral_points_referrer', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'businesses', 'referral_points_referee', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'businesses', 'api_key', 'TEXT');
    $noKey = $pdo->query('SELECT id FROM businesses WHERE api_key IS NULL OR api_key = \'\'')->fetchAll();
    foreach ($noKey as $row) {
        $pdo->prepare('UPDATE businesses SET api_key = ? WHERE id = ?')
            ->execute([bin2hex(random_bytes(24)), $row['id']]);
    }
    /* Lifetime-Punkte fuer Bestandsdaten nachziehen */
    $pdo->exec("UPDATE cards SET points_lifetime = points WHERE points_lifetime = 0 AND points > 0");
    $noCode = $pdo->query('SELECT id FROM businesses WHERE join_code IS NULL OR join_code = \'\'')->fetchAll();
    foreach ($noCode as $row) {
        $pdo->prepare('UPDATE businesses SET join_code = ? WHERE id = ?')
            ->execute([strtoupper(bin2hex(random_bytes(4))), $row['id']]);
    }
}

function ensure_column(PDO $pdo, string $table, string $col, string $ddl): void
{
    $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (!in_array($col, $cols, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $ddl");
    }
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('/login');
    }
    return $u;
}

/** Prüft, ob das Business dem eingeloggten User gehört. */
function own_business(int $userId, int $businessId): ?array
{
    $stmt = db()->prepare('SELECT * FROM businesses WHERE id = ? AND user_id = ?');
    $stmt->execute([$businessId, $userId]);
    return $stmt->fetch() ?: null;
}

function own_program(int $userId, int $programId): ?array
{
    $stmt = db()->prepare(
        'SELECT p.* FROM programs p JOIN businesses b ON b.id = p.business_id
         WHERE p.id = ? AND b.user_id = ?'
    );
    $stmt->execute([$programId, $userId]);
    return $stmt->fetch() ?: null;
}

function own_member(int $userId, int $memberId): ?array
{
    $stmt = db()->prepare(
        'SELECT m.* FROM members m JOIN businesses b ON b.id = m.business_id
         WHERE m.id = ? AND b.user_id = ?'
    );
    $stmt->execute([$memberId, $userId]);
    return $stmt->fetch() ?: null;
}

function own_card(int $userId, int $cardId): ?array
{
    $stmt = db()->prepare(
        'SELECT c.*, p.type AS program_type, p.stamps_required, p.name AS program_name
         FROM cards c
         JOIN programs p ON p.id = c.program_id
         JOIN businesses b ON b.id = p.business_id
         WHERE c.id = ? AND b.user_id = ?'
    );
    $stmt->execute([$cardId, $userId]);
    return $stmt->fetch() ?: null;
}

function txn(int $cardId, string $kind, int $amount = 0, string $note = ''): void
{
    db()->prepare('INSERT INTO transactions (card_id, kind, amount, note) VALUES (?,?,?,?)')
        ->execute([$cardId, $kind, $amount, $note]);
}

function member_code(): string
{
    return strtoupper(bin2hex(random_bytes(5)));
}

const TYPE_LABELS = [
    'stamps'  => 'Stempelkarte',
    'points'  => 'Punkte & Prämien',
    'voucher' => 'Gutschein',
    'pass'    => 'Mehrfachkarte',
];

/* ------------------------------------------------------------------ */
/* Layout                                                              */
/* ------------------------------------------------------------------ */

function page(string $title, string $body, ?array $user = null, string $brand = '#E4572E'): void
{
    $flashes = '';
    foreach (take_flash() as $f) {
        $flashes .= '<div class="flash flash-' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
    $nav = '';
    if ($user) {
        $nav = '<nav class="topnav">
            <a class="logo" href="/dashboard">◆ Loyalty</a>
            <span class="nav-user">' . h($user['name']) . '</span>
            <a href="/logout">Abmelden</a>
        </nav>';
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . h($title) . ' · Loyalty</title>
<link rel="stylesheet" href="/assets/style.css">
<script src="/assets/qrcode.min.js"></script>
<style>:root{--brand:' . h($brand) . '}</style>
</head><body>' . $nav . '<main class="wrap">' . $flashes . $body . '</main>
</body></html>';
}

/* ------------------------------------------------------------------ */
/* Router                                                              */
/* ------------------------------------------------------------------ */

require_once __DIR__ . '/inc/roles.php';
require_once __DIR__ . '/inc/counter.php';
require_once __DIR__ . '/inc/tiers.php';
require_once __DIR__ . '/inc/engage.php';
require_once __DIR__ . '/inc/segments.php';
require_once __DIR__ . '/inc/api.php';
require_once __DIR__ . '/inc/analytics.php';

if (php_sapi_name() !== 'cli') {
/* Statische Dateien (z.B. /assets/style.css) direkt vom eingebauten Server ausliefern */
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}
$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

/* REST API: eigener JSON-Kanal ohne Session-Login */
if (str_starts_with($path, '/api/v1/')) {
    route_api($path, $method);
}

try {
    match (true) {
        $path === '/' => route_home(),
        $path === '/register' => route_register($method),
        $path === '/login' => route_login($method),
        $path === '/logout' => route_logout(),
        $path === '/dashboard' => route_dashboard(),
        $path === '/business/create' && $method === 'POST' => route_business_create(),
        preg_match('#^/business/(\d+)$#', $path, $m) === 1 => route_business((int)$m[1]),
        $path === '/program/create' && $method === 'POST' => route_program_create(),
        preg_match('#^/program/(\d+)$#', $path, $m) === 1 => route_program((int)$m[1]),
        $path === '/reward/create' && $method === 'POST' => route_reward_create(),
        $path === '/tier/create' && $method === 'POST' => route_tier_create(),
        $path === '/tier/delete' && $method === 'POST' => route_tier_delete(),
        $path === '/bonus/create' && $method === 'POST' => route_bonus_create(),
        $path === '/bonus/delete' && $method === 'POST' => route_bonus_delete(),
        $path === '/achievement/create' && $method === 'POST' => route_achievement_create(),
        $path === '/achievement/delete' && $method === 'POST' => route_achievement_delete(),
        $path === '/business/referral' && $method === 'POST' => route_business_referral(),
        $path === '/segment/create' && $method === 'POST' => route_segment_create(),
        $path === '/segment/delete' && $method === 'POST' => route_segment_delete(),
        $path === '/campaign/create' && $method === 'POST' => route_campaign_create(),
        $path === '/campaign/send' && $method === 'POST' => route_campaign_send(),
        $path === '/campaign/delete' && $method === 'POST' => route_campaign_delete(),
        $path === '/apikey/regenerate' && $method === 'POST' => route_apikey_regenerate(),
        $path === '/webhook/create' && $method === 'POST' => route_webhook_create(),
        $path === '/webhook/delete' && $method === 'POST' => route_webhook_delete(),
        preg_match('#^/business/(\d+)/analytics$#', $path, $m) === 1 => route_analytics((int)$m[1]),
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
    http_response_code(500);
    echo '<pre>Interner Fehler: ' . h($e->getMessage()) . '</pre>';
}
}

function not_found(): never
{
    http_response_code(404);
    page('Nicht gefunden', '<h1>404</h1><p>Seite nicht gefunden. <a href="/">Zur Startseite</a></p>');
    exit;
}

/* ------------------------------------------------------------------ */
/* Auth                                                                */
/* ------------------------------------------------------------------ */

function route_home(): never
{
    redirect(current_user() ? '/dashboard' : '/login');
}

function route_register(string $method): never
{
    if ($method === 'GET') {
        page('Registrieren', '
        <div class="auth-box">
          <h1>Konto erstellen</h1>
          <p class="muted">Verwalte Treueprogramme für deine Unternehmen.</p>
          <form method="post" action="/register" class="form">
            <label>Name <input name="name" required maxlength="80"></label>
            <label>E-Mail <input type="email" name="email" required maxlength="190"></label>
            <label>Passwort <input type="password" name="password" required minlength="8"></label>
            <button class="btn">Registrieren</button>
          </form>
          <p class="muted">Schon ein Konto? <a href="/login">Anmelden</a></p>
        </div>');
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        flash('Bitte Name, gültige E-Mail und ein Passwort mit mindestens 8 Zeichen angeben.', 'err');
        redirect('/register');
    }
    $exists = db()->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        flash('Diese E-Mail ist bereits registriert.', 'err');
        redirect('/register');
    }
    db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?,?,?)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $_SESSION['user_id'] = (int)db()->lastInsertId();
    flash('Willkommen! Lege jetzt dein erstes Unternehmen an.');
    redirect('/dashboard');
}

function route_login(string $method): never
{
    if ($method === 'GET') {
        page('Anmelden', '
        <div class="auth-box">
          <h1>◆ Loyalty</h1>
          <p class="muted">Digitale Stempelkarten, Punkte, Gutscheine und Mehrfachkarten für deine Kunden.</p>
          <form method="post" action="/login" class="form">
            <label>E-Mail <input type="email" name="email" required></label>
            <label>Passwort <input type="password" name="password" required></label>
            <button class="btn">Anmelden</button>
          </form>
          <p class="muted">Noch kein Konto? <a href="/register">Registrieren</a></p>
          <p class="muted"><a href="/staff/login">Staff-Login</a> · <a href="/member/login">Kunden-Login</a></p>
        </div>');
        exit;
    }
    $email = strtolower(trim($_POST['email'] ?? ''));
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
        flash('E-Mail oder Passwort falsch.', 'err');
        redirect('/login');
    }
    $_SESSION['user_id'] = (int)$u['id'];
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
            <span class="muted">' . (int)$b['programs'] . ' Programme · ' . (int)$b['members'] . ' Mitglieder</span>
        </a>';
    }
    if ($rows) {
        $cards = '<div class="grid">' . $cards . '</div>';
    } else {
        $cards = '<p class="muted">Noch keine Unternehmen. Lege dein erstes an.</p>';
    }

    page('Dashboard', '
      <h1>Deine Unternehmen</h1>
      ' . $cards . '
      <details class="panel" ' . ($rows ? '' : 'open') . '>
        <summary>Neues Unternehmen anlegen</summary>
        <form method="post" action="/business/create" class="form inline-form">
          <label>Name <input name="name" required maxlength="80" placeholder="Foundry Coffee"></label>
          <label>Markenfarbe <input type="color" name="brand_color" value="#E4572E"></label>
          <button class="btn">Anlegen</button>
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
        flash('Name fehlt.', 'err');
        redirect('/dashboard');
    }
    db()->prepare('INSERT INTO businesses (user_id, name, brand_color) VALUES (?,?,?)')
        ->execute([$u['id'], $name, $color]);
    flash('Unternehmen angelegt.');
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
          <span class="badge">' . h(TYPE_LABELS[$p['type']]) . '</span>
          <strong>' . h($p['name']) . '</strong>
          <span class="muted">' . h($p['description']) . '</span>
        </a>';
    }
    $plist = $plist ? '<div class="grid">' . $plist . '</div>' : '<p class="muted">Noch keine Programme.</p>';

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
    $staffList = $staffList ? '<ul>' . $staffList . '</ul>' : '<p class="muted">Noch keine Staff-Zugänge.</p>';

    $achQ = db()->prepare('SELECT * FROM achievements WHERE business_id = ? AND active = 1 ORDER BY threshold');
    $achQ->execute([$id]);
    $achList = '';
    foreach ($achQ->fetchAll() as $a) {
        $achList .= '<li>🏆 <strong>' . h($a['name']) . '</strong> - ' . h(METRIC_LABELS[$a['metric']] ?? $a['metric'])
            . ' ab ' . (int)$a['threshold']
            . ((int)$a['reward_points'] > 0 ? ' (+' . (int)$a['reward_points'] . ' Punkte)' : '')
            . ' <form method="post" action="/achievement/delete" style="display:inline">'
            . '<input type="hidden" name="business_id" value="' . $id . '">'
            . '<input type="hidden" name="achievement_id" value="' . (int)$a['id'] . '">'
            . '<button class="btn ghost small">Loeschen</button></form></li>';
    }
    $achList = $achList ? '<ul>' . $achList . '</ul>' : '<p class="muted">Noch keine Meilensteine.</p>';
    $metricOpts = '';
    foreach (METRIC_LABELS as $key => $label) {
        $metricOpts .= '<option value="' . $key . '">' . h($label) . '</option>';
    }
    $mlist = '';
    foreach ($members->fetchAll() as $m) {
        $mlist .= '<tr><td><a href="/member/' . (int)$m['id'] . '">' . h($m['name']) . '</a></td>
          <td class="muted">' . h($m['email']) . '</td>
          <td><code>' . h($m['code']) . '</code></td>
          <td><a class="btn small ghost" href="/c/' . h($m['code']) . '" target="_blank">Kundenkarte</a></td></tr>';
    }
    $mlist = $mlist
        ? '<table class="table"><thead><tr><th>Name</th><th>E-Mail</th><th>Code</th><th></th></tr></thead><tbody>' . $mlist . '</tbody></table>'
        : '<p class="muted">Noch keine Mitglieder.</p>';

    page($b['name'], '
      <p><a href="/dashboard" class="muted">← Alle Unternehmen</a></p>
      <h1>' . h($b['name']) . '</h1>
      <p><a class="btn ghost small" href="/business/' . $id . '/analytics">Analytics-Dashboard</a></p>

      <h2>Treueprogramme</h2>
      ' . $plist . '
      <details class="panel">
        <summary>Neues Programm anlegen</summary>
        <form method="post" action="/program/create" class="form" id="program-form">
          <input type="hidden" name="business_id" value="' . $id . '">
          <label>Typ
            <select name="type" id="type-select">
              <option value="stamps">Stempelkarte</option>
              <option value="points">Punkte &amp; Prämien</option>
              <option value="voucher">Gutschein</option>
              <option value="pass">Mehrfachkarte (Prepaid)</option>
            </select>
          </label>
          <label>Name <input name="name" required maxlength="80" placeholder="Dein nächster Kaffee"></label>
          <label>Beschreibung <input name="description" maxlength="190" placeholder="Sammle 6 Stempel. Verdiene einen Kaffee."></label>
          <label class="t stamps">Benötigte Stempel <input type="number" name="stamps_required" value="6" min="2" max="50"></label>
          <label class="t stamps">Belohnung <input name="reward_text" maxlength="120" placeholder="1 Kaffee gratis"></label>
          <label class="t voucher">Gutscheinwert <input name="voucher_value" maxlength="60" placeholder="20 % Rabatt"></label>
          <label class="t voucher">Nutzungen pro Kunde <input type="number" name="uses_per_customer" value="1" min="1" max="100"></label>
          <label class="t pass">Enthaltene Besuche <input type="number" name="pass_visits" value="10" min="1" max="500"></label>
          <button class="btn">Programm anlegen</button>
        </form>
      </details>

      <h2>Mitglieder</h2>
      <form method="get" action="/business/' . $id . '" class="form inline-form">
        <label>Kunde finden <input name="q" value="' . h($q) . '" placeholder="Name, E-Mail oder Code"></label>
        <button class="btn ghost">Suchen</button>
      </form>
      ' . $mlist . '
      <details class="panel">
        <summary>Neues Mitglied anlegen</summary>
        <form method="post" action="/member/create" class="form inline-form">
          <input type="hidden" name="business_id" value="' . $id . '">
          <label>Name <input name="name" required maxlength="80"></label>
          <label>E-Mail (optional) <input type="email" name="email" maxlength="190"></label>
          <label>Login-Passwort (optional) <input type="password" name="password" minlength="8" autocomplete="new-password"></label>
          <button class="btn">Anlegen</button>
        </form>
      </details>

      <h2>Team (Staff)</h2>
      ' . $staffList . '
      <details class="panel">
        <summary>Staff-Zugang anlegen</summary>
        <form method="post" action="/staff/create" class="form inline-form">
          <input type="hidden" name="business_id" value="' . $id . '">
          <label>Name <input name="name" required maxlength="80"></label>
          <label>PIN <input type="password" name="pin" required minlength="4" maxlength="20"></label>
          <button class="btn">Anlegen</button>
        </form>
      </details>

      <h2>Meilensteine (Achievements)</h2>
      ' . $achList . '
      <details class="panel">
        <summary>Neuen Meilenstein anlegen</summary>
        <form method="post" action="/achievement/create" class="form inline-form">
          <input type="hidden" name="business_id" value="' . $id . '">
          <label>Name <input name="name" required maxlength="80" placeholder="Stammkunde"></label>
          <label>Messgroesse <select name="metric">' . $metricOpts . '</select></label>
          <label>Schwelle <input type="number" name="threshold" value="10" min="1" max="100000"></label>
          <label>Bonus-Punkte (optional) <input type="number" name="reward_points" value="0" min="0" max="100000"></label>
          <button class="btn">Anlegen</button>
        </form>
      </details>

      <h2>Freunde werben (Referrals)</h2>
      <p class="muted">Bonus-Punkte, wenn ein bestehender Kunde einen neuen Kunden wirbt (Beitritt ueber seinen persoenlichen Link im Wallet).</p>
      <form method="post" action="/business/referral" class="form inline-form">
        <input type="hidden" name="business_id" value="' . $id . '">
        <label>Punkte fuer den Werber <input type="number" name="referrer" value="' . (int)($b['referral_points_referrer'] ?? 0) . '" min="0" max="100000"></label>
        <label>Punkte fuer den Neukunden <input type="number" name="referee" value="' . (int)($b['referral_points_referee'] ?? 0) . '" min="0" max="100000"></label>
        <button class="btn ghost">Speichern</button>
      </form>
      <h2>API &amp; Webhooks</h2>
      ' . api_html($b) . '

      <h2>Kunden-Segmente</h2>
      ' . segments_html($id) . '

      <h2>E-Mail-Kampagnen</h2>
      <p class="muted">Kampagnen gehen an ein Segment. Ohne SMTP-Anbindung erfolgt die Zustellung lokal in die Wallet-Inbox der Mitglieder (inkl. Versand-Protokoll).</p>
      ' . campaigns_html($id) . '
      <p class="muted">Staff-Portal: <a href="/staff/login?b=' . $id . '">/staff/login?b=' . $id . '</a> · Kunden-Beitritt: <a href="/join/' . h($b['join_code'] ?? '') . '" target="_blank">/join/' . h($b['join_code'] ?? '') . '</a></p>
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
    if (!$b || !isset(TYPE_LABELS[$type]) || trim($_POST['name'] ?? '') === '') {
        flash('Ungültige Eingabe.', 'err');
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
    flash('Programm angelegt.');
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
      <h1><span class="badge">' . h(TYPE_LABELS[$p['type']]) . '</span> ' . h($p['name']) . '</h1>
      <p class="muted">' . h($p['description']) . '</p>';

    if ($p['type'] === 'stamps') {
        $body .= '<p>Ziel: <strong>' . (int)$p['stamps_required'] . ' Stempel</strong> · Belohnung: <strong>' . h($p['reward_text']) . '</strong></p>';
    } elseif ($p['type'] === 'voucher') {
        $body .= '<p>Wert: <strong>' . h($p['voucher_value']) . '</strong> · Nutzungen pro Kunde: <strong>' . (int)$p['uses_per_customer'] . '</strong></p>';
    } elseif ($p['type'] === 'pass') {
        $body .= '<p>Paket: <strong>' . (int)$p['pass_visits'] . ' Besuche</strong> (Bezahlung erfolgt im Geschäft)</p>';
    }

    if ($p['type'] === 'points') {
        $rewards = db()->prepare('SELECT * FROM rewards WHERE program_id = ? ORDER BY points_cost');
        $rewards->execute([$id]);
        $rlist = '';
        foreach ($rewards->fetchAll() as $r) {
            $rlist .= '<li>' . h($r['name']) . ' <span class="muted">· ' . (int)$r['points_cost'] . ' Punkte</span></li>';
        }
        $body .= '<h2>Prämien</h2>' . ($rlist ? '<ul>' . $rlist . '</ul>' : '<p class="muted">Noch keine Prämien.</p>') . '
        <form method="post" action="/reward/create" class="form inline-form panel">
          <input type="hidden" name="program_id" value="' . $id . '">
          <label>Prämie <input name="name" required maxlength="120" placeholder="Maniküre gratis"></label>
          <label>Punktekosten <input type="number" name="points_cost" value="100" min="1" max="100000"></label>
          <button class="btn">Hinzufügen</button>
        </form>';

        /* Membership Tiers */
        $tiers = db()->prepare('SELECT * FROM tiers WHERE program_id = ? ORDER BY min_points');
        $tiers->execute([$id]);
        $tlist = '';
        foreach ($tiers->fetchAll() as $t) {
            $tlist .= '<li><strong>' . h($t['name']) . '</strong> <span class="muted">ab ' . (int)$t['min_points'] . ' Punkten' . ($t['benefit'] ? ' · ' . h($t['benefit']) : '') . '</span>
              <form method="post" action="/tier/delete" class="undo-form"><input type="hidden" name="program_id" value="' . $id . '"><button class="undo-btn" name="tier_id" value="' . (int)$t['id'] . '" title="Loeschen">✕</button></form></li>';
        }
        $body .= '<h2>Mitgliedsstufen (Tiers)</h2>' . ($tlist ? '<ul>' . $tlist . '</ul>' : '<p class="muted">Noch keine Stufen.</p>') . '
        <form method="post" action="/tier/create" class="form inline-form panel">
          <input type="hidden" name="program_id" value="' . $id . '">
          <label>Stufe <input name="name" required maxlength="40" placeholder="Gold"></label>
          <label>Ab Punkten (Lifetime) <input type="number" name="min_points" value="500" min="0" max="1000000"></label>
          <label>Vorteil <input name="benefit" maxlength="120" placeholder="10 % auf alles"></label>
          <button class="btn">Hinzufügen</button>
        </form>';

        /* Bonus Hours */
        $wdNames = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
        $bonus = db()->prepare('SELECT * FROM bonus_hours WHERE program_id = ? ORDER BY weekday, start_hour');
        $bonus->execute([$id]);
        $blist = '';
        foreach ($bonus->fetchAll() as $bh) {
            $blist .= '<li>' . $wdNames[(int)$bh['weekday']] . ' ' . (int)$bh['start_hour'] . '-' . (int)$bh['end_hour'] . ' Uhr: <strong>x' . (int)$bh['multiplier'] . ' Punkte</strong>
              <form method="post" action="/bonus/delete" class="undo-form"><input type="hidden" name="program_id" value="' . $id . '"><button class="undo-btn" name="bonus_id" value="' . (int)$bh['id'] . '" title="Loeschen">✕</button></form></li>';
        }
        $wdOpts = '';
        foreach ($wdNames as $i => $n) {
            $wdOpts .= '<option value="' . $i . '">' . $n . '</option>';
        }
        $body .= '<h2>Bonus-Zeiten</h2>' . ($blist ? '<ul>' . $blist . '</ul>' : '<p class="muted">Keine Bonus-Zeiten. Punkte-Multiplikator in ruhigen Stunden.</p>') . '
        <form method="post" action="/bonus/create" class="form inline-form panel">
          <input type="hidden" name="program_id" value="' . $id . '">
          <label>Wochentag <select name="weekday">' . $wdOpts . '</select></label>
          <label>Von <input type="number" name="start_hour" value="14" min="0" max="23"></label>
          <label>Bis <input type="number" name="end_hour" value="16" min="1" max="24"></label>
          <label>Multiplikator <input type="number" name="multiplier" value="2" min="2" max="10"></label>
          <button class="btn">Hinzufügen</button>
        </form>';
    }

    $cards = db()->prepare(
        'SELECT c.*, m.name AS member_name, m.code FROM cards c JOIN members m ON m.id = c.member_id
         WHERE c.program_id = ? ORDER BY c.created_at DESC LIMIT 100'
    );
    $cards->execute([$id]);
    $rows = '';
    foreach ($cards->fetchAll() as $c) {
        $state = match ($p['type']) {
            'stamps' => $c['stamps'] . '/' . (int)$p['stamps_required'] . ' Stempel' . ($c['pending_rewards'] ? ' · ' . $c['pending_rewards'] . ' Belohnung offen' : ''),
            'points' => $c['points'] . ' Punkte',
            'voucher' => ($c['uses_left'] ?? 0) . ' Nutzungen übrig',
            'pass' => ($c['visits_left'] ?? 0) . ' Besuche übrig',
        };
        $rows .= '<tr><td><a href="/member/' . (int)$c['member_id'] . '">' . h($c['member_name']) . '</a></td><td>' . h($state) . '</td></tr>';
    }
    $body .= '<h2>Ausgestellte Karten</h2>' . ($rows
        ? '<table class="table"><thead><tr><th>Mitglied</th><th>Stand</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        : '<p class="muted">Noch keine Karten ausgestellt. Öffne ein Mitglied und stelle eine Karte aus.</p>');

    page($p['name'], $body, $u, $b['brand_color']);
    exit;
}

function route_reward_create(): never
{
    $u = require_login();
    $p = own_program((int)$u['id'], (int)($_POST['program_id'] ?? 0));
    if (!$p || $p['type'] !== 'points' || trim($_POST['name'] ?? '') === '') {
        flash('Ungültige Eingabe.', 'err');
        redirect('/dashboard');
    }
    db()->prepare('INSERT INTO rewards (program_id, name, points_cost) VALUES (?,?,?)')
        ->execute([$p['id'], trim($_POST['name']), max(1, (int)$_POST['points_cost'])]);
    flash('Prämie hinzugefügt.');
    redirect('/program/' . (int)$p['id']);
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
        flash('Ungültige Eingabe.', 'err');
        redirect('/dashboard');
    }
    $pw = (string)($_POST['password'] ?? '');
    $pwHash = strlen($pw) >= 8 ? password_hash($pw, PASSWORD_DEFAULT) : null;
    db()->prepare('INSERT INTO members (business_id, name, email, code, password_hash) VALUES (?,?,?,?,?)')
        ->execute([$b['id'], $name, trim($_POST['email'] ?? ''), member_code(), $pwHash]);
    flash('Mitglied angelegt.' . ($pwHash ? ' Kunden-Login ist aktiv.' : ''));
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
      <p class="muted">' . h($m['email']) . ' · Kundenkarte: <a href="/c/' . h($m['code']) . '" target="_blank">/c/' . h($m['code']) . '</a></p>'
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
        $body .= '<h2><span class="badge">' . h(TYPE_LABELS[$p['type']]) . '</span> ' . h($p['name']) . '</h2>';

        if (!$card) {
            $body .= '<form method="post" action="/card/issue">
              <input type="hidden" name="member_id" value="' . $id . '">
              <input type="hidden" name="program_id" value="' . (int)$p['id'] . '">
              <button class="btn ghost">Karte ausstellen</button></form></section>';
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
              <p><strong>' . (int)$card['stamps'] . ' / ' . $req . '</strong> Stempel · Offene Belohnungen: <strong>' . (int)$card['pending_rewards'] . '</strong></p>
              <form method="post" action="' . $actionUrl . '" class="btn-row">
                <button class="btn" name="action" value="stamp">+ Stempel</button>
                ' . ($card['pending_rewards'] > 0
                    ? '<button class="btn accent" name="action" value="redeem_stamp_reward">Belohnung einlösen</button>' : '') . '
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
            $body .= '<p class="big-number">' . (int)$card['points'] . ' <span>Punkte</span></p>
              ' . tier_html((int)$p['id'], (int)($card['points_lifetime'] ?? 0)) . '
              <form method="post" action="' . $actionUrl . '" class="form inline-form">
                <input type="number" name="amount" value="10" min="1" max="10000" style="width:6rem">
                <button class="btn" name="action" value="points_add">Punkte gutschreiben</button>
              </form>
              ' . ($opts ? '<form method="post" action="' . $actionUrl . '" class="btn-row">' . $opts . '</form>' : '');
        } elseif ($p['type'] === 'voucher') {
            $body .= '<p class="big-number">' . (int)($card['uses_left'] ?? 0) . ' <span>Nutzungen übrig</span></p>
              <p class="muted">' . h($p['voucher_value']) . '</p>
              ' . (($card['uses_left'] ?? 0) > 0
                  ? '<form method="post" action="' . $actionUrl . '"><button class="btn accent" name="action" value="voucher_use">Gutschein einlösen</button></form>'
                  : '<p class="muted">Keine Nutzungen mehr.</p>');
        } elseif ($p['type'] === 'pass') {
            $body .= '<p class="big-number">' . (int)($card['visits_left'] ?? 0) . ' <span>Besuche übrig</span></p>
              ' . (($card['visits_left'] ?? 0) > 0
                  ? '<form method="post" action="' . $actionUrl . '"><button class="btn" name="action" value="visit">Besuch bestätigen</button></form>'
                  : '<p class="muted">Karte aufgebraucht.</p>');
        }

        $txnStmt->execute([$cid]);
        $txns = $txnStmt->fetchAll();
        if ($txns) {
            $body .= '<details class="txlog"><summary>Verlauf</summary><ul>';
            foreach ($txns as $t) {
                $body .= '<li><span class="muted">' . h($t['created_at']) . '</span> ' . h($t['note']);
                if ($t['reversed_at'] ?? null) {
                    $body .= ' <span class="muted">(storniert)</span>';
                } elseif (!in_array($t['kind'], ['reversal', 'reward_earned'], true)) {
                    $body .= ' <form method="post" action="' . $actionBase . '/' . $cid . '/undo" class="undo-form" onsubmit="return confirm(\'Diese Aktion stornieren?\')"><button class="undo-btn" name="txn_id" value="' . (int)$t['id'] . '" title="Stornieren">↩</button></form>';
                }
                $body .= '</li>';
            }
            $body .= '</ul></details>';
        }
        $body .= '</section>';
    }
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
    flash('Karte ausgestellt.');
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
    $db->beginTransaction();

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
            txn($cardId, 'stamp_add', 1, '+1 Stempel (' . $stamps . '/' . $req . ')');
            if ($earned) {
                txn($cardId, 'reward_earned', 1, 'Belohnung verdient: ' . $card['program_name']);
                flash('Stempel vergeben – Belohnung verdient! 🎉');
            } else {
                flash('Stempel vergeben.');
            }
            break;

        case $action === 'redeem_stamp_reward' && $card['program_type'] === 'stamps' && $card['pending_rewards'] > 0:
            $db->prepare('UPDATE cards SET pending_rewards = pending_rewards - 1 WHERE id = ?')->execute([$cardId]);
            txn($cardId, 'reward_redeem', 1, 'Belohnung eingelöst');
            flash('Belohnung eingelöst.');
            break;

        case $action === 'points_add' && $card['program_type'] === 'points':
            $base = max(1, min(10000, (int)($_POST['amount'] ?? 0)));
            $mult = points_multiplier((int)$card['program_id']);
            $amount = $base * $mult;
            $db->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
                ->execute([$amount, $amount, $cardId]);
            txn($cardId, 'points_add', $amount, '+' . $amount . ' Punkte' . ($mult > 1 ? ' (Bonus-Zeit x' . $mult . ')' : ''));
            flash('+' . $amount . ' Punkte' . ($mult > 1 ? ' - Bonus-Zeit x' . $mult . ' aktiv!' : '') . '.');
            break;

        case str_starts_with($action, 'redeem_reward:') && $card['program_type'] === 'points':
            $rewardId = (int)substr($action, strlen('redeem_reward:'));
            $r = $db->prepare('SELECT * FROM rewards WHERE id = ? AND program_id = ?');
            $r->execute([$rewardId, $card['program_id']]);
            $reward = $r->fetch();
            if (!$reward || $card['points'] < $reward['points_cost']) {
                flash('Nicht genug Punkte.', 'err');
                break;
            }
            $db->prepare('UPDATE cards SET points = points - ? WHERE id = ?')->execute([$reward['points_cost'], $cardId]);
            txn($cardId, 'points_redeem', (int)$reward['points_cost'], 'Eingelöst: ' . $reward['name'] . ' (−' . (int)$reward['points_cost'] . ' Punkte)');
            flash('Prämie eingelöst: ' . $reward['name']);
            break;

        case $action === 'voucher_use' && $card['program_type'] === 'voucher' && ($card['uses_left'] ?? 0) > 0:
            $db->prepare('UPDATE cards SET uses_left = uses_left - 1 WHERE id = ?')->execute([$cardId]);
            txn($cardId, 'voucher_use', 1, 'Gutschein eingelöst');
            flash('Gutschein eingelöst.');
            break;

        case $action === 'visit' && $card['program_type'] === 'pass' && ($card['visits_left'] ?? 0) > 0:
            $db->prepare('UPDATE cards SET visits_left = visits_left - 1 WHERE id = ?')->execute([$cardId]);
            txn($cardId, 'visit', 1, 'Besuch bestätigt');
            flash('Besuch bestätigt.');
            break;

        default:
            flash('Aktion nicht möglich.', 'err');
    }

    $db->commit();

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
    }
}

/* ------------------------------------------------------------------ */
/* Öffentliche Kundenkarte                                             */
/* ------------------------------------------------------------------ */

function route_public_card(string $code): never
{
    $stmt = db()->prepare(
        'SELECT m.*, b.name AS business_name, b.brand_color FROM members m
         JOIN businesses b ON b.id = m.business_id WHERE m.code = ?'
    );
    $stmt->execute([$code]);
    $m = $stmt->fetch();
    if (!$m) {
        not_found();
    }

    $firstName = explode(' ', trim($m['name']))[0];
    $body = '<div class="member-view">
      <p class="brand-tag">' . h($m['business_name']) . '</p>
      <h1>Hallo, ' . h($firstName) . '!</h1>';

    $cardsHtml = wallet_cards_html((int)$m['id']);
    $hasCards = $cardsHtml !== '';
    $body .= $cardsHtml;

    if (!$hasCards) {
        $body .= '<p class="muted">Noch keine Karten. Zeige diesen Code beim nächsten Besuch.</p>';
    }

    $cardUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/c/' . $m['code'];
    $body .= '<div class="qr-wrap"><div id="qr"></div><p class="muted">Code: <code>' . h($m['code']) . '</code></p></div>
    </div>
    <script>
    if (window.QRCode) {
      new QRCode(document.getElementById("qr"), {text: ' . json_encode($cardUrl) . ', width: 140, height: 140});
    }
    </script>';

    page($m['business_name'] . ' Karte', $body, null, $m['brand_color']);
    exit;
}
