<?php
declare(strict_types=1);

/* ==================================================================
 * Bootstrap: Config, Datenbank, Helpers, i18n, CSRF, Layout
 * Wird von public/index.php (Web) und seed.php (CLI) geladen.
 *
 * Sicherheit (Review-Fixes der Vorgängerversion):
 *  - SQLite liegt AUSSERHALB des Document Root (data/, nicht public/)
 *  - Webhook-Zustellung mit SSRF-Schutz (app/inc/api.php)
 *  - CSRF-Token auf allen Session-POST-Formularen
 * ================================================================== */

const ROOT_DIR = __DIR__ . '/..';

require_once __DIR__ . '/inc/policy.php';
require_once __DIR__ . '/inc/security.php';
safe_session_start();
if (PHP_SAPI!=='cli') {
    if (!is_dir(ROOT_DIR.'/data')) mkdir(ROOT_DIR.'/data',0700,true);
    $GLOBALS['maintenance_lock']=fopen(ROOT_DIR.'/data/.maintenance.lock','c');
    if (!$GLOBALS['maintenance_lock'] || !flock($GLOBALS['maintenance_lock'], LOCK_SH)) {
        http_response_code(503);exit('Maintenance in progress');
    }
}
set_exception_handler('application_exception');

/* ------------------------------------------------------------------ */
/* Konfiguration                                                       */
/* ------------------------------------------------------------------ */

function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $file = ROOT_DIR . '/config.php';
        $cfg = is_file($file) ? (require $file) : (require ROOT_DIR . '/config.example.php');
        if (!is_array($cfg)) {
            $cfg = [];
        }
    }
    if ($key === null) {
        return $cfg;
    }
    $cur = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return $default;
        }
        $cur = $cur[$part];
    }
    return $cur;
}

