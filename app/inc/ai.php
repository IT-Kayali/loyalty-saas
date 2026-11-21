<?php
declare(strict_types=1);
/**
 * AI-Verbindungen: MCP-Endpunkt (/mcp) und Verwaltung (/ai). Details und Grenzen: docs/AI.md.
 * Schlüssel gehören einem Betreiberkonto und können nie mehr als dieses. Riskante Änderungen
 * werden nur vorbereitet und laufen erst nach Freigabe des Eigentümers in der App.
 */
const AI_APPROVAL_SECONDS = 600;
const AI_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];
class AiToolError extends RuntimeException {}

function ai_enabled(): bool { return (bool)config('ai_enabled', false); }

/** Werkzeuggruppen fuer feinere Schluesselrechte. Meta-Werkzeuge (list_businesses, get_change_status, run_approved_change) sind immer erlaubt. */
const AI_GROUPS = [
    'reports'  => ['label' => 'Auswertungen', 'tools' => ['get_business_overview', 'list_transactions']],
    'members'  => ['label' => 'Mitglieder', 'tools' => ['list_members', 'get_member', 'create_member']],
    'points'   => ['label' => 'Punkte und Stempel buchen', 'tools' => ['award_points', 'give_stamp']],
    'programs' => ['label' => 'Programme', 'tools' => ['list_programs', 'create_program']],
    'delete'   => ['label' => 'Löschen (mit Freigabe)', 'tools' => ['delete_member', 'delete_program']],
];

/** Gruppe eines Werkzeugs oder null bei Meta-Werkzeugen. */
function ai_tool_group(string $tool): ?string
{
    foreach (AI_GROUPS as $g => $def) if (in_array($tool, $def['tools'], true)) return $g;
    return null;
}

/** Erlaubte Gruppen eines Schluessels (NULL in der DB = alle). */
function ai_key_groups(array $key): array
{
    $raw = $key['tool_groups'] ?? null;
    if ($raw === null || $raw === '') return array_keys(AI_GROUPS);
    return array_values(array_intersect(array_keys(AI_GROUPS), explode(',', (string)$raw)));
}

/** Darf der Schluessel dieses Werkzeug sehen und aufrufen (Stufe, Gruppe)? */
function ai_key_allows(array $key, string $name, array $tool): bool
{
    if ($tool['mode'] !== 'read' && ($key['scope'] ?? '') !== 'write') return false;
    $g = ai_tool_group($name);
    return $g === null || in_array($g, ai_key_groups($key), true);
}

/** $groups: null = alle, sonst Liste; $businessId: null = alle Unternehmen des Kontos. */
function ai_key_create(int $uid, string $name, string $scope, int $days, ?array $groups = null, ?int $businessId = null): string
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 80 || !in_array($scope, ['read', 'write'], true) || $days < 1 || $days > 365) {
        throw new InvalidArgumentException('Name (max. 80 Zeichen), Zugriff und Laufzeit 1 bis 365 Tage erforderlich.');
    }
    if ($groups !== null) {
        $groups = array_values(array_intersect(array_keys(AI_GROUPS), $groups));
        if (!$groups) throw new InvalidArgumentException('Mindestens eine Werkzeuggruppe auswählen.');
        if (count($groups) === count(AI_GROUPS)) $groups = null;
    }
    if ($businessId !== null && !own_business($uid, $businessId)) throw new InvalidArgumentException('Unternehmen nicht gefunden.');
    $q = db()->prepare('SELECT COUNT(*) FROM ai_keys WHERE user_id=? AND revoked_at IS NULL AND expires_at>datetime(\'now\')');
    $q->execute([$uid]);
    if ((int)$q->fetchColumn() >= 10) throw new InvalidArgumentException('Höchstens 10 aktive Schlüssel. Zuerst einen widerrufen.');
    $secret = 'rl_ai_' . bin2hex(random_bytes(24));
    db()->prepare("INSERT INTO ai_keys(user_id,name,prefix,key_hash,scope,expires_at,tool_groups,business_id) VALUES (?,?,?,?,?,datetime('now',?),?,?)")
        ->execute([$uid, $name, substr($secret, 0, 14), hash('sha256', $secret), $scope, '+' . $days . ' days', $groups === null ? null : implode(',', $groups), $businessId]);
    audit_log($uid, 'ai.key.create', ['prefix' => substr($secret, 0, 14), 'scope' => $scope, 'groups' => $groups === null ? 'all' : implode(',', $groups), 'business' => $businessId ?? 'all']);
    return $secret;
}

/** Umfang eines aktiven eigenen Schluessels aendern, offene Freigaben verwerfen. */
function ai_key_update(int $uid, int $keyId, string $scope, array $groups, ?int $businessId): void
{
    if (!in_array($scope, ['read','write'], true)) throw new InvalidArgumentException('Ungültiger Zugriff.');
    $groups = array_values(array_intersect(array_keys(AI_GROUPS), $groups));
    if (!$groups) throw new InvalidArgumentException('Mindestens eine Werkzeuggruppe auswählen.');
    if ($businessId !== null && !own_business($uid, $businessId)) throw new InvalidArgumentException('Unternehmen nicht gefunden.');
    db()->exec('BEGIN IMMEDIATE');
    try {
        $q = db()->prepare("UPDATE ai_keys SET scope=?,tool_groups=?,business_id=? WHERE id=? AND user_id=? AND revoked_at IS NULL AND expires_at>datetime('now')");
        $q->execute([$scope, count($groups) === count(AI_GROUPS) ? null : implode(',', $groups), $businessId, $keyId, $uid]);
        if (!$q->rowCount()) throw new InvalidArgumentException('Aktiver Schlüssel nicht gefunden.');
        db()->prepare("UPDATE ai_requests SET status='expired' WHERE key_id=? AND status IN ('pending','approved')")->execute([$keyId]);
        audit_log($uid, 'ai.key.update', ['key_id'=>$keyId,'scope'=>$scope,'groups'=>implode(',', $groups),'business'=>$businessId ?? 'all']);
        db()->exec('COMMIT');
    } catch (Throwable $e) { db()->exec('ROLLBACK'); throw $e; }
}

