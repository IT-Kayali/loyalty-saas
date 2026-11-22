<?php
declare(strict_types=1);

/* ==================================================================
 * Apple Wallet (pkpass) + Google Wallet
 * Setup: docs/WALLET.md - Zertifikate/Zugangsdaten kommen in config.php
 *
 * Apple: signierter .pkpass-Download + PassKit-Web-Service
 *        (Registrierung der Geraete, Update-Pushes ueber APNs)
 * Google: "Add to Google Wallet"-JWT-Link + Objekt-Updates ueber die
 *         Wallet-REST-API (Service Account)
 * ================================================================== */

/* ------------------------------------------------------------------ */
/* Gemeinsame Pass-Daten                                               */
/* ------------------------------------------------------------------ */

/** Mitglied + Business + Karten zu einem Member-Code. */
function pass_member(string $code): ?array
{
    $stmt = db()->prepare('SELECT m.*, b.name AS business_name, b.brand_color, b.card_design, b.api_key
        FROM members m JOIN businesses b ON b.id = m.business_id WHERE m.code = ?');
    $stmt->execute([$code]);
    $m = $stmt->fetch();
    if (!$m) {
        return null;
    }
    $cards = db()->prepare('SELECT c.*, p.type, p.name AS program_name, p.stamps_required, p.reward_text, p.voucher_value,p.card_design
        FROM cards c JOIN programs p ON p.id = c.program_id
        WHERE c.member_id = ? AND p.active = 1 ORDER BY
          CASE p.type WHEN \'stamps\' THEN 0 WHEN \'points\' THEN 1 ELSE 2 END, c.id');
    $cards->execute([$m['id']]);
    $m['cards'] = $cards->fetchAll();
    $m['design'] = isset($m['cards'][0]) ? program_design($m['cards'][0],$m) : business_design($m);
    return $m;
}

/** Einmaliges, stabiles Auth-Token fuer den PassKit-Web-Service. */
function pass_auth_token(array $m): string
{
    return hash_hmac('sha256', 'pass:' . $m['code'], (string)$m['api_key']);
}

/** Hex #rrggbb -> "rgb(r, g, b)" (Apple-Format). */
function apple_rgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    return 'rgb(' . hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2)) . ')';
}

/** Wallet payload language is issuer-configured, never taken from a viewer session. */
function wallet_t(string $text): string
{
    static $dictionaries = [];
    $language = (string)config('default_lang', 'de');
    if (!in_array($language, AVAILABLE_LANGS, true) || $language === 'de') return $text;
    if (!isset($dictionaries[$language])) $dictionaries[$language] = require ROOT_DIR . '/app/lang/' . $language . '.php';
    return $dictionaries[$language][$text] ?? $text;
}

/** Primaerkarte und Zahlenstand fuer die Pass-Vorderseite. */
function pass_balance(array $m): array
{
    foreach ($m['cards'] as $c) {
        if ($c['type'] === 'stamps') {
            return ['label' => wallet_t('Stempel'), 'value' => (int)$c['stamps'] . ' / ' . (int)$c['stamps_required']];
        }
        if ($c['type'] === 'points') {
            return ['label' => wallet_t('Punkte'), 'value' => (string)(int)$c['points']];
        }
    }
    $c = $m['cards'][0] ?? null;
    if ($c && $c['type'] === 'voucher') {
        return ['label' => wallet_t('Gutschein'), 'value' => (int)($c['uses_left'] ?? 0) . 'x'];
    }
    if ($c && $c['type'] === 'pass') {
        return ['label' => wallet_t('Besuche'), 'value' => (string)(int)($c['visits_left'] ?? 0)];
    }
    return ['label' => wallet_t('Karte'), 'value' => '–'];
}

/* ------------------------------------------------------------------ */
/* Pass-Bilder (GD): Standard-Icon/Logo aus Design-Farben              */
/* ------------------------------------------------------------------ */

