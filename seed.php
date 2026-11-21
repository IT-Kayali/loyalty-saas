<?php
declare(strict_types=1);
/* Demo-Daten für den lokalen Test. Aufruf: bin/php seed.php */

@unlink(__DIR__ . '/data/app.sqlite');

require __DIR__ . '/index.php';

$db = db();
$memberPw = password_hash('kunde1234', PASSWORD_DEFAULT);

$db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?,?,?)')
    ->execute(['Ghaith', 'demo@loyalty.local', password_hash('demo1234', PASSWORD_DEFAULT)]);
$userId = (int)$db->lastInsertId();

/* ---- Business 1: Foundry Coffee ---- */
$db->prepare('INSERT INTO businesses (user_id, name, brand_color) VALUES (?,?,?)')
    ->execute([$userId, 'Foundry Coffee', '#E4572E']);
$bizId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO programs (business_id, type, name, description, stamps_required, reward_text) VALUES (?,?,?,?,?,?)")
    ->execute([$bizId, 'stamps', 'Dein nächster Kaffee', 'Sammle 6 Stempel. Dein nächster Kaffee geht auf uns.', 6, '1 Kaffee gratis']);
$stampProg = (int)$db->lastInsertId();

$db->prepare("INSERT INTO programs (business_id, type, name, description) VALUES (?,?,?,?)")
    ->execute([$bizId, 'points', 'Treuepunkte', 'Punkte sammeln und gegen Prämien eintauschen.']);
$pointsProg = (int)$db->lastInsertId();

$db->prepare('INSERT INTO rewards (program_id, name, points_cost) VALUES (?,?,?)')->execute([$pointsProg, 'Espresso gratis', 50]);
$db->prepare('INSERT INTO rewards (program_id, name, points_cost) VALUES (?,?,?)')->execute([$pointsProg, 'Flat White gratis', 100]);
$db->prepare('INSERT INTO rewards (program_id, name, points_cost) VALUES (?,?,?)')->execute([$pointsProg, '250g Bohnen deiner Wahl', 250]);

/* Tiers + Bonus Hours */
$db->prepare('INSERT INTO tiers (program_id, name, min_points, benefit) VALUES (?,?,?,?)')->execute([$pointsProg, 'Silber', 100, 'Gratis Sirup zu jedem Getränk']);
$db->prepare('INSERT INTO tiers (program_id, name, min_points, benefit) VALUES (?,?,?,?)')->execute([$pointsProg, 'Gold', 500, '10 % auf alles']);
$db->prepare('INSERT INTO tiers (program_id, name, min_points, benefit) VALUES (?,?,?,?)')->execute([$pointsProg, 'Platin', 1500, 'Täglich 1 Kaffee gratis']);
$db->prepare('INSERT INTO bonus_hours (program_id, weekday, start_hour, end_hour, multiplier) VALUES (?,?,?,?,?)')
    ->execute([$pointsProg, (int)date('w'), 0, 24, 3]); /* Demo: heute durchgehend x3 */

$db->prepare("INSERT INTO programs (business_id, type, name, description, voucher_value, uses_per_customer) VALUES (?,?,?,?,?,?)")
    ->execute([$bizId, 'voucher', 'Freunde-werben-Gutschein', 'Einmal pro Kunde einlösbar.', '20 % Rabatt', 1]);
$voucherProg = (int)$db->lastInsertId();

$db->prepare("INSERT INTO programs (business_id, type, name, description, pass_visits) VALUES (?,?,?,?,?)")
    ->execute([$bizId, 'pass', '10er-Karte Coworking-Ecke', 'Zehn Besuche, vorab im Laden bezahlt.', 10]);
$passProg = (int)$db->lastInsertId();

/* Staff */
$db->prepare('INSERT INTO staff (business_id, name, pin_hash) VALUES (?,?,?)')
    ->execute([$bizId, 'Lena', password_hash('4321', PASSWORD_DEFAULT)]);

/* Mitglieder (mit Kunden-Login) */
$members = [
    ['Aylin Demir', 'aylin@example.com'],
    ['Ben Kowalski', 'ben@example.com'],
    ['Chris Nguyen', ''],
];
$memberIds = [];
foreach ($members as [$name, $email]) {
    $db->prepare('INSERT INTO members (business_id, name, email, code, password_hash) VALUES (?,?,?,?,?)')
        ->execute([$bizId, $name, $email, member_code(), $email !== '' ? $memberPw : null]);
    $memberIds[$name] = (int)$db->lastInsertId();
}

$issue = function (int $prog, int $member, ?int $uses, ?int $visits) use ($db): int {
    $db->prepare('INSERT INTO cards (program_id, member_id, uses_left, visits_left) VALUES (?,?,?,?)')
        ->execute([$prog, $member, $uses, $visits]);
    return (int)$db->lastInsertId();
};

