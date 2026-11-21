<?php
/* ==================================================================
 * Loyalty SaaS - Konfigurationsvorlage
 * Kopiere diese Datei nach config.php und passe die Werte an.
 * config.php ist in .gitignore und wird nie ins Repo übernommen.
 * ================================================================== */

return [
    /* ---- Basis ---- */
    // Basis-URL der Installation OHNE Slash am Ende.
    // Wird für Wallet-Pässe, QR-Codes und Webhooks benutzt.
    'base_url' => 'http://127.0.0.1:8099',

    // Standardsprache der Oberfläche: 'de' oder 'en'.
    // Besucher können per ?lang= umschalten (wird in der Session gemerkt).
    'default_lang' => 'de',

    // Pfad zur SQLite-Datei. Standard: data/app.sqlite (AUSSERHALB von public/).
    'db_path' => __DIR__ . '/data/app.sqlite',

    // Upload-Verzeichnis für Logos (AUSSERHALB von public/).
    'upload_dir' => __DIR__ . '/data/uploads',

    /* ---- Sicherheit ---- */
    // Webhooks per http:// zulassen? Nur für lokale Entwicklung true setzen.
    'webhook_allow_http' => false,

    // Explicit allowlist. Never grant admin privileges just because an account registered first.
    'platform_admin_emails' => [],

    /* ---- Stripe: SaaS subscriptions (docs/STRIPE.md) ---- */
    'stripe' => [
        'secret_key' => getenv('STRIPE_SECRET_KEY') ?: '',
        'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: '',
        // Off by default: enabling affects creation of new businesses. Existing cards stay accessible.
        'enforce_limits' => false,
        'plans' => [
            'starter' => ['name' => 'Starter', 'price_id' => getenv('STRIPE_PRICE_STARTER') ?: '',
                'description' => 'Für ein einzelnes Unternehmen.', 'limits' => ['businesses' => 1]],
            'pro' => ['name' => 'Pro', 'price_id' => getenv('STRIPE_PRICE_PRO') ?: '',
                'description' => 'Für mehrere Unternehmen.', 'limits' => ['businesses' => 10]],
        ],
    ],

    /* ---- Apple Wallet (PassKit) ----------------------------------
     * Setup-Anleitung: docs/WALLET.md
     * Ohne diese Werte antwortet der Apple-Pass-Endpunkt mit HTTP 501.
     * -------------------------------------------------------------- */
    'apple_wallet' => [
        'team_id'        => '',          // Apple Developer Team-ID (10 Zeichen)
        'pass_type_id'   => '',          // z.B. pass.de.example.loyalty
        'organization'   => '',          // Name deiner Firma (steht auf dem Pass)
        // Pass-Signatur-Zertifikat (aus Keychain als .p12 exportiert,
        // dann per openssl in .pem zerlegt - siehe docs/WALLET.md):
        'cert_path'      => __DIR__ . '/data/apple/pass_cert.pem',
        'key_path'       => __DIR__ . '/data/apple/pass_key.pem',
        'key_password'   => '',          // Passwort des privaten Schlüssels (falls gesetzt)
        'wwdr_path'      => __DIR__ . '/data/apple/wwdr.pem',
        // APNs-Push (für Pass-Updates als Mitteilung auf dem Sperrbildschirm):
        'apns_key_id'    => '',          // 10-stellige Key-ID aus dem Developer-Portal
        'apns_auth_key'  => __DIR__ . '/data/apple/AuthKey.p8',
        'apns_topic'     => '',          // = pass_type_id
        'apns_sandbox'   => false,       // true = api.sandbox.push.apple.com
    ],

    /* ---- Google Wallet -------------------------------------------
     * Setup-Anleitung: docs/WALLET.md
     * Ohne diese Werte antwortet der Google-Pass-Endpunkt mit HTTP 501.
     * -------------------------------------------------------------- */
    'google_wallet' => [
        'issuer_id'     => '',           // Issuer-ID aus der Google Wallet Console
        'class_suffix'  => 'loyalty',    // Klassen-ID wird: {issuer_id}.loyalty
        // Service-Account-JSON (aus Google Cloud Console):
        'service_account_json' => __DIR__ . '/data/google/service-account.json',
    ],

    /* ---- E-Mail (Kampagnen, optional) ------------------------------
     * Leer lassen = Zustellung lokal in die Wallet-Inbox.
     * -------------------------------------------------------------- */
    'smtp' => [
        'host' => '', 'port' => 587, 'user' => '', 'pass' => '',
        'from' => '', 'from_name' => '',
    ],
];