function pass_png(string $kind, array $design, string $letter, string $logoUpload = ''): string
{
    [$w, $h] = match ($kind) {
        'icon'   => [58, 58],
        'icon2x' => [116, 116],
        default  => [320, 100],   // logo / logo@2x
    };
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);

    if ($logoUpload !== '' && str_starts_with($kind, 'logo')) {
        $srcPath = (string)config('upload_dir', ROOT_DIR . '/data/uploads') . '/' . basename($logoUpload);
        if (is_file($srcPath)) {
            $src = imagecreatefromstring((string)file_get_contents($srcPath));
            if ($src) {
                $sw = imagesx($src);
                $sh = imagesy($src);
                $scale = min($w / $sw, $h / $sh);
                $dw = max(1, (int)($sw * $scale));
                $dh = max(1, (int)($sh * $scale));
                imagealphablending($img, true);
                imagecopyresampled($img, $src, (int)(($w - $dw) / 2), (int)(($h - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
                imagedestroy($src);
                ob_start();
                imagepng($img);
                imagedestroy($img);
                return (string)ob_get_clean();
            }
        }
    }

    $accent = sscanf(ltrim($design['accent_color'], '#'), '%02x%02x%02x');
    $fg = sscanf(ltrim($design['bg_color'], '#'), '%02x%02x%02x');
    $colorBg = imagecolorallocatealpha($img, $accent[0], $accent[1], $accent[2], 0);
    $colorFg = imagecolorallocate($img, $fg[0], $fg[1], $fg[2]);
    imagealphablending($img, true);
    imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $colorBg);
    $size = min($w, $h) * 0.55;
    $font = 5;
    $tw = imagefontwidth($font) * strlen($letter);
    $th = imagefontheight($font);
    /* Groesser wirkt per Skalierung auf eine Bitmap */
    $tmp = imagecreatetruecolor(60, 60);
    imagealphablending($tmp, false);
    imagesavealpha($tmp, true);
    imagefill($tmp, 0, 0, $transparent);
    imagealphablending($tmp, true);
    imagestring($tmp, 5, (int)((60 - $tw * 2) / 2), (int)((60 - $th * 2) / 2), $letter, $colorFg);
    imagecopyresampled($img, $tmp, (int)(($w - $size) / 2), (int)(($h - $size) / 2), 0, 0, (int)$size, (int)$size, 60, 60);
    imagedestroy($tmp);
    ob_start();
    imagepng($img);
    imagedestroy($img);
    return (string)ob_get_clean();
}

/* ------------------------------------------------------------------ */
/* ZIP ohne ext-zip (deterministisch, Deflate via zlib)                */
/* ------------------------------------------------------------------ */

function zip_build(array $files): string
{
    $out = '';
    $central = '';
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $comp = gzdeflate($data, 9);
        $nlen = strlen($name);
        $out .= "PK\x03\x04" . pack('v5V3v2',
            20, 0, 8, 0, 0,
            $crc, strlen($comp), strlen($data), $nlen, 0
        ) . $name . $comp;
        $central .= "PK\x01\x02" . pack('v6V3v5V2',
            20, 20, 0, 8, 0, 0,
            $crc, strlen($comp), strlen($data),
            $nlen, 0, 0, 0, 0, 0, strlen($out) - strlen($comp) - 30 - $nlen
        ) . $name;
    }
    return $out . $central . "PK\x05\x06" . pack('v4V2v', 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
}

/* ------------------------------------------------------------------ */
/* Apple Wallet                                                        */
/* ------------------------------------------------------------------ */

function apple_wallet_ready(): bool
{
    return (string)config('apple_wallet.pass_type_id', '') !== ''
        && is_file((string)config('apple_wallet.cert_path', ''))
        && is_file((string)config('apple_wallet.key_path', ''))
        && is_file((string)config('apple_wallet.wwdr_path', ''));
}

function apple_push_ready(): bool
{
    return (string)config('apple_wallet.apns_key_id', '') !== ''
        && is_file((string)config('apple_wallet.apns_auth_key', ''))
        && (string)config('apple_wallet.apns_topic', '') !== '';
}

function apple_pass_json(array $m): array
{
    $d = $m['design'];
    $balance = pass_balance($m);
    $secondary = [];
    foreach (array_slice($m['cards'], 0, 3) as $c) {
        $val = match ($c['type']) {
            'stamps'  => (int)$c['stamps'] . '/' . (int)$c['stamps_required'] . ' ' . wallet_t('Stempel'),
            'points'  => (int)$c['points'] . ' ' . wallet_t('Punkte'),
            'voucher' => (int)($c['uses_left'] ?? 0) . 'x ' . wallet_t('Gutschein'),
            default   => (int)($c['visits_left'] ?? 0) . ' ' . wallet_t('Besuche'),
        };
        $secondary[] = ['key' => 'prog' . (int)$c['program_id'], 'label' => mb_strtoupper($c['program_name']), 'value' => $val];
    }
    return [
        'locations' => wallet_member_locations($m),
        'formatVersion' => 1,
        'passTypeIdentifier' => config('apple_wallet.pass_type_id'),
        'serialNumber' => $m['code'],
        'teamIdentifier' => config('apple_wallet.team_id'),
        'organizationName' => config('apple_wallet.organization', '') !== '' ? config('apple_wallet.organization') : $m['business_name'],
        'description' => $m['business_name'] . ' ' . wallet_t('Kundenkarte'),
        'logoText' => $d['header'] !== '' ? $d['header'] : $m['business_name'],
        'foregroundColor' => apple_rgb($d['text_color']),
        'backgroundColor' => apple_rgb($d['bg_color']),
        'labelColor' => apple_rgb($d['text_color']),
        'storeCard' => [
            'primaryFields' => [[
                'key' => 'balance',
                'label' => mb_strtoupper($balance['label']),
                'value' => $balance['value'],
                'changeMessage' => wallet_t('Neuer Stand: %@'),
            ]],
            'secondaryFields' => $secondary,
            'backFields' => [wallet_message_apple_field($m)],
            'auxiliaryFields' => [[
                'key' => 'member',
                'label' => mb_strtoupper(wallet_t('Mitglied')),
                'value' => $m['name'],
            ]],
        ],
        'barcode' => [
            'format' => 'PKBarcodeFormatQR',
            'message' => base_url() . '/c/' . $m['code'],
            'messageEncoding' => 'iso-8859-1',
        ],
        'webServiceURL' => base_url() . '/api/pass/',
        'authenticationToken' => pass_auth_token($m),
    ];
}

/** Baut das signierte .pkpass-Bundle (ZIP). */
function apple_pkpass(array $m,?array $payload=null): string
{
    $passJson = json_encode($payload ?? apple_pass_json($m), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $letter = mb_strtoupper(mb_substr($m['business_name'], 0, 1));
    $files = [
        'pass.json'    => $passJson,
        'icon.png'     => pass_png('icon', $m['design'], $letter),
        'icon@2x.png'  => pass_png('icon2x', $m['design'], $letter),
        'logo.png'     => pass_png('logo', $m['design'], $letter, (string)$m['design']['logo']),
        'logo@2x.png'  => pass_png('logo2x', $m['design'], $letter, (string)$m['design']['logo']),
    ];
    if ($m['design']['stamp_image'] !== '') {
        $files['strip.png'] = stamp_strip_png($m);
        $files['strip@2x.png'] = stamp_strip_png($m, 2);
    }
    $manifest = [];
    foreach ($files as $name => $data) {
        $manifest[$name] = sha1($data);
    }
    $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES);

    $tmpDir = sys_get_temp_dir() . '/pkpass_' . bin2hex(random_bytes(6));
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/manifest.json', $manifestJson);

    $ok = openssl_pkcs7_sign(
        $tmpDir . '/manifest.json',
        $tmpDir . '/signature',
        'file://' . config('apple_wallet.cert_path'),
        ['file://' . config('apple_wallet.key_path'), (string)config('apple_wallet.key_password', '')],
        [],
        PKCS7_BINARY | PKCS7_DETACHED,
        (string)config('apple_wallet.wwdr_path')
    );
    if (!$ok) {
        throw new RuntimeException('PKCS#7-Signatur fehlgeschlagen (Zertifikat/Schluessel/WWDR pruefen)');
    }
    /* Apple will die reine DER-Signatur: aus der S/MIME-Multipart-Ausgabe
       den application/x-pkcs7-signature-Teil extrahieren (PHP nutzt \n, nicht \r\n) */
    $smime = str_replace("\r\n", "\n", (string)file_get_contents($tmpDir . '/signature'));
    if (!preg_match('/application\/x-pkcs7-signature;?[^\n]*\n(?:[^\n]*\n)*?\n([A-Za-z0-9+\/=\n]+)\n--/s', $smime, $sm)
        || ($der = base64_decode(str_replace("\n", '', $sm[1]), true)) === false
        || !str_starts_with($der, "\x30")) {
        throw new RuntimeException('PKCS#7-Signatur konnte nicht extrahiert werden');
    }
    $files['manifest.json'] = $manifestJson;
    $files['signature'] = $der;
    array_map('unlink', glob($tmpDir . '/*'));
    rmdir($tmpDir);
    return zip_build($files);
}

function route_pass_apple(string $code): never
{
    $m = pass_member($code);
    if (!$m) {
        not_found();
    }
    if (!apple_wallet_ready()) {
        http_response_code(501);
        page('Apple Wallet', '<div class="auth-box"><h1>Apple Wallet</h1>
          <p class="muted">' . t('Apple Wallet ist auf dieser Installation noch nicht eingerichtet.') . '</p>
          <p class="muted">' . t('Betreiber: Zertifikate und IDs in config.php hinterlegen - Anleitung in docs/WALLET.md.') . '</p></div>');
        exit;
    }
    apple_pass_response($m);
}

/* ------------------------------------------------------------------ */
/* Apple PassKit Web Service (Registrierung + Updates)                 */
/* ------------------------------------------------------------------ */

function pass_service_auth(string $serial): void
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('#^ApplePass\s+(\S+)$#', $auth, $m)) {
        http_response_code(401);
        exit;
    }
    $member = pass_member($serial);
    if (!$member || !hash_equals(pass_auth_token($member), $m[1])) {
        http_response_code(401);
        exit;
    }
}