function base_url(): string
{
    $conf = rtrim((string)config('base_url', ''), '/');
    if ($conf !== '') {
        return $conf;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/* ------------------------------------------------------------------ */
/* Mehrsprachigkeit (i18n)                                             */
/* Neue Sprache: app/lang/xx.php anlegen (Kopie von de.php).          */
/* ------------------------------------------------------------------ */

const AVAILABLE_LANGS = ['de', 'en'];

function lang(): string
{
    if (isset($_GET['lang']) && in_array($_GET['lang'], AVAILABLE_LANGS, true)) {
        $_SESSION['lang'] = $_GET['lang'];
    }
    $l = $_SESSION['lang'] ?? (string)config('default_lang', 'de');
    return in_array($l, AVAILABLE_LANGS, true) ? $l : 'de';
}

/** Übersetzung: t('Deutscher Text') -> übersetzter Text. Fehlt der Key, kommt der deutsche Originaltext. */
function t(string $text, array $repl = []): string
{
    static $dict = null;
    $l = lang();
    if ($l !== 'de') {
        if ($dict === null) {
            $file = ROOT_DIR . '/app/lang/' . $l . '.php';
            $dict = is_file($file) ? (require $file) : [];
        }
        $text = $dict[$text] ?? $text;
    }
    foreach ($repl as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

function lang_switcher(): string
{
    $out = '<span class="lang-switch">';
    foreach (AVAILABLE_LANGS as $l) {
        $cls = $l === lang() ? ' class="active"' : '';
        $url = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $qs = $_GET;
        $qs['lang'] = $l;
        $out .= '<a' . $cls . ' href="' . h($url . '?' . http_build_query($qs)) . '">' . strtoupper($l) . '</a>';
    }
    return $out . '</span>';
}

/* ------------------------------------------------------------------ */
/* Datenbank                                                           */
/* ------------------------------------------------------------------ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $path = (string)config('db_path', ROOT_DIR . '/data/app.sqlite');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        init_schema($pdo);
        install_plan_guards($pdo);
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
    CREATE TABLE IF NOT EXISTS pass_devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_library_id TEXT NOT NULL,
        pass_type_id TEXT NOT NULL,
        serial TEXT NOT NULL,
        push_token TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE (device_library_id, pass_type_id, serial)
    );
    SQL);

    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS order_receipts (
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        reference TEXT NOT NULL,
        amount_cents INTEGER NOT NULL,
        points INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        PRIMARY KEY (business_id,reference)
    );
    CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY,window_start INTEGER NOT NULL,hits INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS platform_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        action TEXT NOT NULL,
        details TEXT NOT NULL DEFAULT '{}',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS subscriptions (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        stripe_customer_id TEXT UNIQUE,
        stripe_subscription_id TEXT UNIQUE,
        checkout_session_id TEXT,
        checkout_generation INTEGER NOT NULL DEFAULT 0,
        plan_key TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'none',
        cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
        current_period_end INTEGER,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS stripe_events (
        id TEXT PRIMARY KEY,
        event_type TEXT NOT NULL,
        processed_at TEXT NOT NULL DEFAULT (datetime('now'))
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
    ensure_column($pdo, 'businesses', 'card_design', 'TEXT');          // JSON: Farben, Logo, Felder
    ensure_column($pdo, 'businesses', 'shopify_secret', 'TEXT');       // App-Secret für Shopify-Webhooks
    ensure_column($pdo, 'businesses', 'points_per_cent', 'INTEGER');   // Auto-Punkte aus Shop-Bestellungen (0 = aus)
    $noKey = $pdo->query('SELECT id FROM businesses WHERE api_key IS NULL OR api_key = \'\'')->fetchAll();
    foreach ($noKey as $row) {
        $pdo->prepare('UPDATE businesses SET api_key = ? WHERE id = ?')
            ->execute([bin2hex(random_bytes(24)), $row['id']]);
    }
    /* Lifetime-Punkte fuer Bestandsdaten nachziehen */
    if (!$pdo->query("SELECT 1 FROM platform_settings WHERE key='migration_lifetime_v1'")->fetchColumn()) {
        $pdo->exec("UPDATE cards SET points_lifetime = points WHERE points_lifetime = 0 AND points > 0");
        $pdo->exec("INSERT INTO platform_settings (key,value) VALUES ('migration_lifetime_v1','1')");
    }
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

/* ------------------------------------------------------------------ */
/* CSRF-Schutz (alle Session-POST-Formulare)                           */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) {
        http_response_code(403);
        page('CSRF', '<div class="auth-box"><h1>' . t('Sicherheitsprüfung fehlgeschlagen') . '</h1>
            <p class="muted">' . t('Bitte lade die Seite neu und versuche es erneut.') . '</p></div>');
        exit;
    }
}

/* ------------------------------------------------------------------ */
/* Auth & Ownership                                                    */
/* ------------------------------------------------------------------ */

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

function type_label(string $type): string
{
    return t([
        'stamps'  => 'Stempelkarte',
        'points'  => 'Punkte & Prämien',
        'voucher' => 'Gutschein',
        'pass'    => 'Mehrfachkarte',
    ][$type] ?? $type);
}

/* ------------------------------------------------------------------ */
/* Karten-Design (pro Business, JSON in businesses.card_design)        */
/* ------------------------------------------------------------------ */

function design_defaults(): array
{
    return [
        'bg_color'     => '#1C1A17',   // Karten-Hintergrund
        'text_color'   => '#FAF7F2',   // Haupttext auf der Karte
        'accent_color' => '',          // leer = Markenfarbe
        'stamp_image'  => '',
        'logo'         => '',          // /uploads/... (PNG/JPEG/WebP)
        'header'       => '',          // Zeile über dem Namen (leer = Business-Name)
        'footer'       => '',          // kleine Zeile unten (z.B. Adresse/Webseite)
        'show_points'  => 1,
        'show_tier'    => 1,
        'qr_logo'      => 1,           // Logo in der Mitte des QR-Codes
    ];
}

function business_design(array $b): array
{
    $d = design_defaults();
    $raw = (string)($b['card_design'] ?? '');
    if ($raw !== '') {
        $extra = json_decode($raw, true);
        if (is_array($extra)) {
            $d = array_merge($d, array_intersect_key($extra, $d));
        }
    }
    if ($d['accent_color'] === '' || $d['accent_color'] === null) {
        $d['accent_color'] = $b['brand_color'] ?? '#E4572E';
    }
    foreach (['bg_color', 'text_color', 'accent_color'] as $c) {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$d[$c])) {
            $d[$c] = design_defaults()[$c] ?: '#1C1A17';
        }
    }
    return $d;
}

/** Öffentliche URL eines hochgeladenen Logos (wird über /uploads/ ausgeliefert). */
function upload_url(string $file): string
{
    return $file === '' ? '' : '/uploads/' . rawurlencode(basename($file));
}

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
            <a class="logo" href="/dashboard"><span class="logo-mark"></span>' . h(platform_setting('platform_name', 'Loyalty')) . '</a>
            <span class="nav-right">' . lang_switcher()
                . '<a class="nav-link" href="/billing">' . t('Abrechnung') . '</a>'
                . (is_platform_admin($user) ? '<a class="nav-link" href="/admin">Admin</a>' : '')
                . '<span class="nav-user">' . h($user['name']) . '</span>
                <a class="nav-link" href="/logout">' . t('Abmelden') . '</a></span>
        </nav>';
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo '<!DOCTYPE html><html lang="' . h(lang()) . '"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . h($title) . ' · Loyalty</title>
<link rel="stylesheet" href="/assets/style.css?v=2">
<script src="/assets/qrcode.min.js"></script>
<script src="/assets/app.js?v=2" defer></script>
<style>:root{--brand:' . h($brand) . '}</style>
</head><body>' . $nav . '<main class="wrap">' . $flashes . $body . '</main>
</body></html>';
}
