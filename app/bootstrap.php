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
require_once __DIR__ . '/inc/wallet-auth.php';
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
        try {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            init_schema($pdo);
            install_plan_guards($pdo);
        } catch (Throwable $e) {
            // A caught migration error must not expose a partially initialized connection.
            $pdo = null;
            throw $e;
        }
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
    CREATE TABLE IF NOT EXISTS member_wallet_auth (
        member_id INTEGER PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
        auth_token TEXT NOT NULL CHECK(length(auth_token)=64 AND auth_token NOT GLOB '*[^0-9a-f]*'),
        image_token TEXT NOT NULL CHECK(length(image_token)=64 AND image_token NOT GLOB '*[^0-9a-f]*'),
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TRIGGER IF NOT EXISTS member_wallet_auth_immutable
    BEFORE UPDATE OF member_id,auth_token,image_token ON member_wallet_auth
    WHEN NEW.member_id<>OLD.member_id OR NEW.auth_token<>OLD.auth_token OR NEW.image_token<>OLD.image_token
    BEGIN SELECT RAISE(ABORT,'Pass authentication is immutable'); END;
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
    CREATE TABLE IF NOT EXISTS networks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS locations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        address TEXT NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS mail_outbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER REFERENCES businesses(id) ON DELETE CASCADE,
        member_id INTEGER REFERENCES members(id) ON DELETE SET NULL,
        recipient TEXT NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        category TEXT NOT NULL CHECK(category IN ('marketing','transactional')),
        dedup_key TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'queued',
        attempts INTEGER NOT NULL DEFAULT 0,
        claimed_at TEXT,
        sent_at TEXT,
        last_error TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS mail_automations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        event TEXT NOT NULL,
        days INTEGER NOT NULL DEFAULT 30,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        kind TEXT NOT NULL CHECK(kind IN ('owner','member')),
        token_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS prepaid_issues (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
        program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE RESTRICT,
        member_id INTEGER REFERENCES members(id) ON DELETE SET NULL,
        code TEXT NOT NULL UNIQUE,
        operation TEXT NOT NULL,
        receipt TEXT NOT NULL,
        name TEXT NOT NULL,
        kind TEXT NOT NULL CHECK(kind IN ('visits','unlimited')),
        remaining INTEGER,
        original_visits INTEGER,
        paid_cents INTEGER NOT NULL CHECK(paid_cents>=0),
        currency TEXT NOT NULL,
        expires_at TEXT,
        terms TEXT NOT NULL,
        actor_kind TEXT NOT NULL,
        actor_id INTEGER NOT NULL,
        version INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(business_id,operation),
        UNIQUE(business_id,receipt)
    );
    CREATE TABLE IF NOT EXISTS prepaid_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        issue_id INTEGER NOT NULL REFERENCES prepaid_issues(id) ON DELETE RESTRICT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE RESTRICT,
        kind TEXT NOT NULL CHECK(kind IN ('sale','visit','reversal')),
        quantity INTEGER NOT NULL,
        amount_cents INTEGER NOT NULL DEFAULT 0,
        operation TEXT NOT NULL,
        actor_kind TEXT NOT NULL,
        actor_id INTEGER NOT NULL,
        reverses_id INTEGER UNIQUE REFERENCES prepaid_ledger(id),
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(issue_id,operation)
    );
    CREATE TRIGGER IF NOT EXISTS prepaid_ledger_no_update BEFORE UPDATE ON prepaid_ledger BEGIN SELECT RAISE(ABORT,'Ledger is append-only'); END;
    CREATE TRIGGER IF NOT EXISTS prepaid_ledger_no_delete BEFORE DELETE ON prepaid_ledger BEGIN SELECT RAISE(ABORT,'Ledger is append-only'); END;
    CREATE TABLE IF NOT EXISTS voucher_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
        member_id INTEGER REFERENCES members(id) ON DELETE CASCADE,
        batch TEXT NOT NULL,
        code TEXT NOT NULL UNIQUE,
        rules_snapshot TEXT NOT NULL,
        used INTEGER NOT NULL DEFAULT 0,
        max_uses INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS voucher_redemptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
        program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE,
        code_id INTEGER NOT NULL REFERENCES voucher_codes(id),
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        operation TEXT NOT NULL,
        spend_cents INTEGER NOT NULL,
        discount_cents INTEGER NOT NULL,
        actor_kind TEXT NOT NULL,
        actor_id INTEGER NOT NULL,
        snapshot TEXT NOT NULL,
        reversed_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE (business_id,operation)
    );
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
    CREATE TABLE IF NOT EXISTS maintenance_status (
        task TEXT PRIMARY KEY CHECK(task IN ('mail_worker','points_expiry','webhook_retry')),
        run_token TEXT NOT NULL,
        started_at TEXT NOT NULL,
        finished_at TEXT,
        state TEXT NOT NULL CHECK(state IN ('started','completed','failed'))
    );
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
    ensure_column($pdo, 'members', 'marketing_consent', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'members', 'consent_at', 'TEXT');
    ensure_column($pdo, 'members', 'consent_source', 'TEXT');
    ensure_column($pdo, 'members', 'birthday', 'TEXT');
    ensure_column($pdo, 'members', 'session_version', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'session_version', 'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets(id INTEGER PRIMARY KEY AUTOINCREMENT,reference TEXT NOT NULL UNIQUE,user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,channel TEXT NOT NULL CHECK(channel IN ('partner','contact')),requester_name TEXT NOT NULL,requester_email TEXT NOT NULL,topic TEXT NOT NULL,subject TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','awaiting','closed')),priority TEXT NOT NULL DEFAULT 'normal' CHECK(priority IN ('low','normal','high')),assignee INTEGER REFERENCES users(id) ON DELETE SET NULL,version INTEGER NOT NULL DEFAULT 1,deleted_at TEXT,created_at TEXT NOT NULL DEFAULT (datetime('now')),updated_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_id INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,internal INTEGER NOT NULL DEFAULT 0 CHECK(internal IN (0,1)),body TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_operations(operation TEXT PRIMARY KEY,actor TEXT NOT NULL,request_hash TEXT NOT NULL,ticket_id INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS support_owner ON support_tickets(user_id,updated_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_keys(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,name TEXT NOT NULL,prefix TEXT NOT NULL,key_hash TEXT NOT NULL UNIQUE,scope TEXT NOT NULL CHECK(scope IN ('read','write')),expires_at TEXT NOT NULL,revoked_at TEXT,last_used_at TEXT,created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_requests(id TEXT PRIMARY KEY,key_id INTEGER NOT NULL REFERENCES ai_keys(id) ON DELETE CASCADE,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,tool TEXT NOT NULL,args TEXT NOT NULL,summary TEXT NOT NULL,touches TEXT NOT NULL,fingerprint TEXT NOT NULL,nonce_hash TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','expired','running','done','failed')),result TEXT,expires_at TEXT NOT NULL,decided_at TEXT,created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_calls(key_id INTEGER NOT NULL REFERENCES ai_keys(id) ON DELETE CASCADE,idempotency_key TEXT NOT NULL,request_hash TEXT NOT NULL,state TEXT NOT NULL,response TEXT,created_at TEXT NOT NULL DEFAULT (datetime('now')),PRIMARY KEY(key_id,idempotency_key))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS merchant_operations(business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,member_id INTEGER REFERENCES members(id) ON DELETE SET NULL,operation TEXT NOT NULL,request_hash TEXT NOT NULL,response TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT (datetime('now')),PRIMARY KEY(business_id,operation))");
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS merchant_erase BEFORE DELETE ON members BEGIN UPDATE merchant_operations SET request_hash='erased',response='{\"ok\":true,\"deleted\":true}',member_id=NULL WHERE member_id=OLD.id; END");
    $pdo->exec("CREATE TABLE IF NOT EXISTS paypal_attempts(request_id TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,plan_key TEXT NOT NULL,plan_id TEXT NOT NULL,custom_id TEXT NOT NULL UNIQUE,subscription_id TEXT UNIQUE,finished INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS paypal_sales(sale_id TEXT PRIMARY KEY,subscription_id TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS paypal_events(id TEXT PRIMARY KEY,event_type TEXT NOT NULL,processed_at TEXT NOT NULL DEFAULT (datetime('now')))");
    ensure_column($pdo, 'subscriptions', 'provider', "TEXT NOT NULL DEFAULT 'stripe'");
    ensure_column($pdo, 'subscriptions', 'paypal_subscription_id', 'TEXT');
    ensure_column($pdo, 'subscriptions', 'paypal_request_id', 'TEXT');
    ensure_column($pdo, 'subscriptions', 'paypal_payment_hold', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'businesses', 'network_id', 'INTEGER REFERENCES networks(id)');
    ensure_column($pdo, 'locations', 'wallet_latitude', 'REAL');
    ensure_column($pdo, 'locations', 'wallet_longitude', 'REAL');
    ensure_column($pdo, 'locations', 'wallet_nearby', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'businesses', 'wallet_nearby_notice', "TEXT NOT NULL DEFAULT '{}'");
    ensure_column($pdo, 'members', 'wallet_nearby_consent', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'members', 'wallet_nearby_granted_at', 'TEXT');
    ensure_column($pdo, 'members', 'wallet_nearby_withdrawn_at', 'TEXT');
    ensure_column($pdo, 'members', 'wallet_nearby_notice_hash', "TEXT NOT NULL DEFAULT ''");
    ensure_column($pdo, 'members', 'wallet_nearby_snapshot', "TEXT NOT NULL DEFAULT '{}'");
    ensure_column($pdo, 'businesses', 'wallet_push_settings', "TEXT NOT NULL DEFAULT '{}'");
    $walletColumns = array_column($pdo->query('PRAGMA table_info(pass_devices)')->fetchAll(), 'name');
    $walletTables = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('apple_pass_clock','apple_pass_versions')")->fetchColumn();
    if (!in_array('registration_revision', $walletColumns, true) || !in_array('registration_generation', $walletColumns, true) || !in_array('registered_at_ms', $walletColumns, true) || $walletTables !== 2) {
        // First concurrent requests must not race ALTER TABLE against each other.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            ensure_column($pdo, 'pass_devices', 'registration_revision', 'INTEGER NOT NULL DEFAULT 0');
            ensure_column($pdo, 'pass_devices', 'registration_generation', 'INTEGER NOT NULL DEFAULT 0');
            ensure_column($pdo, 'pass_devices', 'registered_at_ms', 'INTEGER NOT NULL DEFAULT 0');
            $pdo->exec("CREATE TABLE IF NOT EXISTS apple_pass_clock(id INTEGER PRIMARY KEY CHECK(id=1),generation TEXT NOT NULL,revision INTEGER NOT NULL DEFAULT 0 CHECK(revision>=0))");
            $pdo->prepare('INSERT OR IGNORE INTO apple_pass_clock(id,generation,revision)VALUES(1,?,0)')->execute([bin2hex(random_bytes(32))]);
            $pdo->exec("CREATE TABLE IF NOT EXISTS apple_pass_versions(member_id INTEGER PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,content_hash TEXT NOT NULL,revision INTEGER NOT NULL UNIQUE,modified_at INTEGER NOT NULL,date_highwater INTEGER NOT NULL,date_safe INTEGER NOT NULL DEFAULT 0 CHECK(date_safe IN (0,1)))");
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
    }
    ensure_column($pdo, 'businesses', 'wallet_push_notice_revision', 'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_push_choices(member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,provider TEXT NOT NULL CHECK(provider IN ('apple','google')),enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)),revision INTEGER NOT NULL DEFAULT 0 CHECK(revision>=0),granted_at TEXT,withdrawn_at TEXT,notice_hash TEXT NOT NULL DEFAULT '',snapshot TEXT NOT NULL DEFAULT '{}',PRIMARY KEY(member_id,provider))");
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS wallet_push_business_name AFTER UPDATE OF name ON businesses WHEN NEW.name<>OLD.name BEGIN UPDATE businesses SET wallet_push_notice_revision=wallet_push_notice_revision+1 WHERE id=NEW.id; END");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_push_deliveries(id TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,provider TEXT NOT NULL CHECK(provider IN ('apple','google')),header TEXT NOT NULL,body TEXT NOT NULL,content_hash TEXT NOT NULL,expires_at TEXT NOT NULL,notice_hash TEXT NOT NULL,choice_revision INTEGER NOT NULL,state TEXT NOT NULL CHECK(state IN ('processing','accepted','partial','rejected','unknown')),published INTEGER NOT NULL DEFAULT 0 CHECK(published IN (0,1)),outcome TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL DEFAULT (datetime('now')),finished_at TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS wallet_push_delivery_quota ON wallet_push_deliveries(member_id,provider,created_at)");
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS wallet_push_delivery_tenant BEFORE INSERT ON wallet_push_deliveries WHEN NOT EXISTS(SELECT 1 FROM members m JOIN businesses b ON b.id=m.business_id WHERE m.id=NEW.member_id AND b.id=NEW.business_id AND b.user_id=NEW.user_id) BEGIN SELECT RAISE(ABORT,'Wallet message tenant mismatch'); END");
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS wallet_push_delivery_immutable BEFORE UPDATE ON wallet_push_deliveries WHEN NEW.id<>OLD.id OR NEW.user_id<>OLD.user_id OR NEW.business_id<>OLD.business_id OR NEW.member_id<>OLD.member_id OR NEW.provider<>OLD.provider OR NEW.header<>OLD.header OR NEW.body<>OLD.body OR NEW.content_hash<>OLD.content_hash OR NEW.expires_at<>OLD.expires_at OR NEW.notice_hash<>OLD.notice_hash OR NEW.choice_revision<>OLD.choice_revision OR NEW.published<>OLD.published OR NEW.created_at<>OLD.created_at BEGIN SELECT RAISE(ABORT,'Wallet message identity immutable'); END");
    ensure_column($pdo, 'staff', 'location_id', 'INTEGER REFERENCES locations(id)');
    ensure_column($pdo, 'staff', 'role', "TEXT NOT NULL DEFAULT 'supervisor'");
    ensure_column($pdo, 'staff', 'permissions', 'TEXT');
    ensure_column($pdo, 'staff', 'session_version', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'transactions', 'staff_id', 'INTEGER REFERENCES staff(id)');
    ensure_column($pdo, 'transactions', 'location_id', 'INTEGER REFERENCES locations(id)');
    ensure_column($pdo, 'prepaid_issues', 'location_id', 'INTEGER REFERENCES locations(id)');
    ensure_column($pdo, 'prepaid_ledger', 'location_id', 'INTEGER REFERENCES locations(id)');
    ensure_column($pdo, 'voucher_redemptions', 'location_id', 'INTEGER REFERENCES locations(id)');
    // Tenant relations are enforced even for direct SQL, not only owner forms.
    foreach(['INSERT','UPDATE'] as $op) {
        $suffix=strtolower($op);
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS network_owner_$suffix BEFORE $op ON businesses WHEN NEW.network_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM networks n WHERE n.id=NEW.network_id AND n.user_id=NEW.user_id) BEGIN SELECT RAISE(ABORT,'Network owner mismatch'); END");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS staff_location_$suffix BEFORE $op ON staff WHEN NEW.location_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM locations l WHERE l.id=NEW.location_id AND l.business_id=NEW.business_id) BEGIN SELECT RAISE(ABORT,'Staff location mismatch'); END");
    }
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS network_owner_immutable BEFORE UPDATE OF user_id ON networks WHEN NEW.user_id<>OLD.user_id BEGIN SELECT RAISE(ABORT,'Network ownership cannot change'); END");
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS location_business_immutable BEFORE UPDATE OF business_id ON locations WHEN NEW.business_id<>OLD.business_id BEGIN SELECT RAISE(ABORT,'Location business cannot change'); END");
    ensure_column($pdo, 'programs', 'card_design', 'TEXT');
    ensure_column($pdo, 'programs', 'voucher_rules', 'TEXT');
    ensure_column($pdo, 'programs', 'pass_kind', "TEXT NOT NULL DEFAULT 'visits'");
    ensure_column($pdo, 'programs', 'pass_valid_days', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'programs', 'pass_price_cents', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'programs', 'pass_currency', "TEXT NOT NULL DEFAULT 'EUR'");
    ensure_column($pdo, 'businesses', 'join_code', 'TEXT');
    ensure_column($pdo, 'transactions', 'reversed_at', 'TEXT');
    ensure_column($pdo, 'mail_automations', 'voucher_program_id', 'INTEGER');
    ensure_column($pdo, 'mail_automations', 'channel', "TEXT NOT NULL DEFAULT 'email'");
    $pdo->exec('CREATE TABLE IF NOT EXISTS automation_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, dedup_key TEXT NOT NULL UNIQUE, rule_id INTEGER NOT NULL, member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE, channel TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')))');
    ensure_column($pdo, 'mail_automations', 'voucher_days', 'INTEGER NOT NULL DEFAULT 30');
    ensure_column($pdo, 'programs', 'points_transfer', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'programs', 'transfer_out_daily', 'INTEGER NOT NULL DEFAULT 500');
    ensure_column($pdo, 'programs', 'transfer_in_daily', 'INTEGER NOT NULL DEFAULT 1000');
    ensure_column($pdo, 'webhook_deliveries', 'attempts', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'webhook_deliveries', 'next_attempt_at', 'TEXT');
    ensure_column($pdo, 'businesses', 'timezone', "TEXT NOT NULL DEFAULT 'UTC'");
    ensure_column($pdo, 'rewards', 'valid_from', 'TEXT');
    ensure_column($pdo, 'rewards', 'valid_until', 'TEXT');
    ensure_column($pdo, 'programs', 'claim_minutes', 'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_claims (id INTEGER PRIMARY KEY AUTOINCREMENT, card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE, reward_id INTEGER, reward_name TEXT NOT NULL, points_cost INTEGER NOT NULL, code TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','used','cancelled')), expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')), used_at TEXT, used_by TEXT)");
    ensure_column($pdo, 'webhook_deliveries', 'owner_ack', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'businesses', 'webhook_owner_mail', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'ai_keys', 'tool_groups', 'TEXT');
    ensure_column($pdo, 'ai_keys', 'requests_per_minute', 'INTEGER NOT NULL DEFAULT 60');
    ensure_column($pdo, 'ai_keys', 'business_id', 'INTEGER');
    ensure_column($pdo, 'webhook_deliveries', 'lock_until', 'TEXT');
    ensure_column($pdo, 'webhook_deliveries', 'dead_ack', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'programs', 'transfer_count_daily', 'INTEGER NOT NULL DEFAULT 5');
    $pdo->exec("CREATE TABLE IF NOT EXISTS point_request_links (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE, program_id INTEGER NOT NULL REFERENCES programs(id) ON DELETE CASCADE, token TEXT NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1, uses INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS point_transfers (id INTEGER PRIMARY KEY AUTOINCREMENT, op TEXT NOT NULL UNIQUE, link_id INTEGER, program_id INTEGER NOT NULL, from_card INTEGER NOT NULL, to_card INTEGER NOT NULL, amount INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    ensure_column($pdo, 'programs', 'points_expire_days', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'cards', 'points_lifetime', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'businesses', 'referral_points_referrer', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'businesses', 'referral_points_referee', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'businesses', 'api_key', 'TEXT');
    ensure_column($pdo, 'businesses', 'card_design', 'TEXT');          // JSON: Farben, Logo, Felder
    ensure_column($pdo, 'businesses', 'shopify_secret', 'TEXT');       // App-Secret für Shopify-Webhooks
    ensure_column($pdo, 'businesses', 'points_per_cent', 'INTEGER');   // Auto-Punkte aus Shop-Bestellungen (0 = aus)
    wallet_auth_migrate($pdo);
    wallet_auth_fill_empty_business_keys($pdo);
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
    $u=$stmt->fetch();
    if($u && (int)($u['session_version'] ?? 0)!==(int)($_SESSION['user_session_version'] ?? 0)){unset($_SESSION['user_id']);return null;}
    return $u ?: null;
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

function txn(int $cardId, string $kind, int $amount = 0, string $note = '', ?array $actor = null): void
{
    $staff=PHP_SAPI!=='cli' && str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH) ?: '', '/staff/')?current_staff():null;
    if($actor!==null)$staff=$actor['kind']==='staff'?$actor:null;
    db()->prepare('INSERT INTO transactions (card_id, kind, amount, note,staff_id,location_id) VALUES (?,?,?,?,?,?)')
        ->execute([$cardId,$kind,$amount,$note,$staff['id'] ?? null,$staff['location_id'] ?? null]);
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