$c = $issue($stampProg, $memberIds['Aylin Demir'], null, null);
$db->prepare('UPDATE cards SET stamps = 5 WHERE id = ?')->execute([$c]);
for ($i = 1; $i <= 5; $i++) {
    txn($c, 'stamp_add', 1, "+1 Stempel ($i/6)");
}
$c = $issue($passProg, $memberIds['Aylin Demir'], null, 10);
$db->prepare('UPDATE cards SET visits_left = 8 WHERE id = ?')->execute([$c]);
txn($c, 'visit', 1, 'Besuch bestätigt');
txn($c, 'visit', 1, 'Besuch bestätigt');

$c = $issue($pointsProg, $memberIds['Ben Kowalski'], null, null);
$db->prepare('UPDATE cards SET points = 120 WHERE id = ?')->execute([$c]);
txn($c, 'points_add', 50, '+50 Punkte');
txn($c, 'points_add', 70, '+70 Punkte');

$issue($voucherProg, $memberIds['Chris Nguyen'], 1, null);

/* ---- Business 2: Atelier Beauty (zeigt Shared Wallet: Aylin ist überall) ---- */
$db->prepare('INSERT INTO businesses (user_id, name, brand_color) VALUES (?,?,?)')
    ->execute([$userId, 'Atelier Beauty', '#7C3AED']);
$biz2 = (int)$db->lastInsertId();

$db->prepare("INSERT INTO programs (business_id, type, name, description) VALUES (?,?,?,?)")
    ->execute([$biz2, 'points', 'Beauty-Punkte', 'Punkte sammeln bei jedem Besuch.']);
$beautyProg = (int)$db->lastInsertId();
$db->prepare('INSERT INTO rewards (program_id, name, points_cost) VALUES (?,?,?)')->execute([$beautyProg, 'Maniküre gratis', 100]);

$db->prepare('INSERT INTO staff (business_id, name, pin_hash) VALUES (?,?,?)')
    ->execute([$biz2, 'Mira', password_hash('9876', PASSWORD_DEFAULT)]);

$db->prepare('INSERT INTO members (business_id, name, email, code, password_hash) VALUES (?,?,?,?,?)')
    ->execute([$biz2, 'Aylin Demir', 'aylin@example.com', member_code(), $memberPw]);
$aylin2 = (int)$db->lastInsertId();
$c = $issue($beautyProg, $aylin2, null, null);
$db->prepare('UPDATE cards SET points = 80 WHERE id = ?')->execute([$c]);
txn($c, 'points_add', 80, '+80 Punkte');

/* Achievements + Referrals + Segmente (Etappen 4-5) */
$db->prepare('INSERT INTO achievements (business_id, name, metric, threshold, reward_points) VALUES (?,?,?,?,?)')
    ->execute([$bizId, 'Treue-Stammkunde', 'points_lifetime', 100, 25]);
$db->prepare('INSERT INTO achievements (business_id, name, metric, threshold, reward_points) VALUES (?,?,?,?,?)')
    ->execute([$bizId, 'Stempel-Profi', 'stamps_total', 25, 50]);
$db->prepare('UPDATE businesses SET referral_points_referrer = 50, referral_points_referee = 20 WHERE id = ?')
    ->execute([$bizId]);
$db->prepare("INSERT INTO segments (business_id, name, rule, param) VALUES (?,?,?,?)")
    ->execute([$bizId, 'Aktive Kunden', 'active_days', 30]);
$db->prepare("INSERT INTO segments (business_id, name, rule, param) VALUES (?,?,?,?)")
    ->execute([$bizId, 'VIP 100+', 'min_points', 100]);

/* join_code-Backfill sofort (init_schema fuellt erst beim naechsten Request) */
foreach ($db->query('SELECT id FROM businesses WHERE join_code IS NULL OR join_code = \'\'')->fetchAll() as $row) {
    $db->prepare('UPDATE businesses SET join_code = ? WHERE id = ?')
        ->execute([strtoupper(bin2hex(random_bytes(4))), $row['id']]);
}

echo "Seed fertig.\n";
echo "Betreiber:  demo@loyalty.local / demo1234  (http://127.0.0.1:8099/login)\n";
echo "Staff:      Business $bizId, Name Lena, PIN 4321  (/staff/login?b=$bizId)\n";
echo "Kunde:      aylin@example.com / kunde1234  (/member/login -> Shared Wallet über beide Businesses)\n";
foreach ($memberIds as $name => $id) {
    $code = $db->query("SELECT code FROM members WHERE id = $id")->fetchColumn();
    echo "Kundenkarte $name: /c/$code\n";
}
$jc = $db->query("SELECT join_code FROM businesses WHERE id = $bizId")->fetchColumn();
echo "Beitritts-Link Foundry: /join/$jc\n";