function route_pass_service(string $path, string $method): never
{
    header('Content-Type: application/json');

    /* Registrierung: POST/DELETE /api/pass/v1/devices/{dev}/registrations/{type}/{serial} */
    if (preg_match('#^/api/pass/v1/devices/([\w.-]+)/registrations/([\w.-]+)/([A-F0-9]{10})$#', $path, $m)) {
        [, $device, $passType, $serial] = $m;
        if ($passType !== (string)config('apple_wallet.pass_type_id', '')) {
            http_response_code(404);
            exit;
        }
        pass_service_auth($serial);
        if ($method === 'POST') {
            $input = json_decode((string)file_get_contents('php://input'), true);
            $token = is_array($input) ? ($input['pushToken'] ?? null) : null;
            if (!is_string($token) || !preg_match('/^[\x21-\x7E]{1,512}$/D', $token)) {
                http_response_code(400);
                exit;
            }
            $found = apple_pass_register($device, $passType, $serial, $token);
            http_response_code($found ? 200 : 201);
            exit;
        }
        if ($method === 'DELETE') {
            db()->prepare('DELETE FROM pass_devices WHERE device_library_id = ? AND pass_type_id = ? AND serial = ?')
                ->execute([$device, $passType, $serial]);
            http_response_code(200);
            exit;
        }
    }

    /* Serien mit Updates: GET /api/pass/v1/devices/{dev}/registrations/{type} */
    if (preg_match('#^/api/pass/v1/devices/([\w.-]+)/registrations/([\w.-]+)$#', $path, $m) && $method === 'GET') {
        if ($m[2] !== (string)config('apple_wallet.pass_type_id', '')) { http_response_code(404); exit; }
        $since = $_GET['passesUpdatedSince'] ?? null;
        if ($since !== null && !is_string($since)) { http_response_code(400); exit; }
        header('Cache-Control: private, no-store');
        $changes = apple_pass_changes($m[1], $m[2], $since);
        if ($changes === null) { http_response_code(204); exit; }
        echo json_encode($changes);
        exit;
    }

    /* Aktueller Pass: GET /api/pass/v1/passes/{type}/{serial} */
    if (preg_match('#^/api/pass/v1/passes/([\w.-]+)/([A-F0-9]{10})$#', $path, $m) && $method === 'GET') {
        [, $passType, $serial] = $m;
        if ($passType !== (string)config('apple_wallet.pass_type_id', '')) {
            http_response_code(404);
            exit;
        }
        pass_service_auth($serial);
        $member = pass_member($serial);
        if (!$member) { not_found(); }
        if (!apple_wallet_ready()) { http_response_code(501); exit; }
        apple_pass_response($member,true);
    }

    /* Log-Endpunkt (darf alles annehmen) */
    if ($path === '/api/pass/v1/log' && $method === 'POST') {
        http_response_code(200);
        exit;
    }

    http_response_code(404);
    exit;
}