function route_ai_key(int $keyId, string $method): never
{
    $u = require_login(); $uid = (int)$u['id'];
    if (!ai_enabled()) not_found();
    $q = db()->prepare("SELECT * FROM ai_keys WHERE id=? AND user_id=? AND revoked_at IS NULL AND expires_at>datetime('now')");
    $q->execute([$keyId,$uid]); $key = $q->fetch();
    if (!$key) not_found();
    if ($method === 'POST') {
        try {
            ai_key_update($uid,$keyId,(string)($_POST['scope'] ?? ''),array_map('strval',(array)($_POST['groups'] ?? [])),(int)($_POST['business_id'] ?? 0) ?: null);
            flash('Schlüsselumfang gespeichert. Offene Freigaben sind abgelaufen.'); redirect('/ai');
        } catch (InvalidArgumentException $e) { flash($e->getMessage(),'err'); redirect('/ai/key/'.$keyId); }
    }
    header('Cache-Control: no-store');
    $body = '<p><a href="/ai">← KI-Verbindungen</a></p><h1>Schlüsselumfang ändern</h1><section class="panel"><h2>'.h($key['name']).'</h2><p>Präfix: <code>'.h($key['prefix']).'</code></p><p class="muted">Speichern ändert die Rechte für folgende Anfragen mit demselben Schlüssel. Offene und schon freigegebene, noch nicht ausgeführte Löschanfragen dieses Schlüssels laufen ab, auch wenn du dieselben Werte speicherst. Bereits laufende Vorgänge können nicht zurückgerufen werden. Geheimnis und Ablaufdatum bleiben unverändert.</p><form method="post" class="form">'.csrf_field().'<label>Zugriff<select name="scope"><option value="read"'.($key['scope']==='read'?' selected':'').'>Nur lesen</option><option value="write"'.($key['scope']==='write'?' selected':'').'>Lesen und ändern</option></select></label><label>Unternehmen<select name="business_id"><option value="0">Alle Unternehmen des Kontos</option>';
    $bs=db()->prepare('SELECT id,name FROM businesses WHERE user_id=? ORDER BY id');$bs->execute([$uid]);
    foreach ($bs->fetchAll() as $b) $body.='<option value="'.(int)$b['id'].'"'.((int)$key['business_id']===(int)$b['id']?' selected':'').'>'.h($b['name']).'</option>';
    $body.='</select></label><fieldset><legend>Werkzeuggruppen</legend>';
    foreach (AI_GROUPS as $g=>$def) $body.='<label class="check"><input type="checkbox" name="groups[]" value="'.h($g).'"'.(in_array($g,ai_key_groups($key),true)?' checked':'').'> '.h($def['label']).'</label>';
    $body.='</fieldset><button class="btn">Umfang speichern</button></form></section>';
    page('Schlüsselumfang ändern',$body,$u);exit;
}

function ai_key_revoke(int $uid, int $keyId): bool
{
    $q = db()->prepare("UPDATE ai_keys SET revoked_at=datetime('now') WHERE id=? AND user_id=? AND revoked_at IS NULL");
    $q->execute([$keyId, $uid]);
    if ($q->rowCount() === 1) {
        // Offene Freigaben dieses Schlüssels sind damit ungültig.
        db()->prepare("UPDATE ai_requests SET status='expired' WHERE key_id=? AND status IN ('pending','approved')")->execute([$keyId]);
        audit_log($uid, 'ai.key.revoke', ['key_id' => $keyId]);
        return true;
    }
    return false;
}

/** @return array{key:array,user:array}|null */
function ai_key_lookup(string $secret): ?array
{
    if (!preg_match('/^rl_ai_[a-f0-9]{48}$/D', $secret)) return null;
    $q = db()->prepare("SELECT * FROM ai_keys WHERE key_hash=? AND revoked_at IS NULL AND expires_at>datetime('now')");
    $q->execute([hash('sha256', $secret)]);
    $k = $q->fetch();
    if (!$k) return null;
    $u = db()->prepare('SELECT * FROM users WHERE id=?');
    $u->execute([$k['user_id']]);
    $user = $u->fetch();
    return $user ? ['key' => $k, 'user' => $user] : null;
}

function ai_business(array $ctx, array $args): array
{
    $uid = (int)$ctx['user']['id'];
    $bid = (int)($args['business_id'] ?? 0);
    $only = (int)($ctx['key']['business_id'] ?? 0);
    if ($only !== 0) {
        // Schluessel gilt nur fuer dieses Unternehmen: Standard, andere werden wie nicht vorhanden behandelt.
        if ($bid !== 0 && $bid !== $only) throw new AiToolError('Unternehmen nicht gefunden.');
        $bid = $only;
    }
    if ($bid === 0) {
        $q = db()->prepare('SELECT id FROM businesses WHERE user_id=?');
        $q->execute([$uid]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) throw new AiToolError('business_id ist erforderlich (list_businesses zeigt die Unternehmen).');
        $bid = (int)$ids[0];
    }
    $b = own_business($uid, $bid);
    if (!$b) throw new AiToolError('Unternehmen nicht gefunden.');
    return $b;
}

function ai_member(array $b, array $args): array
{
    $m = api_member_by_code((int)$b['id'], (string)($args['member_code'] ?? ''));
    if (!$m) throw new AiToolError('Mitglied nicht gefunden.');
    return $m;
}

function ai_one(string $sql, array $p): int { $q = db()->prepare($sql); $q->execute($p); return (int)$q->fetchColumn(); }

function ai_member_fingerprint(array $b, array $args): string
{
    $m = ai_member($b, $args);
    return hash('sha256', json_encode([$m['id'], $m['name'], $m['email'], ai_one('SELECT COALESCE(SUM(stamps+points+pending_rewards),0) FROM cards WHERE member_id=?', [$m['id']])]));
}
function ai_program_fingerprint(array $b, array $args): string
{
    $q = db()->prepare('SELECT * FROM programs WHERE id=? AND business_id=?');
    $q->execute([(int)($args['program_id'] ?? 0), $b['id']]);
    $p = $q->fetch();
    if (!$p) throw new AiToolError('Programm nicht gefunden.');
    return hash('sha256', json_encode([$p['id'], $p['name'], $p['type'], $p['active'], ai_one('SELECT COUNT(*) FROM cards WHERE program_id=?', [$p['id']])]));
}

