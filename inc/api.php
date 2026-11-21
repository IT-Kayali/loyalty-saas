<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Etappe 6: REST API (JSON, API-Key) + Webhooks                       */
/* Doku: "API" + "Webhooks"                                            */
/* ------------------------------------------------------------------ */

const WEBHOOK_EVENTS = [
    'member.joined'        => 'Neues Mitglied',
    'card.stamp'           => 'Stempel vergeben',
    'card.points'          => 'Punkte gutgeschrieben',
    'card.redeem'          => 'Einloesung',
    'card.visit'           => 'Besuch (Mehrfachkarte)',
    'achievement.unlocked' => 'Meilenstein erreicht',
];

/* ------------------------------------------------------------------ */
/* Webhooks                                                            */
/* ------------------------------------------------------------------ */

/** Feuert ein Event an alle aktiven Webhooks des Business. */
function fire_webhook(int $businessId, string $event, array $payload): void
{
    $stmt = db()->prepare("SELECT * FROM webhooks WHERE business_id = ? AND active = 1
        AND (',' || events || ',') LIKE ?");
    $stmt->execute([$businessId, '%,' . $event . ',%']);
    foreach ($stmt->fetchAll() as $hook) {
        $body = json_encode([
            'event' => $event,
            'business_id' => $businessId,
            'timestamp' => date('c'),
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE);
        db()->prepare('INSERT INTO webhook_deliveries (webhook_id, event, payload) VALUES (?,?,?)')
            ->execute([$hook['id'], $event, $body]);
        $deliveryId = (int)db()->lastInsertId();
        [$status, $response] = webhook_post($hook['url'], $body, (string)$hook['secret']);
        db()->prepare('UPDATE webhook_deliveries SET status = ?, response = ?, delivered_at = datetime("now") WHERE id = ?')
            ->execute([$status, mb_strimwidth((string)$response, 0, 500, ''), $deliveryId]);
    }
}

/** POSTet die Payload an die Webhook-URL. Liefert [status, response]. */
function webhook_post(string $url, string $body, string $secret): array
{
    $signature = $secret !== '' ? hash_hmac('sha256', $body, $secret) : '';
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n"
            . "User-Agent: LoyaltyApp-Webhook/1.0\r\n"
            . ($signature !== '' ? "X-Webhook-Signature: sha256=$signature\r\n" : ''),
        'content' => $body,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) {
        return ['failed', 'Verbindung fehlgeschlagen'];
    }
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    return [($code >= 200 && $code < 300) ? 'delivered' : 'failed', 'HTTP ' . $code];
}

/* ------------------------------------------------------------------ */
/* Owner: API-Key + Webhook-Verwaltung                                 */
/* ------------------------------------------------------------------ */

function route_apikey_regenerate(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if ($b) {
        db()->prepare('UPDATE businesses SET api_key = ? WHERE id = ?')
            ->execute([bin2hex(random_bytes(24)), $b['id']]);
        flash('Neuer API-Schluessel erzeugt. Der alte ist sofort ungueltig.');
        redirect('/business/' . (int)$b['id']);
    }
    redirect('/dashboard');
}

function route_webhook_create(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $url = trim($_POST['url'] ?? '');
    $events = array_values(array_intersect(array_keys(WEBHOOK_EVENTS), (array)($_POST['events'] ?? [])));
    if (!$b || !preg_match('#^https?://#', $url) || !$events) {
        flash('URL (http/https) und mindestens ein Event erforderlich.', 'err');
        redirect($b ? '/business/' . (int)$b['id'] : '/dashboard');
    }
    db()->prepare('INSERT INTO webhooks (business_id, url, events, secret) VALUES (?,?,?,?)')
        ->execute([$b['id'], $url, implode(',', $events), trim($_POST['secret'] ?? '')]);
    flash('Webhook angelegt.');
    redirect('/business/' . (int)$b['id']);
}

function route_webhook_delete(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if ($b) {
        db()->prepare('DELETE FROM webhooks WHERE id = ? AND business_id = ?')
            ->execute([(int)$_POST['webhook_id'], $b['id']]);
        flash('Webhook geloescht.');
        redirect('/business/' . (int)$b['id']);
    }
    redirect('/dashboard');
}

function api_html(array $b): string
{
    $hooks = db()->prepare('SELECT * FROM webhooks WHERE business_id = ? ORDER BY id');
    $hooks->execute([$b['id']]);
    $list = '';
    foreach ($hooks->fetchAll() as $hook) {
        $del = db()->prepare('SELECT event, status, response, delivered_at FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT 5');
        $del->execute([$hook['id']]);
        $log = '';
        foreach ($del->fetchAll() as $d) {
            $log .= '<br><span class="muted" style="font-size:12px">' . h($d['delivered_at']) . ' ' . h($d['event'])
                . ' - ' . h($d['status']) . ' (' . h($d['response']) . ')</span>';
        }
        $list .= '<li><code>' . h($hook['url']) . '</code> <span class="muted">' . h($hook['events']) . '</span>'
            . ' <form method="post" action="/webhook/delete" style="display:inline">'
            . '<input type="hidden" name="business_id" value="' . (int)$b['id'] . '">'
            . '<input type="hidden" name="webhook_id" value="' . (int)$hook['id'] . '">'
            . '<button class="btn ghost small">Loeschen</button></form>' . $log . '</li>';
    }
    $list = $list ? '<ul>' . $list . '</ul>' : '<p class="muted">Noch keine Webhooks.</p>';

    $eventBoxes = '';
    foreach (WEBHOOK_EVENTS as $key => $label) {
        $eventBoxes .= '<label style="display:inline-flex;gap:4px;align-items:center;margin-right:12px;font-weight:normal">'
            . '<input type="checkbox" name="events[]" value="' . $key . '" style="width:auto"> ' . h($label) . '</label>';
    }
    return '
      <p>API-Schluessel: <code>' . h($b['api_key'] ?? '') . '</code>
        <form method="post" action="/apikey/regenerate" style="display:inline">
          <input type="hidden" name="business_id" value="' . (int)$b['id'] . '">
          <button class="btn ghost small">Neu erzeugen</button>
        </form></p>
      <p class="muted">Nutzung: <code>curl -H "Authorization: Bearer KEY" .../api/v1/members</code> - Endpunkte: GET /api/v1/business, GET/POST /api/v1/members, GET /api/v1/members/{code}, GET /api/v1/programs, GET /api/v1/transactions, POST /api/v1/cards/{id}/points</p>
      <h3 style="margin:16px 0 8px">Webhooks</h3>
      ' . $list . '
      <details class="panel">
        <summary>Webhook anlegen</summary>
        <form method="post" action="/webhook/create" class="form">
          <input type="hidden" name="business_id" value="' . (int)$b['id'] . '">
          <label>Ziel-URL <input name="url" required placeholder="https://mein-server.de/hook"></label>
          <label>Secret (optional, fuer HMAC-Signatur) <input name="secret" maxlength="80"></label>
          <div>' . $eventBoxes . '</div>
          <button class="btn">Anlegen</button>
        </form>
      </details>';
}

/* ------------------------------------------------------------------ */
/* REST API Dispatcher (JSON, kein Session-Login)                      */
/* ------------------------------------------------------------------ */

function api_auth(): array
{
    $key = '';
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('#^Bearer\s+(\S+)$#i', $header, $m)) {
        $key = $m[1];
    } elseif (isset($_GET['api_key'])) {
        $key = (string)$_GET['api_key'];
    }
    $stmt = db()->prepare('SELECT * FROM businesses WHERE api_key = ?');
    $stmt->execute([$key]);
    $b = $stmt->fetch();
    if (!$b) {
        api_json(['error' => 'Ungueltiger API-Schluessel'], 401);
    }
    return $b;
}