/* ------------------------------------------------------------------ */
/* APNs: Update-Push an registrierte Geraete                           */
/* ------------------------------------------------------------------ */

function apns_jwt(): string
{
    $key = (string)file_get_contents((string)config('apple_wallet.apns_auth_key'));
    $header = json_encode(['alg' => 'ES256', 'kid' => config('apple_wallet.apns_key_id')]);
    $claims = json_encode(['iss' => config('apple_wallet.team_id'), 'iat' => time()]);
    $data = rtrim(strtr(base64_encode($header), '+/', '-_'), '=') . '.'
        . rtrim(strtr(base64_encode($claims), '+/', '-_'), '=');
    openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256);
    /* DER -> raw r||s */
    $sig = asn1_to_raw_ecdsa($sig);
    return $data . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
}

function asn1_to_raw_ecdsa(string $der): string
{
    if (ord($der[0]) !== 0x30) {
        return $der;
    }
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos = 3;
    }
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $pos++; // 0x02
        $len = ord($der[$pos++]);
        $val = substr($der, $pos, $len);
        $pos += $len;
        $parts[] = str_pad(ltrim($val, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
    return $parts[0] . $parts[1];
}

/** Sendet einen stillen Update-Push fuer einen Pass (loest Neu-Download + Mitteilung aus). */
function apns_push_serial(string $serial): void
{
    if (!apple_push_ready()) {
        return;
    }
    $stmt = db()->prepare('SELECT push_token FROM pass_devices WHERE serial = ?');
    $stmt->execute([$serial]);
    $tokens = array_column($stmt->fetchAll(), 'push_token');
    if (!$tokens) {
        return;
    }
    $jwt = apns_jwt();
    $host = config('apple_wallet.apns_sandbox') ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
    $ch = curl_multi_init();
    $handles = [];
    foreach ($tokens as $token) {
        $h = curl_init($host . '/3/device/' . rawurlencode($token));
        curl_setopt_array($h, [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => [
                'authorization: bearer ' . $jwt,
                'apns-topic: ' . config('apple_wallet.apns_topic'),
                'apns-push-type: background',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_multi_add_handle($ch, $h);
        $handles[] = $h;
    }
    do {
        curl_multi_exec($ch, $running);
        curl_multi_select($ch, 1);
    } while ($running > 0);
    foreach ($handles as $h) {
        curl_multi_remove_handle($ch, $h);
        curl_close($h);
    }
    curl_multi_close($ch);
}

/* ------------------------------------------------------------------ */
/* Google Wallet                                                       */
/* ------------------------------------------------------------------ */

function google_wallet_ready(): bool
{
    return (string)config('google_wallet.issuer_id', '') !== ''
        && is_file((string)config('google_wallet.service_account_json', ''));
}

function google_class_id(): string
{
    return config('google_wallet.issuer_id') . '.' . preg_replace('/[^A-Za-z0-9._-]/', '', (string)config('google_wallet.class_suffix', 'loyalty'));
}

function google_object_id(array $m): string
{
    return config('google_wallet.issuer_id') . '.' . $m['code'] . '.' . (int)$m['business_id'];
}

/** JWT-Payload (Klasse + Objekt) fuer den Save-Link und die API. */
function google_pass_payload(array $m): array
{
    $d = $m['design'];
    $balance = pass_balance($m);
    $logoUri = $d['logo'] !== '' ? base_url() . upload_url($d['logo']) : null;
    $modules = [];
    foreach (array_slice($m['cards'], 0, 4) as $c) {
        $val = match ($c['type']) {
            'stamps'  => (int)$c['stamps'] . '/' . (int)$c['stamps_required'] . ' ' . wallet_t('Stempel'),
            'points'  => (int)$c['points'] . ' ' . wallet_t('Punkte'),
            'voucher' => (int)($c['uses_left'] ?? 0) . 'x ' . wallet_t('Gutschein'),
            default   => (int)($c['visits_left'] ?? 0) . ' ' . wallet_t('Besuche'),
        };
        $modules[] = ['header' => $c['program_name'], 'body' => $val, 'id' => 'prog' . (int)$c['program_id']];
    }
    // GenericClass is neutral; branding/type fields are defined on GenericObject only.
    $class = ['id' => google_class_id()];
    $object = [
        'id' => google_object_id($m),
        'classId' => google_class_id(),
        'genericType' => 'GENERIC_TYPE_UNSPECIFIED',
        'cardTitle' => ['defaultValue' => ['language' => 'de', 'value' => $m['business_name']]],
        'header' => ['defaultValue' => ['language' => 'de', 'value' => $m['name']]],
        'subheader' => ['defaultValue' => ['language' => 'de', 'value' => $balance['label'] . ': ' . $balance['value']]],
        'hexBackgroundColor' => $d['bg_color'],
        'barcode' => [
            'type' => 'QR_CODE',
            'value' => base_url() . '/c/' . $m['code'],
        ],
        'textModulesData' => $modules,
        'merchantLocations' => wallet_member_locations($m),
    ];
    if ($logoUri) {
        $object['logo'] = ['sourceUri' => ['uri' => $logoUri]];
    }
    if ($d['stamp_image'] !== '') {
        $token = hash_hmac('sha256', 'stamp-image:' . $m['code'], (string)$m['api_key']);
        $object['heroImage'] = ['sourceUri' => ['uri' => base_url() . '/pass/stamps/' . $m['code'] . '/' . $token . '.png']];
    }
    return ['class' => $class, 'object' => $object];
}

function google_service_account(): array
{
    $path=(string)config('google_wallet.service_account_json','');
    if(!is_file($path)||!is_readable($path))return [];
    $value=json_decode((string)file_get_contents($path),true);
    return is_array($value)?$value:[];
}

function jwt_rs256(array $payload, string $key): string
{
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $data = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256);
    return $data . '.' . $b64($sig);
}

/** "Add to Google Wallet"-Link mit signiertem JWT (Klasse+Objekt im Token). */
function google_save_link(array $m): string
{
    $sa = google_service_account();
    $p = google_pass_payload($m);
    $jwt = jwt_rs256([
        'iss' => $sa['client_email'] ?? '',
        'aud' => 'google',
        'typ' => 'savetowallet',
        'iat' => time(),
        'origins' => [parse_url(base_url(), PHP_URL_HOST)],
        'payload' => [
            'genericClasses' => [$p['class']],
            'genericObjects' => [$p['object']],
        ],
    ], (string)($sa['private_key'] ?? ''));
    return 'https://pay.google.com/gp/v/save/' . $jwt;
}

function route_pass_google(string $code): never
{
    $m = pass_member($code);
    if (!$m) {
        not_found();
    }
    if (!google_wallet_ready()) {
        http_response_code(501);
        page('Google Wallet', '<div class="auth-box"><h1>Google Wallet</h1>
          <p class="muted">' . t('Google Wallet ist auf dieser Installation noch nicht eingerichtet.') . '</p>
          <p class="muted">' . t('Betreiber: Issuer-ID und Service-Account in config.php hinterlegen - Anleitung in docs/WALLET.md.') . '</p></div>');
        exit;
    }
    redirect(google_save_link($m));
}

/** A token belongs to the exact configured account file, never merely its cache path. */
function google_token_cache_usable(array $cache,string $accountHash,int $now): bool
{
    return $accountHash!==''&&is_string($cache['account_hash'] ?? null)&&hash_equals($accountHash,$cache['account_hash'])&&is_string($cache['token'] ?? null)&&$cache['token']!==''&&is_numeric($cache['expires'] ?? null)&&(int)$cache['expires']>$now+60;
}
/* Google Wallet REST API: Access Token (Service Account, gecacht) */
function google_access_token(): ?string
{
    $cacheFile = (string)config('upload_dir', ROOT_DIR . '/data') . '/google_token.json';
    $path=(string)config('google_wallet.service_account_json','');
    if(!is_file($path)||!is_readable($path))return null;
    $accountHash=hash_file('sha256',$path);if($accountHash===false)return null;
    if (is_file($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c)&&google_token_cache_usable($c,$accountHash,time())) {
            return $c['token'];
        }
    }
    $sa = google_service_account();
    if (($sa['client_email'] ?? '') === '') {
        return null;
    }
    $jwt = jwt_rs256([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => time(),
        'exp' => time() + 3600,
    ], (string)$sa['private_key']);
    $resp = http_post_json('https://oauth2.googleapis.com/token', http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    $token = $resp['body']['access_token'] ?? null;
    if (!is_string($token)||$token==='')return null;
    $temporary=@tempnam(dirname($cacheFile),'.wallet-token-');
    if($temporary!==false){
        @chmod($temporary,0600);
        $ttl=max(0,min(3500,(int)($resp['body']['expires_in'] ?? 3600)-60));
        $written=file_put_contents($temporary,json_encode(['token'=>$token,'expires'=>time()+$ttl,'account_hash'=>$accountHash]));
        if($written!==false&&@rename($temporary,$cacheFile))@chmod($cacheFile,0600);
        if(is_file($temporary))unlink($temporary);
    }
    return $token;
}

/** Explicit null clears our removed images; omitted provider fields stay untouched. */
function google_object_patch(array $object): array
{
    return $object + ['logo' => null, 'heroImage' => null];
}

/** Erstellt/aktualisiert das Wallet-Objekt, kein optionaler Nachrichten- oder Empfangsnachweis. */
function google_update_object(array $m): void
{
    if (!google_wallet_ready()) {
        return;
    }
    $token = google_access_token();
    if (!$token) {
        return;
    }
    $p = google_pass_payload($m);
    $auth = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    /* Klasse idempotent anlegen (existiert meist schon -> 409 ignorieren) */
    http_post_json('https://walletobjects.googleapis.com/walletobjects/v1/genericClass', json_encode($p['class'], JSON_UNESCAPED_SLASHES), $auth);
    /* Objekt: PATCH erhaelt fremde Felder/Nachrichten, POST nur nach echtem 404 */
    $id = rawurlencode($p['object']['id']);
    $get = http_get_json('https://walletobjects.googleapis.com/walletobjects/v1/genericObject/' . $id, $auth);
    if (($get['status'] ?? 0) === 200) {
        http_request_json('PATCH', 'https://walletobjects.googleapis.com/walletobjects/v1/genericObject/' . $id, json_encode(google_object_patch($p['object']), JSON_UNESCAPED_SLASHES), $auth);
    } elseif (($get['status'] ?? 0) === 404) {
        http_post_json('https://walletobjects.googleapis.com/walletobjects/v1/genericObject', json_encode($p['object'], JSON_UNESCAPED_SLASHES), $auth);
    }
}

/* ------------------------------------------------------------------ */
/* HTTP-Helfer (curl, JSON)                                            */
/* ------------------------------------------------------------------ */

function http_request_json(string $method, string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string)$raw, true) ?: [], 'raw' => (string)$raw];
}

function http_post_json(string $url, string $body, array $headers): array
{
    return http_request_json('POST', $url, $body, $headers);
}

function http_get_json(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string)$raw, true) ?: [], 'raw' => (string)$raw];
}