function ai_tools(): array
{
    $biz = ['business_id' => ['type' => 'integer', 'description' => 'Unternehmen. Bei nur einem Unternehmen optional.']];
    $code = ['member_code' => ['type' => 'string', 'description' => '10-stelliger Mitgliedscode.']];
    return [
        'list_businesses' => ['desc' => 'Listet die Unternehmen des Kontos.', 'props' => [], 'req' => [], 'mode' => 'read',
            'run' => function (array $c, array $a): array {
                $only = (int)($c['key']['business_id'] ?? 0);
                $q = db()->prepare('SELECT id,name,join_code FROM businesses WHERE user_id=?' . ($only ? ' AND id=' . $only : '') . ' ORDER BY id');
                $q->execute([$c['user']['id']]);
                return ['businesses' => $q->fetchAll(PDO::FETCH_ASSOC)];
            }],
        'get_business_overview' => ['desc' => 'Kennzahlen: Mitglieder, Programme, Karten, Buchungen der letzten 30 Tage.', 'props' => $biz, 'req' => [], 'mode' => 'read',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a); $id = (int)$b['id'];
                return ['business' => $b['name'],
                    'members' => ai_one('SELECT COUNT(*) FROM members WHERE business_id=?', [$id]),
                    'active_programs' => ai_one('SELECT COUNT(*) FROM programs WHERE business_id=? AND active=1', [$id]),
                    'open_rewards' => ai_one('SELECT COALESCE(SUM(c.pending_rewards),0) FROM cards c JOIN programs p ON p.id=c.program_id WHERE p.business_id=?', [$id]),
                    'transactions_30d' => ai_one("SELECT COUNT(*) FROM transactions t JOIN cards c ON c.id=t.card_id JOIN programs p ON p.id=c.program_id WHERE p.business_id=? AND t.created_at>=datetime('now','-30 days')", [$id])];
            }],
        'list_programs' => ['desc' => 'Listet Programme mit Konfiguration.', 'props' => $biz, 'req' => [], 'mode' => 'read',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a);
                $q = db()->prepare('SELECT id,type,name,description,stamps_required,reward_text,active FROM programs WHERE business_id=? ORDER BY id');
                $q->execute([$b['id']]);
                return ['programs' => $q->fetchAll(PDO::FETCH_ASSOC)];
            }],
        'list_members' => ['desc' => 'Sucht Mitglieder nach Name, E-Mail oder Code. Höchstens 50, Cursor über after.', 'props' => $biz + ['q' => ['type' => 'string', 'description' => 'Suchtext.'], 'after' => ['type' => 'integer', 'description' => 'Cursor aus next_after.']], 'req' => [], 'mode' => 'read',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a);
                $q = trim((string)($a['q'] ?? ''));
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($q, 0, 100)) . '%';
                $st = db()->prepare("SELECT id,name,email,code,created_at FROM members WHERE business_id=? AND id>? AND (?='' OR name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR code LIKE ? ESCAPE '!') ORDER BY id LIMIT 51");
                $st->execute([$b['id'], max(0, (int)($a['after'] ?? 0)), $q, $like, $like, $like]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                $more = count($rows) > 50; $rows = array_slice($rows, 0, 50);
                return ['members' => $rows, 'next_after' => $more ? (int)end($rows)['id'] : null];
            }],
        'get_member' => ['desc' => 'Ein Mitglied mit Karten und Ständen.', 'props' => $biz + $code, 'req' => ['member_code'], 'mode' => 'read',
            'run' => function (array $c, array $a): array { return member_api_payload(ai_member(ai_business($c, $a), $a)); }],
        'list_transactions' => ['desc' => 'Letzte Buchungen des Unternehmens (höchstens 100).', 'props' => $biz + ['limit' => ['type' => 'integer', 'description' => '1 bis 100, Standard 25.']], 'req' => [], 'mode' => 'read',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a);
                $st = db()->prepare('SELECT t.id,t.kind,t.amount,t.note,t.created_at,m.code AS member_code FROM transactions t JOIN cards c ON c.id=t.card_id JOIN programs p ON p.id=c.program_id JOIN members m ON m.id=c.member_id WHERE p.business_id=? ORDER BY t.id DESC LIMIT ?');
                $st->bindValue(1, (int)$b['id'], PDO::PARAM_INT); $st->bindValue(2, max(1, min(100, (int)($a['limit'] ?? 25))), PDO::PARAM_INT); $st->execute();
                return ['transactions' => $st->fetchAll(PDO::FETCH_ASSOC)];
            }],
        'create_member' => ['desc' => 'Legt ein Mitglied an oder findet es per E-Mail.', 'props' => $biz + ['name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'idempotency_key' => ['type' => 'string']], 'req' => ['name'], 'mode' => 'write',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a);
                $name = trim((string)($a['name'] ?? '')); $email = strtolower(trim((string)($a['email'] ?? '')));
                if ($name === '' || mb_strlen($name) > 120 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new AiToolError('Name und gültige E-Mail (optional) erforderlich.');
                return member_api_payload(api_member_upsert((int)$b['id'], $email, $name));
            }],
        'award_points' => ['desc' => 'Schreibt Punkte auf die aktive Punktekarte eines Mitglieds gut (1 bis 10000, Bonus-Zeiten gelten).', 'props' => $biz + $code + ['amount' => ['type' => 'integer'], 'idempotency_key' => ['type' => 'string']], 'req' => ['member_code', 'amount'], 'mode' => 'write',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a); $m = ai_member($b, $a);
                $amount = (int)($a['amount'] ?? 0);
                if ($amount < 1 || $amount > 10000) throw new AiToolError('amount muss 1 bis 10000 sein.');
                $st = db()->prepare("SELECT c.* FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=? AND p.type='points' AND p.active=1 LIMIT 1");
                $st->execute([$m['id'], $b['id']]);
                $card = $st->fetch();
                if (!$card) throw new AiToolError('Keine aktive Punktekarte.');
                $mult = points_multiplier((int)$card['program_id']); $credit = $amount * $mult;
                db()->prepare('UPDATE cards SET points=points+?, points_lifetime=points_lifetime+? WHERE id=?')->execute([$credit, $credit, $card['id']]);
                txn((int)$card['id'], 'points_add', $credit, '+' . $credit . ' Punkte (KI)' . ($mult > 1 ? ' (Bonus-Zeit x' . $mult . ')' : ''));
                check_achievements((int)$m['id'], (int)$b['id']);
                fire_webhook((int)$b['id'], 'card.points', ['card_id' => (int)$card['id'], 'member_id' => (int)$m['id'], 'amount' => $credit]);
                wallet_pass_update_for_card((int)$card['id']);
                return ['member' => $m['code'], 'credited' => $credit, 'bonus_multiplier' => $mult, 'points' => ai_one('SELECT points FROM cards WHERE id=?', [$card['id']])];
            }],
        'give_stamp' => ['desc' => 'Vergibt einen Stempel auf der aktiven Stempelkarte eines Mitglieds.', 'props' => $biz + $code + ['idempotency_key' => ['type' => 'string']], 'req' => ['member_code'], 'mode' => 'write',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a); $m = ai_member($b, $a);
                $st = db()->prepare("SELECT c.*, p.stamps_required, p.name AS program_name FROM cards c JOIN programs p ON p.id=c.program_id WHERE c.member_id=? AND p.business_id=? AND p.type='stamps' AND p.active=1 LIMIT 1");
                $st->execute([$m['id'], $b['id']]);
                $card = $st->fetch();
                if (!$card) throw new AiToolError('Keine aktive Stempelkarte.');
                $req = (int)$card['stamps_required']; $stamps = (int)$card['stamps'] + 1; $pending = (int)$card['pending_rewards']; $earned = false;
                if ($stamps >= $req) { $pending++; $stamps -= $req; $earned = true; }
                db()->prepare('UPDATE cards SET stamps=?, pending_rewards=? WHERE id=?')->execute([$stamps, $pending, $card['id']]);
                txn((int)$card['id'], 'stamp_add', 1, '+1 Stempel (KI) (' . $stamps . '/' . $req . ')');
                if ($earned) txn((int)$card['id'], 'reward_earned', 1, 'Belohnung verdient: ' . $card['program_name']);
                check_achievements((int)$m['id'], (int)$b['id']);
                fire_webhook((int)$b['id'], 'card.stamp', ['card_id' => (int)$card['id'], 'member_id' => (int)$m['id']]);
                wallet_pass_update_for_card((int)$card['id']);
                return ['member' => $m['code'], 'stamps' => $stamps, 'stamps_required' => $req, 'pending_rewards' => $pending, 'reward_earned' => $earned];
            }],
        'create_program' => ['desc' => 'Legt ein Stempel- oder Punkteprogramm an (Design danach in der App).', 'props' => $biz + ['type' => ['type' => 'string', 'enum' => ['stamps', 'points']], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'stamps_required' => ['type' => 'integer', 'description' => 'Nur Stempelkarten, 2 bis 50.'], 'reward_text' => ['type' => 'string'], 'idempotency_key' => ['type' => 'string']], 'req' => ['type', 'name'], 'mode' => 'write',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a);
                $type = (string)($a['type'] ?? ''); $name = trim((string)($a['name'] ?? ''));
                if (!in_array($type, ['stamps', 'points'], true) || $name === '' || mb_strlen($name) > 120) throw new AiToolError('type (stamps oder points) und name erforderlich.');
                if ($e = plan_policy((int)$c['user']['id'], '', 'programs')) throw new AiToolError($e);
                $stamps = max(2, min(50, (int)($a['stamps_required'] ?? 6)));
                db()->prepare('INSERT INTO programs (business_id,type,name,description,stamps_required,reward_text) VALUES (?,?,?,?,?,?)')
                    ->execute([$b['id'], $type, $name, mb_substr(trim((string)($a['description'] ?? '')), 0, 500), $stamps, mb_substr(trim((string)($a['reward_text'] ?? '')), 0, 200)]);
                return ['program_id' => (int)db()->lastInsertId(), 'type' => $type, 'name' => $name, 'stamps_required' => $stamps];
            }],
        'delete_member' => ['desc' => 'Löscht ein Mitglied mit Karten und Buchungen. Wartet auf Freigabe des Eigentümers in der App.', 'props' => $biz + $code + ['idempotency_key' => ['type' => 'string']], 'req' => ['member_code'], 'mode' => 'approval',
            'summary' => function (array $b, array $a): string { $m = ai_member($b, $a); return 'Mitglied "' . $m['name'] . '" (' . $m['code'] . ') löschen'; },
            'touches' => function (array $b, array $a): string { $m = ai_member($b, $a); return ai_one('SELECT COUNT(*) FROM cards WHERE member_id=?', [$m['id']]) . ' Karten und ' . ai_one('SELECT COUNT(*) FROM transactions WHERE card_id IN (SELECT id FROM cards WHERE member_id=?)', [$m['id']]) . ' Buchungen werden entfernt'; },
            'fingerprint' => 'ai_member_fingerprint',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a); $m = ai_member($b, $a);
                db()->prepare('DELETE FROM members WHERE id=? AND business_id=?')->execute([$m['id'], $b['id']]);
                return ['deleted' => true, 'member' => $m['code']];
            }],
        'delete_program' => ['desc' => 'Löscht ein Programm mit allen Karten und Buchungen. Wartet auf Freigabe des Eigentümers in der App.', 'props' => $biz + ['program_id' => ['type' => 'integer'], 'idempotency_key' => ['type' => 'string']], 'req' => ['program_id'], 'mode' => 'approval',
            'summary' => function (array $b, array $a): string { ai_program_fingerprint($b, $a); return 'Programm #' . (int)$a['program_id'] . ' "' . db()->query('SELECT name FROM programs WHERE id=' . (int)$a['program_id'])->fetchColumn() . '" löschen'; },
            'touches' => function (array $b, array $a): string { return ai_one('SELECT COUNT(*) FROM cards WHERE program_id=?', [(int)$a['program_id']]) . ' Karten und ' . ai_one('SELECT COUNT(*) FROM transactions WHERE card_id IN (SELECT id FROM cards WHERE program_id=?)', [(int)$a['program_id']]) . ' Buchungen werden entfernt'; },
            'fingerprint' => 'ai_program_fingerprint',
            'run' => function (array $c, array $a): array {
                $b = ai_business($c, $a); ai_program_fingerprint($b, $a);
                db()->prepare('DELETE FROM programs WHERE id=? AND business_id=?')->execute([(int)$a['program_id'], $b['id']]);
                return ['deleted' => true, 'program_id' => (int)$a['program_id']];
            }],
        'get_change_status' => ['desc' => 'Status einer vorbereiteten Änderung (pending, approved, rejected, expired, done, failed).', 'props' => ['request_id' => ['type' => 'string']], 'req' => ['request_id'], 'mode' => 'read',
            'run' => function (array $c, array $a): array {
                $r = ai_request_load($c, (string)($a['request_id'] ?? ''));
                return ['request_id' => $r['id'], 'tool' => $r['tool'], 'status' => ai_request_status($r), 'expires_at' => $r['expires_at']];
            }],
        'run_approved_change' => ['desc' => 'Führt eine vom Eigentümer freigegebene Änderung genau einmal aus. Braucht request_id und nonce aus der Vorbereitung.', 'props' => ['request_id' => ['type' => 'string'], 'nonce' => ['type' => 'string']], 'req' => ['request_id', 'nonce'], 'mode' => 'write', 'run' => 'ai_run_approved'],
    ];
}