/** Erlaubte Stempel-Icons (Programmdesign und Vorlagen). */
function stamp_icons(): array { return ['','⭐','☕','🍕','🎁','✂️','💎','🏆','🌿','🍗','🥙','🍞','🍦','🧼','💅','🍝','🍺']; }

function design_defaults(): array
{
    return [
        'bg_color'     => '#1C1A17',   // Karten-Hintergrund
        'text_color'   => '#FAF7F2',   // Haupttext auf der Karte
        'accent_color' => '',          // leer = Markenfarbe
        'stamp_image'  => '',
        'background_image' => '',
        'overlay_opacity' => 75,
        'stamp_icon' => '',
        'logo'         => '',          // /uploads/... (PNG/JPEG/WebP)
        'header'       => '',          // Zeile über dem Namen (leer = Business-Name)
        'footer'       => '',          // kleine Zeile unten (z.B. Adresse/Webseite)
        'show_points'  => 1,
        'show_tier'    => 1,
        'qr_logo'      => 1,           // Logo in der Mitte des QR-Codes
        'layout'       => [],          // gestaltete Karte, siehe app/inc/cardlayout.php
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
    $d['overlay_opacity']=max(0,min(100,(int)$d['overlay_opacity']));
    if(!in_array($d['stamp_icon'],stamp_icons(),true))$d['stamp_icon']='';
    return $d;
}

function program_design(array $program,array $business): array
{
    if(!empty($program['card_design']))return business_design(['card_design'=>$program['card_design'],'brand_color'=>$business['brand_color'] ?? '#E4572E']);
    return business_design($business);
}

function card_face_style(array $d): string
{
    $style='--card-bg:'.$d['bg_color'].';--card-text:'.$d['text_color'].';--brand:'.$d['accent_color'].';';
    if($d['background_image']!=='') {
        $rgb=sscanf($d['bg_color'],'#%02x%02x%02x');$overlay='rgba('.implode(',',$rgb).','.($d['overlay_opacity']/100).')';
        $style.='background-image:linear-gradient('.$overlay.','.$overlay.'),url('.upload_url($d['background_image']).');background-size:cover;background-position:center;';
    }
    return $style;
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
                . '<a class="nav-link" href="/organization">Organisation</a>'
                . '<a class="nav-link" href="/support">Support</a>'
                . (config('ai_enabled',false) ? '<a class="nav-link" href="/ai">KI</a>' : '')
                . '<a class="nav-link" href="/billing">' . t('Abrechnung') . '</a>'
                . (is_platform_admin($user) ? '<a class="nav-link" href="/admin">Admin</a>' : '')
                . '<span class="nav-user">' . h($user['name']) . '</span>
                <a class="nav-link" href="/account/password">' . t('Passwort') . '</a><a class="nav-link" href="/logout">' . t('Abmelden') . '</a></span>
        </nav>';
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo '<!DOCTYPE html><html lang="' . h(lang()) . '"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="/manifest.webmanifest"><meta name="theme-color" content="#E4572E"><link rel="apple-touch-icon" href="/assets/pwa-192.png">
<title>' . h($title) . ' · Loyalty</title>
<link rel="stylesheet" href="/assets/style.css?v=2">
<script src="/assets/qrcode.min.js"></script>
<script src="/assets/app.js?v=2" defer></script>
<script src="/assets/pwa.js" defer></script>
<style>:root{--brand:' . h($brand) . '}</style>
</head><body data-member-account="' . h((function_exists('current_member_email') && ($memberEmail=current_member_email()))?hash('sha256',strtolower($memberEmail)):'') . '">' . $nav . '<main class="wrap">' . $flashes . $body . '</main>
</body></html>';
}

/** Public seeded credentials, shown only on an explicitly disposable demo. */
function demo_login_hint(): string
{
    if(getenv('DEMO_MODE')!=='1' && config('demo_banner',false)!==true)return '';
    return '<aside class="demo-hint"><h2>Demo-Zugänge</h2><p>Nur Testdaten eingeben. Keine echten Kundendaten oder Zahlungen. Änderungen können von anderen Besuchern gesehen und zurückgesetzt werden.</p><dl><dt><a href="/login">Betreiber</a></dt><dd><code>demo@loyalty.local</code><br>Passwort: <code>demo1234</code></dd><dt><a href="/staff/login?b=1">Theke / Staff</a></dt><dd>Business-Nr. <code>1</code>, Name <code>Lena</code><br>PIN: <code>4321</code></dd><dt><a href="/member/login">Mitglied</a></dt><dd><code>aylin@example.com</code><br>Passwort: <code>kunde1234</code></dd></dl></aside>';
}