/* ------------------------------------------------------------------ */
/* Pass-Update nach Karten-Aktion                                      */
/* ------------------------------------------------------------------ */

function wallet_pass_update_for_card(int $cardId): void
{
    $stmt = db()->prepare('SELECT m.code FROM cards c JOIN members m ON m.id = c.member_id WHERE c.id = ?');
    $stmt->execute([$cardId]);
    $code = $stmt->fetchColumn();
    if (!$code) {
        return;
    }
    /* Apple: stiller Push, Geraet laedt den Pass neu (Mitteilung via changeMessage) */
    apns_push_serial((string)$code);
    /* Google: Objekt aktualisieren (Android zeigt Update-Mitteilung) */
    $m = pass_member((string)$code);
    if ($m) {
        try {
            google_update_object($m);
        } catch (Throwable) {
            /* Wallet-Update darf die Karten-Aktion nie brechen */
        }
    }
}

/** Wallet-Buttons fuer Wallet- und Kartenansicht. */
function wallet_buttons(string $code): string
{
    return '<div class="wallet-add">
        <a class="wallet-btn wallet-btn-apple" href="/pass/apple/' . h($code) . '.pkpass"> Apple Wallet</a>
        <a class="wallet-btn wallet-btn-google" href="/pass/google/' . h($code) . '">G &#8231; Wallet</a>
      </div>';
}