function ai_request_load(array $ctx, string $id): array
{
    $q = db()->prepare('SELECT * FROM ai_requests WHERE id=? AND key_id=? AND user_id=?');
    $q->execute([$id, $ctx['key']['id'], $ctx['user']['id']]);
    $r = $q->fetch();
    if (!$r) throw new AiToolError('Freigabeanfrage nicht gefunden.');
    return $r;
}

function ai_request_status(array $r): string
{
    if (in_array($r['status'], ['pending', 'approved'], true) && $r['expires_at'] <= gmdate('Y-m-d H:i:s')) return 'expired';
    return (string)$r['status'];
}

function ai_prepare(array $ctx, string $name, array $tool, array $args): array
{
    $b = ai_business($ctx, $args);
    $args['business_id'] = (int)$b['id'];
    $summary = ($tool['summary'])($b, $args);
    $touches = ($tool['touches'])($b, $args);
    $fp = ($tool['fingerprint'])($b, $args);
    $id = bin2hex(random_bytes(12)); $nonce = bin2hex(random_bytes(16));
    $exp = gmdate('Y-m-d H:i:s', time() + AI_APPROVAL_SECONDS);
    $clean = array_diff_key($args, ['idempotency_key' => 1]);
    db()->prepare('INSERT INTO ai_requests(id,key_id,user_id,tool,args,summary,touches,fingerprint,nonce_hash,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $ctx['key']['id'], $ctx['user']['id'], $name, json_encode($clean, JSON_UNESCAPED_UNICODE), $summary, $touches, $fp, hash('sha256', $nonce), $exp]);
    audit_log((int)$ctx['user']['id'], 'ai.change.prepared', ['request' => $id, 'tool' => $name]);
    return ['approval_required' => true, 'request_id' => $id, 'nonce' => $nonce, 'summary' => $summary,
        'review_url' => base_url() . '/ai/approval/' . $id, 'expires_at' => $exp . ' UTC',
        'next' => 'Der Eigentümer prüft und gibt in der App frei. Danach run_approved_change mit request_id und nonce aufrufen. Die Anfrage gilt 10 Minuten.'];
}

function ai_run_approved(array $ctx, array $args): array
{
    $r = ai_request_load($ctx, (string)($args['request_id'] ?? ''));
    $nonce = (string)($args['nonce'] ?? '');
    if (!hash_equals($r['nonce_hash'], hash('sha256', $nonce))) throw new AiToolError('nonce passt nicht zur Anfrage.');
    $status = ai_request_status($r);
    if ($status !== 'approved') throw new AiToolError(match ($status) {
        'pending' => 'Noch nicht freigegeben. Der Eigentümer muss in der App entscheiden.',
        'done' => 'Diese Änderung wurde bereits ausgeführt.',
        'rejected' => 'Der Eigentümer hat abgelehnt. Nichts wurde geändert.',
        'expired' => 'Die Anfrage ist abgelaufen. Änderung neu vorbereiten.',
        default => 'Nicht ausführbar (Status ' . $status . '). Bestand prüfen und neu vorbereiten.',
    });
    $tools = ai_tools(); $tool = $tools[$r['tool']] ?? null;
    if (!$tool || $tool['mode'] !== 'approval') throw new AiToolError('Unbekanntes Werkzeug in der Anfrage.');
    // Genau einmal: nur wer den Wechsel approved -> running gewinnt.
    $q = db()->prepare("UPDATE ai_requests SET status='running' WHERE id=? AND status='approved'");
    $q->execute([$r['id']]);
    if ($q->rowCount() !== 1) throw new AiToolError('Die Anfrage läuft bereits oder wurde ausgeführt.');
    $a = json_decode($r['args'], true) ?: [];
    try {
        $b = ai_business($ctx, $a);
        if (!hash_equals($r['fingerprint'], ($tool['fingerprint'])($b, $a))) {
            db()->prepare("UPDATE ai_requests SET status='failed',result=? WHERE id=?")->execute([json_encode(['error' => 'record_changed']), $r['id']]);
            audit_log((int)$ctx['user']['id'], 'ai.change.failed', ['request' => $r['id'], 'reason' => 'record_changed']);
            throw new AiToolError('Nicht ausgeführt: Der Datensatz hat sich seit der Prüfung geändert. Neu vorbereiten.');
        }
        if ($e = plan_policy((int)$ctx['user']['id'], 'api')) {
            db()->prepare("UPDATE ai_requests SET status='approved' WHERE id=?")->execute([$r['id']]);
            throw new AiToolError($e);
        }
        $result = ($tool['run'])($ctx, $a);
        db()->prepare("UPDATE ai_requests SET status='done',result=? WHERE id=?")->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $r['id']]);
        audit_log((int)$ctx['user']['id'], 'ai.change.done', ['request' => $r['id'], 'tool' => $r['tool']]);
        return $result;
    } catch (AiToolError $e) {
        throw $e;
    } catch (Throwable $e) {
        db()->prepare("UPDATE ai_requests SET status='failed',result=? WHERE id=? AND status='running'")->execute([json_encode(['error' => 'server']), $r['id']]);
        audit_log((int)$ctx['user']['id'], 'ai.change.failed', ['request' => $r['id'], 'reason' => 'server']);
        throw new AiToolError('Änderung nicht ausgeführt und geschlossen (Serverfehler). Bestand prüfen und neu vorbereiten.');
    }
}