function api_json(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function member_api_payload(array $m): array
{
    $cards = db()->prepare('SELECT c.id, c.stamps, c.points, c.points_lifetime, c.pending_rewards,
        c.uses_left, c.visits_left, p.name AS program, p.type
        FROM cards c JOIN programs p ON p.id = c.program_id WHERE c.member_id = ?');
    $cards->execute([$m['id']]);
    return [
        'id' => (int)$m['id'],
        'name' => $m['name'],
        'email' => $m['email'],
        'code' => $m['code'],
        'joined_at' => $m['created_at'],
        'cards' => $cards->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function route_api(string $path, string $method): never
{
    $b = api_auth();
    $bid = (int)$b['id'];
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];

    /* GET /api/v1/business */
    if ($path === '/api/v1/business' && $method === 'GET') {
        api_json(['id' => $bid, 'name' => $b['name'], 'brand_color' => $b['brand_color'], 'join_code' => $b['join_code']]);
    }

    /* GET /api/v1/programs */
    if ($path === '/api/v1/programs' && $method === 'GET') {
        $stmt = db()->prepare('SELECT id, type, name, description, stamps_required, reward_text, active FROM programs WHERE business_id = ?');
        $stmt->execute([$bid]);
        api_json(['programs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /* GET /api/v1/members */
    if ($path === '/api/v1/members' && $method === 'GET') {
        $stmt = db()->prepare('SELECT * FROM members WHERE business_id = ? ORDER BY id DESC LIMIT 200');
        $stmt->execute([$bid]);
        $out = [];
        foreach ($stmt->fetchAll() as $m) {
            $out[] = member_api_payload($m);
        }
        api_json(['count' => count($out), 'members' => $out]);
    }

    /* POST /api/v1/members - Mitglied anlegen */
    if ($path === '/api/v1/members' && $method === 'POST') {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            api_json(['error' => 'name ist erforderlich'], 422);
        }
        $email = strtolower(trim((string)($input['email'] ?? '')));
        db()->prepare('INSERT INTO members (business_id, name, email, code) VALUES (?,?,?,?)')
            ->execute([$bid, $name, $email, member_code()]);
        $mid = (int)db()->lastInsertId();
        $programs = db()->prepare('SELECT * FROM programs WHERE business_id = ? AND active = 1');
        $programs->execute([$bid]);
        foreach ($programs->fetchAll() as $p) {
            $uses = $p['type'] === 'voucher' ? (int)$p['uses_per_customer'] : null;
            $visits = $p['type'] === 'pass' ? (int)$p['pass_visits'] : null;
            db()->prepare('INSERT OR IGNORE INTO cards (program_id, member_id, uses_left, visits_left) VALUES (?,?,?,?)')
                ->execute([$p['id'], $mid, $uses, $visits]);
        }
        $m = db()->prepare('SELECT * FROM members WHERE id = ?');
        $m->execute([$mid]);
        fire_webhook($bid, 'member.joined', ['member_id' => $mid, 'name' => $name]);
        api_json(member_api_payload($m->fetch()), 201);
    }

    /* GET /api/v1/members/{code} */
    if (preg_match('#^/api/v1/members/([A-F0-9]{10})$#', $path, $m2) && $method === 'GET') {
        $stmt = db()->prepare('SELECT * FROM members WHERE business_id = ? AND code = ?');
        $stmt->execute([$bid, $m2[1]]);
        $m = $stmt->fetch();
        if (!$m) {
            api_json(['error' => 'Mitglied nicht gefunden'], 404);
        }
        api_json(member_api_payload($m));
    }

    /* GET /api/v1/transactions */
    if ($path === '/api/v1/transactions' && $method === 'GET') {
        $stmt = db()->prepare('SELECT t.id, t.kind, t.amount, t.note, t.created_at, t.reversed_at,
            c.id AS card_id, m.code AS member_code, p.name AS program
            FROM transactions t JOIN cards c ON t.card_id = c.id
            JOIN members m ON c.member_id = m.id JOIN programs p ON c.program_id = p.id
            WHERE p.business_id = ? ORDER BY t.id DESC LIMIT 100');
        $stmt->execute([$bid]);
        api_json(['transactions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /* POST /api/v1/cards/{id}/points - Punkte gutschreiben */
    if (preg_match('#^/api/v1/cards/(\d+)/points$#', $path, $m3) && $method === 'POST') {
        $stmt = db()->prepare('SELECT c.*, p.type AS program_type, p.name AS program_name, p.business_id
            FROM cards c JOIN programs p ON p.id = c.program_id WHERE c.id = ? AND p.business_id = ?');
        $stmt->execute([(int)$m3[1], $bid]);
        $card = $stmt->fetch();
        if (!$card || $card['program_type'] !== 'points') {
            api_json(['error' => 'Punktekarte nicht gefunden'], 404);
        }
        $base = max(1, min(10000, (int)($input['amount'] ?? 0)));
        $mult = points_multiplier((int)$card['program_id']);
        $amount = $base * $mult;
        db()->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
            ->execute([$amount, $amount, $card['id']]);
        txn((int)$card['id'], 'points_add', $amount, '+' . $amount . ' Punkte (API)' . ($mult > 1 ? ' (Bonus-Zeit x' . $mult . ')' : ''));
        check_achievements((int)$card['member_id'], $bid);
        fire_webhook($bid, 'card.points', ['card_id' => (int)$card['id'], 'member_id' => (int)$card['member_id'], 'amount' => $amount]);
        api_json(['card_id' => (int)$card['id'], 'credited' => $amount, 'bonus_multiplier' => $mult,
            'points' => (int)db()->query('SELECT points FROM cards WHERE id = ' . (int)$card['id'])->fetchColumn()]);
    }

    api_json(['error' => 'Endpunkt nicht gefunden'], 404);
}
