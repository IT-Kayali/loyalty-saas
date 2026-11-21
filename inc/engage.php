<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Etappe 4: Achievements + Referrals + Member-Inbox                   */
/* Doku: "Achievements" + "Member referrals" + "Automated messages"    */
/* ------------------------------------------------------------------ */

/** Nachricht in die Member-Inbox (Wallet-Feed) schreiben. */
function member_message(int $memberId, string $kind, string $text): void
{
    db()->prepare('INSERT INTO member_messages (member_id, kind, text) VALUES (?,?,?)')
        ->execute([$memberId, $kind, $text]);
}

/** Kennzahlen eines Mitglieds in einem Business. */
function member_metrics(int $memberId, int $businessId): array
{
    $count = function (string $kind) use ($memberId, $businessId): int {
        $stmt = db()->prepare("SELECT COUNT(*) FROM transactions t
            JOIN cards c ON t.card_id = c.id JOIN programs p ON c.program_id = p.id
            WHERE c.member_id = ? AND p.business_id = ? AND t.kind = ? AND t.reversed_at IS NULL");
        $stmt->execute([$memberId, $businessId, $kind]);
        return (int)$stmt->fetchColumn();
    };
    $lifetime = db()->prepare("SELECT COALESCE(SUM(c.points_lifetime),0) FROM cards c
        JOIN programs p ON c.program_id = p.id
        WHERE c.member_id = ? AND p.business_id = ? AND p.type = 'points'");
    $lifetime->execute([$memberId, $businessId]);
    return [
        'stamps_total' => $count('stamp_add'),
        'points_lifetime' => (int)$lifetime->fetchColumn(),
        'visits_total' => $count('visit'),
        'rewards_redeemed' => $count('reward_redeem') + $count('points_redeem') + $count('voucher_use'),
    ];
}

const METRIC_LABELS = [
    'stamps_total' => 'Stempel gesamt',
    'points_lifetime' => 'Punkte (Lifetime)',
    'visits_total' => 'Besuche gesamt',
    'rewards_redeemed' => 'Einlösungen gesamt',
];

/**
 * Prüft und vergibt Meilensteine nach einer Aktion.
 * Bonuspunkte gehen auf die erste Punktekarte des Mitglieds im Business.
 */
function check_achievements(int $memberId, int $businessId): void
{
    $metrics = member_metrics($memberId, $businessId);
    $stmt = db()->prepare('SELECT * FROM achievements WHERE business_id = ? AND active = 1');
    $stmt->execute([$businessId]);
    foreach ($stmt->fetchAll() as $a) {
        $value = $metrics[$a['metric']] ?? 0;
        if ($value < (int)$a['threshold']) {
            continue;
        }
        $has = db()->prepare('SELECT 1 FROM member_achievements WHERE achievement_id = ? AND member_id = ?');
        $has->execute([$a['id'], $memberId]);
        if ($has->fetch()) {
            continue;
        }
        db()->prepare('INSERT INTO member_achievements (achievement_id, member_id) VALUES (?,?)')
            ->execute([$a['id'], $memberId]);
        $text = '🏆 Meilenstein erreicht: ' . $a['name'];
        if ((int)$a['reward_points'] > 0) {
            $card = db()->prepare("SELECT c.id FROM cards c JOIN programs p ON c.program_id = p.id
                WHERE c.member_id = ? AND p.business_id = ? AND p.type = 'points' LIMIT 1");
            $card->execute([$memberId, $businessId]);
            $cid = $card->fetchColumn();
            if ($cid) {
                db()->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
                    ->execute([(int)$a['reward_points'], (int)$a['reward_points'], $cid]);
                txn((int)$cid, 'points_add', (int)$a['reward_points'], '+' . (int)$a['reward_points'] . ' Punkte (Meilenstein: ' . $a['name'] . ')');
                $text .= ' - +' . (int)$a['reward_points'] . ' Punkte!';
            }
        }
        member_message($memberId, 'achievement', $text);
        fire_webhook($businessId, 'achievement.unlocked', [
            'member_id' => $memberId, 'achievement' => $a['name'],
        ]);
    }
}

/* ------------------------------------------------------------------ */
/* Owner: Achievements + Referral-Einstellungen                        */
/* ------------------------------------------------------------------ */

function route_achievement_create(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    $metric = $_POST['metric'] ?? '';
    if (!$b || !isset(METRIC_LABELS[$metric]) || trim($_POST['name'] ?? '') === '') {
        flash('Ungueltige Eingabe.', 'err');
        redirect('/dashboard');
    }
    db()->prepare('INSERT INTO achievements (business_id, name, metric, threshold, reward_points) VALUES (?,?,?,?,?)')
        ->execute([$b['id'], trim($_POST['name']), $metric, max(1, (int)$_POST['threshold']), max(0, (int)$_POST['reward_points'])]);
    flash('Meilenstein angelegt.');
    redirect('/business/' . (int)$b['id']);
}

function route_achievement_delete(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if ($b) {
        db()->prepare('DELETE FROM achievements WHERE id = ? AND business_id = ?')
            ->execute([(int)$_POST['achievement_id'], $b['id']]);
        flash('Meilenstein geloescht.');
        redirect('/business/' . (int)$b['id']);
    }
    redirect('/dashboard');
}

function route_business_referral(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if (!$b) {
        redirect('/dashboard');
    }
    db()->prepare('UPDATE businesses SET referral_points_referrer = ?, referral_points_referee = ? WHERE id = ?')
        ->execute([max(0, (int)$_POST['referrer']), max(0, (int)$_POST['referee']), $b['id']]);
    flash('Referral-Einstellungen gespeichert.');
    redirect('/business/' . (int)$b['id']);
}

/* ------------------------------------------------------------------ */
/* Referral-Vergütung beim Beitritt                                    */
/* ------------------------------------------------------------------ */

/**
 * Verguetet Werber und Geworbenen nach einem Beitritt.
 * $refCode ist der Member-Code des Werbers (muss zum selben Business gehoeren).
 */
function credit_referral(array $b, int $newMemberId, string $refCode): void
{
    $refBonus = (int)($b['referral_points_referrer'] ?? 0);
    $newBonus = (int)($b['referral_points_referee'] ?? 0);
    if ($refBonus <= 0 && $newBonus <= 0) {
        return;
    }
    $referrer = db()->prepare('SELECT * FROM members WHERE business_id = ? AND code = ?');
    $referrer->execute([$b['id'], $refCode]);
    $referrer = $referrer->fetch();
    if (!$referrer || (int)$referrer['id'] === $newMemberId) {
        return;
    }
    $pointsCard = function (int $memberId) use ($b) {
        $stmt = db()->prepare("SELECT c.id FROM cards c JOIN programs p ON c.program_id = p.id
            WHERE c.member_id = ? AND p.business_id = ? AND p.type = 'points' LIMIT 1");
        $stmt->execute([$memberId, $b['id']]);
        return $stmt->fetchColumn();
    };
    if ($refBonus > 0 && ($cid = $pointsCard((int)$referrer['id']))) {
        db()->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
            ->execute([$refBonus, $refBonus, $cid]);
        txn((int)$cid, 'points_add', $refBonus, '+' . $refBonus . ' Punkte (Freunde-werben-Bonus)');
        member_message((int)$referrer['id'], 'referral', '🤝 Danke fuers Werben! +' . $refBonus . ' Punkte fuer dich.');
    }
    if ($newBonus > 0 && ($cid = $pointsCard($newMemberId))) {
        db()->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
            ->execute([$newBonus, $newBonus, $cid]);
        txn((int)$cid, 'points_add', $newBonus, '+' . $newBonus . ' Punkte (Willkommensbonus)');
        member_message($newMemberId, 'referral', '🤝 Willkommensbonus: +' . $newBonus . ' Punkte, weil du geworben wurdest.');
    }
}