/** Führt ein Werkzeug aus. Schreibaufrufe sind über idempotency_key sicher wiederholbar. */
function ai_call_tool(array $ctx, string $name, array $args): array
{
    $tools = ai_tools(); $tool = $tools[$name] ?? null;
    if (!$tool || !ai_key_allows($ctx['key'], $name, $tool)) {
        if ($tool) audit_log((int)$ctx['user']['id'], 'ai.scope.denied', ['tool' => $name, 'key' => $ctx['key']['prefix']]);
        throw new InvalidArgumentException('Unbekanntes Werkzeug: ' . $name);
    }
    foreach ($tool['req'] as $field) if (!array_key_exists($field, $args) || $args[$field] === '' || $args[$field] === null) throw new AiToolError('Pflichtfeld fehlt: ' . $field);
    if ($tool['mode'] === 'read') return ($tool['run'])($ctx, $args);
    if ($e = plan_policy((int)$ctx['user']['id'], 'api')) throw new AiToolError($e);
    $idem = (string)($args['idempotency_key'] ?? '');
    if ($idem !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,80}$/D', $idem)) throw new AiToolError('idempotency_key: 8 bis 80 Zeichen A-Z a-z 0-9 . _ : -');
    $run = function () use ($ctx, $name, $tool, $args): array {
        return $tool['mode'] === 'approval' ? ai_prepare($ctx, $name, $tool, $args) : (is_string($tool['run']) ? ($tool['run'])($ctx, $args) : $tool['run']($ctx, $args));
    };
    if ($idem === '' || $name === 'run_approved_change') return $run();
    $hash = hash('sha256', $name . json_encode(array_diff_key($args, ['idempotency_key' => 1]), JSON_UNESCAPED_UNICODE));
    $ins = db()->prepare('INSERT OR IGNORE INTO ai_calls(key_id,idempotency_key,request_hash,state) VALUES (?,?,?,\'running\')');
    $ins->execute([$ctx['key']['id'], $idem, $hash]);
    if ($ins->rowCount() === 0) {
        $q = db()->prepare('SELECT * FROM ai_calls WHERE key_id=? AND idempotency_key=?');
        $q->execute([$ctx['key']['id'], $idem]);
        $old = $q->fetch();
        if (!hash_equals($old['request_hash'], $hash)) throw new AiToolError('idempotency_key wurde schon für andere Eingaben benutzt.');
        if ($old['state'] === 'done') return (json_decode($old['response'], true) ?: []) + ['replayed' => true];
        throw new AiToolError('Ein früherer Aufruf mit diesem idempotency_key ist unklar beendet. Nicht blind wiederholt: Bestand prüfen und neuen Schlüssel verwenden.');
    }
    $result = $run();
    db()->prepare("UPDATE ai_calls SET state='done',response=? WHERE key_id=? AND idempotency_key=?")->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $ctx['key']['id'], $idem]);
    return $result;
}

function ai_prompts(array $ctx): array
{
    $write = $ctx['key']['scope'] === 'write' && in_array('programs', ai_key_groups($ctx['key']), true);
    $g = ai_key_groups($ctx['key']);
    $p = [
        'weekly_report' => ['description' => 'Wochenbericht für ein Unternehmen.', 'arguments' => [],
            'text' => 'Rufe get_business_overview, list_programs und list_transactions (limit 100) auf und fasse die letzten Tage knapp zusammen: Mitglieder, aktive Programme, offene Belohnungen, auffällige Buchungen.'],
        'member_lookup' => ['description' => 'Mitglied nachschlagen.', 'arguments' => [['name' => 'query', 'description' => 'Name, E-Mail oder Code', 'required' => true]],
            'text' => 'Suche mit list_members nach "{query}", lade bei einem Treffer get_member und erkläre Kartenstände und offene Belohnungen.'],
    ];
    if (!in_array('reports', $g, true) || !in_array('programs', $g, true)) unset($p['weekly_report']);
    if (!in_array('members', $g, true)) unset($p['member_lookup']);
    if ($write) $p['launch_stamp_card'] = ['description' => 'Stempelkarte anlegen.', 'arguments' => [['name' => 'name', 'required' => true], ['name' => 'stamps', 'required' => true], ['name' => 'reward', 'required' => true]],
        'text' => 'Lege mit create_program (type stamps) die Karte "{name}" mit {stamps} Stempeln und der Prämie "{reward}" an. Nenne danach die Programm-ID und weise darauf hin, dass das Design in der App angepasst wird.'];
    return $p;
}

function mcp_reply($id, array $payload, int $http = 200): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_dispatch(array $ctx, array $msg): ?array
{
    $id = $msg['id'] ?? null; $method = (string)($msg['method'] ?? ''); $p = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    if (($msg['jsonrpc'] ?? '') !== '2.0' || $method === '') return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32600, 'message' => 'Ungültige Anfrage']];
    if (!array_key_exists('id', $msg)) return null; // Benachrichtigung
    $ok = fn(array|object $r) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $r];
    $err = fn(int $c, string $m) => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $c, 'message' => $m]];
    switch ($method) {
        case 'initialize':
            $v = (string)($p['protocolVersion'] ?? '');
            return $ok(['protocolVersion' => in_array($v, AI_PROTOCOLS, true) ? $v : AI_PROTOCOLS[0],
                'capabilities' => ['tools' => ['listChanged' => false], 'prompts' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'loyalty-saas', 'version' => '1.0'],
                'instructions' => 'Handelt als das Betreiberkonto des Schlüssels. Riskante Änderungen warten auf Freigabe in der App.']);
        case 'ping': return $ok((object)[]);
        case 'tools/list':
            $out = [];
            foreach (ai_tools() as $n => $t) {
                if (!ai_key_allows($ctx['key'], $n, $t)) continue;
                $out[] = ['name' => $n, 'description' => $t['desc'] . ($t['mode'] === 'approval' ? ' [Wartet auf Freigabe]' : ''),
                    'inputSchema' => ['type' => 'object', 'properties' => (object)$t['props'], 'required' => $t['req']],
                    'annotations' => ['readOnlyHint' => $t['mode'] === 'read', 'destructiveHint' => $t['mode'] === 'approval']];
            }
            return $ok(['tools' => $out]);
        case 'tools/call':
            $name = (string)($p['name'] ?? ''); $args = is_array($p['arguments'] ?? null) ? $p['arguments'] : [];
            try {
                $data = ai_call_tool($ctx, $name, $args);
                audit_log((int)$ctx['user']['id'], 'ai.tool', ['tool' => $name, 'key' => $ctx['key']['prefix'], 'ok' => true]);
                return $ok(['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]], 'structuredContent' => $data, 'isError' => false]);
            } catch (InvalidArgumentException $e) {
                return $err(-32602, $e->getMessage());
            } catch (AiToolError $e) {
                audit_log((int)$ctx['user']['id'], 'ai.tool', ['tool' => $name, 'key' => $ctx['key']['prefix'], 'ok' => false]);
                return $ok(['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
            }
        case 'prompts/list':
            $out = [];
            foreach (ai_prompts($ctx) as $n => $pr) $out[] = ['name' => $n, 'description' => $pr['description'], 'arguments' => $pr['arguments']];
            return $ok(['prompts' => $out]);
        case 'prompts/get':
            $pr = ai_prompts($ctx)[(string)($p['name'] ?? '')] ?? null;
            if (!$pr) return $err(-32602, 'Unbekannter Prompt');
            $text = $pr['text']; $given = is_array($p['arguments'] ?? null) ? $p['arguments'] : [];
            foreach ($pr['arguments'] as $arg) $text = str_replace('{' . $arg['name'] . '}', mb_substr((string)($given[$arg['name']] ?? ''), 0, 200), $text);
            return $ok(['description' => $pr['description'], 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]]);
    }
    return $err(-32601, 'Methode nicht gefunden');
}

function route_mcp(string $method): never
{
    header('Cache-Control: no-store');
    if (!ai_enabled()) { http_response_code(404); header('Content-Type: application/json'); echo '{"error":"ai_disabled"}'; exit; }
    // Browser senden Origin. Schlüsselzugriff ist nur für Programme gedacht, nicht für fremde Webseiten.
    if (isset($_SERVER['HTTP_ORIGIN'])) { http_response_code(403); header('Content-Type: application/json'); echo '{"error":"origin_not_allowed"}'; exit; }
    if ($method !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
    $auth = preg_match('#^Bearer\s+(\S+)$#i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m) ? ai_key_lookup($m[1]) : null;
    if (!$auth) { http_response_code(401); header('WWW-Authenticate: Bearer realm="loyalty-mcp"'); header('Content-Type: application/json'); echo '{"error":"invalid_key"}'; exit; }
    if (!rate_limit_take('mcp', (string)$auth['key']['id'], 60, 60)) rate_limit_stop();
    db()->prepare("UPDATE ai_keys SET last_used_at=datetime('now') WHERE id=?")->execute([$auth['key']['id']]);
    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > 100000) mcp_reply(null, ['error' => ['code' => -32600, 'message' => 'Anfrage zu groß']], 413);
    $body = json_decode($raw, true);
    if (!is_array($body)) mcp_reply(null, ['error' => ['code' => -32700, 'message' => 'Kein gültiges JSON']], 400);
    if (array_is_list($body)) {
        if (!$body || count($body) > 20) mcp_reply(null, ['error' => ['code' => -32600, 'message' => 'Batch 1 bis 20']], 400);
        $out = array_values(array_filter(array_map(fn($x) => is_array($x) ? mcp_dispatch($auth, $x) : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Ungültige Anfrage']], $body)));
        if (!$out) { http_response_code(202); exit; }
        http_response_code(200); header('Content-Type: application/json; charset=utf-8'); echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }
    $r = mcp_dispatch($auth, $body);
    if ($r === null) { http_response_code(202); exit; }
    http_response_code(200); header('Content-Type: application/json; charset=utf-8'); echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}

function ai_key_scope_label(array $key, array $bnames): string
{
    $b = (int)($key['business_id'] ?? 0);
    $biz = $b ? ($bnames[$b] ?? ('Unternehmen #' . $b)) : 'Alle Unternehmen';
    $g = ai_key_groups($key);
    $tools = count($g) === count(AI_GROUPS) ? 'alle Werkzeuge' : implode(', ', array_map(fn($x) => AI_GROUPS[$x]['label'], $g));
    return $biz . ', ' . $tools;
}

function route_ai(string $method): never
{
    $u = require_login(); $uid = (int)$u['id'];
    if (!ai_enabled()) {
        page('KI-Verbindungen', '<h1>KI-Verbindungen</h1><p>Aus. Der Betreiber der Installation schaltet sie mit <code>ai_enabled</code> in der config.php ein. Die normalen Seiten funktionieren ohne.</p>', $u);
        exit;
    }
    $shown = '';
    if ($method === 'POST') {
        $act = (string)($_POST['action'] ?? '');
        try {
            if ($act === 'create') {
                $secret = ai_key_create($uid, (string)($_POST['name'] ?? ''), (string)($_POST['scope'] ?? ''), (int)($_POST['days'] ?? 90),
                    isset($_POST['scoped']) ? array_map('strval', (array)($_POST['groups'] ?? [])) : null,
                    (int)($_POST['business_id'] ?? 0) ?: null);
                $shown = '<section class="panel"><h2>Neuer Schlüssel</h2><p>Nur jetzt sichtbar. Kopieren und im KI-Client als Bearer-Schlüssel eintragen. Später gibt es nur das Präfix.</p><p><code style="overflow-wrap:anywhere">' . h($secret) . '</code></p></section>';
            } elseif ($act === 'revoke') {
                flash(ai_key_revoke($uid, (int)($_POST['key_id'] ?? 0)) ? 'Schlüssel widerrufen.' : 'Schlüssel nicht gefunden.', 'ok');
                redirect('/ai');
            } else throw new InvalidArgumentException('Aktion nicht erlaubt.');
        } catch (InvalidArgumentException $e) { flash($e->getMessage(), 'err'); redirect('/ai'); }
    }
    header('Cache-Control: no-store');
    $body = '<h1>KI-Verbindungen</h1><p class="muted">Ein KI-Client (Claude, ChatGPT, Cursor und andere MCP-Clients) arbeitet mit deinem Schlüssel und kann nie mehr als du. Lesen und alltägliche Änderungen laufen sofort. Löschungen warten auf deine Freigabe hier in der App.</p>' . $shown
        . '<section class="panel"><p><strong>Adresse:</strong> <code>' . h(base_url()) . '/mcp</code> (Bearer-Schlüssel, nur über HTTPS betreiben)</p></section>';
    $q = db()->prepare('SELECT * FROM ai_requests WHERE user_id=? ORDER BY created_at DESC, rowid DESC LIMIT 30'); $q->execute([$uid]);
    $reqs = $q->fetchAll(); $pending = '';
    foreach ($reqs as $r) if (ai_request_status($r) === 'pending') $pending .= '<li><a href="/ai/approval/' . h($r['id']) . '">' . h($r['summary']) . '</a></li>';
    if ($pending !== '') $body .= '<section class="panel"><h2>Wartet auf deine Entscheidung</h2><ul>' . $pending . '</ul></section>';
    $body .= '<h2>Schlüssel</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Präfix</th><th>Zugriff</th><th>Umfang</th><th>Läuft ab (UTC)</th><th>Zuletzt benutzt</th><th></th></tr></thead><tbody>';
    $bn = db()->prepare('SELECT id,name FROM businesses WHERE user_id=? ORDER BY id'); $bn->execute([$uid]); $bnames = $bn->fetchAll(PDO::FETCH_KEY_PAIR);
    $k = db()->prepare('SELECT * FROM ai_keys WHERE user_id=? ORDER BY id DESC'); $k->execute([$uid]);
    foreach ($k->fetchAll() as $key) {
        $dead = $key['revoked_at'] !== null || $key['expires_at'] <= gmdate('Y-m-d H:i:s');
        $body .= '<tr><td>' . h($key['name']) . '</td><td><code>' . h($key['prefix']) . '</code></td><td>' . ($key['scope'] === 'write' ? 'Lesen und ändern' : 'Nur lesen') . '</td><td>' . h(ai_key_scope_label($key, $bnames)) . '</td><td>' . h($key['expires_at']) . ($dead ? ' (inaktiv)' : '') . '</td><td>' . h($key['last_used_at'] ?? 'nie') . '</td><td>'
            . ($dead ? '' : '<p><a href="/ai/key/' . (int)$key['id'] . '">Umfang ändern</a></p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="revoke"><input type="hidden" name="key_id" value="' . (int)$key['id'] . '"><button class="btn ghost">Widerrufen</button></form>') . '</td></tr>';
    }
    $bizSelect = '<label>Unternehmen<select name="business_id"><option value="0">Alle Unternehmen des Kontos</option>';
    foreach ($bnames as $bid => $bname) $bizSelect .= '<option value="' . (int)$bid . '">' . h($bname) . '</option>';
    $bizSelect .= '</select></label>';
    $groupBoxes = '';
    foreach (AI_GROUPS as $g => $def) $groupBoxes .= '<label class="check"><input type="checkbox" name="groups[]" value="' . h($g) . '" checked> ' . h($def['label']) . '</label>';
    $body .= '</tbody></table></div><section class="panel"><h2>Neuen Schlüssel erstellen</h2><form method="post" class="form">' . csrf_field() . '<input type="hidden" name="action" value="create"><label>Name<input name="name" maxlength="80" required></label><label>Zugriff<select name="scope"><option value="read">Nur lesen</option><option value="write">Lesen und ändern</option></select></label><label>Laufzeit in Tagen<input name="days" type="number" min="1" max="365" value="90"></label><fieldset class="scope-box"><legend>Umfang</legend>' . $bizSelect . '<p class="muted">Werkzeuge, die dieser Schlüssel nutzen darf:</p>' . $groupBoxes . '<input type="hidden" name="scoped" value="1"></fieldset><button class="btn">Schlüssel erstellen</button></form></section>';
    $body .= '<h2>Letzte Anfragen</h2><div class="table-wrap"><table><thead><tr><th>Änderung</th><th>Status</th><th>Zeit (UTC)</th></tr></thead><tbody>';
    foreach (array_slice($reqs, 0, 10) as $r) $body .= '<tr><td><a href="/ai/approval/' . h($r['id']) . '">' . h($r['summary']) . '</a></td><td>' . h(ai_request_status($r)) . '</td><td>' . h($r['created_at']) . '</td></tr>';
    $body .= '</tbody></table></div>';
    page('KI-Verbindungen', $body, $u);
    exit;
}

function route_ai_approval(string $id, string $method): never
{
    $u = require_login(); $uid = (int)$u['id'];
    $q = db()->prepare('SELECT r.*,k.name AS key_name,k.prefix FROM ai_requests r JOIN ai_keys k ON k.id=r.key_id WHERE r.id=? AND r.user_id=?');
    $q->execute([$id, $uid]);
    $r = $q->fetch();
    if (!$r) not_found();
    if ($method === 'POST') {
        $decision = (string)($_POST['decision'] ?? '');
        $new = $decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : '');
        if ($new === '' || $r['status'] !== 'pending' || ai_request_status($r) !== 'pending') {
            flash('Die Anfrage ist nicht mehr offen.', 'err'); redirect('/ai/approval/' . $id);
        }
        // Der Schlüssel muss noch aktiv sein, sonst gibt es nichts zu entscheiden.
        $live = db()->prepare("SELECT COUNT(*) FROM ai_keys WHERE id=? AND revoked_at IS NULL AND expires_at>datetime('now')"); $live->execute([$r['key_id']]);
        if (!(int)$live->fetchColumn()) { flash('Der Schlüssel ist nicht mehr aktiv.', 'err'); redirect('/ai/approval/' . $id); }
        $up = db()->prepare("UPDATE ai_requests SET status=?,decided_at=datetime('now') WHERE id=? AND status='pending' AND expires_at>?");
        $up->execute([$new, $id, gmdate('Y-m-d H:i:s')]);
        if ($up->rowCount() === 1) audit_log($uid, 'ai.change.' . $new, ['request' => $id, 'tool' => $r['tool']]);
        flash($up->rowCount() === 1 ? ($new === 'approved' ? 'Freigegeben. Der KI-Client kann die Änderung jetzt ausführen.' : 'Abgelehnt. Nichts wurde geändert.') : 'Die Anfrage ist nicht mehr offen.');
        redirect('/ai/approval/' . $id);
    }
    $status = ai_request_status($r);
    $labels = ['pending' => 'Wartet auf deine Entscheidung', 'approved' => 'Freigegeben', 'running' => 'Läuft', 'done' => 'Ausgeführt', 'rejected' => 'Abgelehnt', 'expired' => 'Abgelaufen', 'failed' => 'Nicht ausgeführt'];
    $left = max(0, strtotime($r['expires_at'] . ' UTC') - time());
    $body = '<p><a href="/ai">Zurück zu KI-Verbindungen</a></p><h1>Änderung prüfen</h1><section class="panel"><h2>' . h($r['summary']) . '</h2><p>Angefragt über Schlüssel "' . h($r['key_name']) . '" (<code>' . h($r['prefix']) . '</code>) am ' . h($r['created_at']) . ' UTC.</p><p><strong>Das betrifft:</strong> ' . h($r['touches']) . '.</p><p><strong>Status:</strong> ' . h($labels[$status] ?? $status) . (in_array($status, ['pending', 'approved'], true) ? ' · noch ' . intdiv($left, 60) . ' Min ' . ($left % 60) . ' s' : '') . '</p></section>';
    if ($status === 'pending') $body .= '<form method="post" class="form">' . csrf_field() . '<button class="btn" name="decision" value="approve">Freigeben</button> <button class="btn ghost" name="decision" value="reject">Ablehnen</button></form><p class="muted">Lies die Änderung vor dem Freigeben. Bei Überraschung ablehnen, dann bleibt alles unverändert.</p>';
    page('Änderung prüfen', $body, $u);
    exit;
}
