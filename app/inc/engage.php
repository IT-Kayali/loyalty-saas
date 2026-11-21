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
    $db=db();$db->exec('BEGIN IMMEDIATE');$events=[];
    try {
        $q=$db->prepare('SELECT id FROM members WHERE id=? AND business_id=?');$q->execute([$memberId,$businessId]);if(!$q->fetch())throw new InvalidArgumentException('Mitglied passt nicht zum Unternehmen.');
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
            $text = '🏆 ' . t('Meilenstein erreicht:') . ' ' . $a['name'];
            if ((int)$a['reward_points'] > 0) {
                $card = db()->prepare("SELECT c.id FROM cards c JOIN programs p ON c.program_id = p.id
                    WHERE c.member_id = ? AND p.business_id = ? AND p.type = 'points' LIMIT 1");
                $card->execute([$memberId, $businessId]);
                $cid = $card->fetchColumn();
                if ($cid) {
                    db()->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
                        ->execute([(int)$a['reward_points'], (int)$a['reward_points'], $cid]);
                    txn((int)$cid, 'points_add', (int)$a['reward_points'], '+' . (int)$a['reward_points'] . ' Punkte (Meilenstein: ' . $a['name'] . ')');
                    $text .= ' – +' . (int)$a['reward_points'] . ' ' . t('Punkte') . '!';
                }
            }
            member_message($memberId, 'achievement', $text);
            $events[]=['member_id'=>$memberId,'achievement'=>$a['name']];
        }
        $db->exec('COMMIT');
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
    // Provider work must never hold the milestone write lock.
    foreach($events as $event)fire_webhook($businessId,'achievement.unlocked',$event);
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
        flash(t('Ungültige Eingabe.'), 'err');
        redirect('/dashboard');
    }
    db()->prepare('INSERT INTO achievements (business_id, name, metric, threshold, reward_points) VALUES (?,?,?,?,?)')
        ->execute([$b['id'], trim($_POST['name']), $metric, max(1, (int)$_POST['threshold']), max(0, (int)$_POST['reward_points'])]);
    flash(t('Meilenstein angelegt.'));
    redirect('/business/' . (int)$b['id']);
}

function route_achievement_delete(): never
{
    $u=require_login();$bid=(int)($_POST['business_id'] ?? 0);$id=(int)($_POST['achievement_id'] ?? 0);
    if(!own_business((int)$u['id'],$bid))not_found();
    $q=db()->prepare('SELECT id FROM achievements WHERE id=? AND business_id=?');$q->execute([$id,$bid]);if(!$q->fetch())not_found();
    try{achievement_change((int)$u['id'],$id,[],'delete');flash('Noch nicht erreichter Meilenstein gelöscht.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}
    redirect('/business/'.$bid);
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
    flash(t('Referral-Einstellungen gespeichert.'));
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
        member_message((int)$referrer['id'], 'referral', '🤝 ' . t('Danke fürs Werben! +{n} Punkte für dich.', ['n' => $refBonus]));
    }
    if ($newBonus > 0 && ($cid = $pointsCard($newMemberId))) {
        db()->prepare('UPDATE cards SET points = points + ?, points_lifetime = points_lifetime + ? WHERE id = ?')
            ->execute([$newBonus, $newBonus, $cid]);
        txn((int)$cid, 'points_add', $newBonus, '+' . $newBonus . ' Punkte (Willkommensbonus)');
        member_message($newMemberId, 'referral', '🤝 ' . t('Willkommensbonus: +{n} Punkte, weil du geworben wurdest.', ['n' => $newBonus]));
    }
}

function achievement_revision(array $a): string
{
    return hash('sha256',json_encode([(int)$a['id'],$a['name'],$a['metric'],(int)$a['threshold'],(int)$a['reward_points'],(int)$a['active']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function achievement_has_awards(int $id): bool
{
    $q=db()->prepare('SELECT 1 FROM member_achievements WHERE achievement_id=? LIMIT 1');$q->execute([$id]);return (bool)$q->fetchColumn();
}
function achievement_change(int $uid,int $id,array $in,string $action='update'): void
{
    if(!in_array($action,['update','delete','state'],true))throw new InvalidArgumentException('Ungültige Aktion.');
    $name=trim((string)($in['name'] ?? ''));$metric=(string)($in['metric'] ?? '');$threshold=(string)($in['threshold'] ?? '');$reward=(string)($in['reward_points'] ?? '');
    if($action==='update' && ($name==='' || !mb_check_encoding($name,'UTF-8') || mb_strlen($name)>80 || preg_match('/[\x00-\x1f\x7f]/u',$name) || !isset(METRIC_LABELS[$metric]) || !preg_match('/^[0-9]{1,6}$/D',$threshold) || (int)$threshold<1 || (int)$threshold>100000 || !preg_match('/^[0-9]{1,6}$/D',$reward) || (int)$reward>100000))throw new InvalidArgumentException('Name (1–80 Zeichen), gültige Messgröße, Schwelle 1–100000 und Bonus 0–100000 angeben.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('SELECT a.* FROM achievements a JOIN businesses b ON b.id=a.business_id WHERE a.id=? AND b.user_id=?');$q->execute([$id,$uid]);$a=$q->fetch();if(!$a)throw new InvalidArgumentException('Meilenstein nicht verfügbar.');
        if(($err=plan_policy($uid,'achievements'))!==null)throw new InvalidArgumentException($err);
        if($action!=='state' && achievement_has_awards($id))throw new InvalidArgumentException('Bereits erreicht: Regel und Vergabebelege bleiben unverändert. Nicht editierbar oder löschbar.');
        if($action==='state'){
            if(!in_array((string)($in['active'] ?? ''),['0','1'],true))throw new InvalidArgumentException('Ungültiger Aktivstatus.');
            if(!hash_equals(achievement_revision($a),(string)($in['revision'] ?? '')))throw new InvalidArgumentException('Meilenstein wurde inzwischen geändert. Aktuellen Stand prüfen.');
            $db->prepare('UPDATE achievements SET active=? WHERE id=?')->execute([(int)$in['active'],$id]);
        }elseif($action==='update') {
            if(!hash_equals(achievement_revision($a),(string)($in['revision'] ?? '')))throw new InvalidArgumentException('Meilenstein wurde inzwischen geändert. Aktuellen Stand prüfen.');
            $db->prepare('UPDATE achievements SET name=?,metric=?,threshold=?,reward_points=? WHERE id=?')->execute([$name,$metric,(int)$threshold,(int)$reward,$id]);
        }else $db->prepare('DELETE FROM achievements WHERE id=?')->execute([$id]);
        audit_log($uid,'achievement.'.$action,['achievement_id'=>$id,'business_id'=>(int)$a['business_id']]);$db->exec('COMMIT');
    }catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function route_achievement_edit(int $id,string $method): never
{
    $u=require_login();$q=db()->prepare('SELECT a.* FROM achievements a JOIN businesses b ON b.id=a.business_id WHERE a.id=? AND b.user_id=?');$q->execute([$id,$u['id']]);$a=$q->fetch();if(!$a)not_found();
    header('Cache-Control: private, no-store');
    if($method==='POST'){try{$action=(string)($_POST['action'] ?? 'update');if(!in_array($action,['update','state'],true))throw new InvalidArgumentException('Ungültige Aktion.');achievement_change((int)$u['id'],$id,$_POST,$action);flash($action==='state'?((int)$_POST['active']===1?'Meilenstein fortgesetzt. Keine rückwirkende Vergabe.':'Meilenstein pausiert. Bestehende Freischaltungen und Punkte bleiben erhalten.'):'Meilenstein gespeichert. Keine Punkte oder Nachrichten vergeben.');}catch(InvalidArgumentException $e){flash($e->getMessage(),'err');}redirect('/achievement/'.$id.'/edit');}
    $count=db()->prepare('SELECT COUNT(*) FROM member_achievements WHERE achievement_id=?');$count->execute([$id]);$awards=(int)$count->fetchColumn();
    $body='<p><a href="/business/'.(int)$a['business_id'].'">← Unternehmen</a></p><h1>Meilenstein prüfen</h1><section class="panel achievement-review"><h2>'.h($a['name']).'</h2><p>'.h(METRIC_LABELS[$a['metric']] ?? $a['metric']).' ab '.(int)$a['threshold'].' · Bonus: '.(int)$a['reward_points'].' Punkte</p><p>'.$awards.' gespeicherte Freischaltungen. Bonus nur bei der bisherigen Punktekarte. Speichern führt keine Prüfung oder Vergabe aus; die nächste unterstützte Kartenaktion prüft die dann gültigen Regeln.</p>';
    if($awards)$body.='<p class="notice">Nur lesen: Bereits erreicht. Regel und Vergabebelege bleiben erhalten, keine nachträgliche Änderung oder Löschung über diese Verwaltung.</p>';
    else {
        $opts='';foreach(METRIC_LABELS as $v=>$label)$opts.='<option value="'.$v.'"'.($v===$a['metric']?' selected':'').'>'.h($label).'</option>';
        $body.='<form method="post" class="form">'.csrf_field().'<input type="hidden" name="revision" value="'.achievement_revision($a).'"><label>Name<input name="name" maxlength="80" value="'.h($a['name']).'" required></label><label>Messgröße<select name="metric">'.$opts.'</select></label><label>Schwelle<input name="threshold" type="number" min="1" max="100000" value="'.(int)$a['threshold'].'" required></label><label>Bonus-Punkte<input name="reward_points" type="number" min="0" max="100000" value="'.(int)$a['reward_points'].'" required></label><button class="btn">Meilenstein speichern</button></form>';
    }
    $body.='</section><section class="panel achievement-state"><h2>'.((int)$a['active']===1?'Aktiv':'Pausiert').'</h2><p>Pause stoppt künftige Freischaltungen, entzieht keine erreichten Meilensteine oder Punkte. Fortsetzen prüft keine Mitglieder nachträglich. Erst die nächste unterstützte Kartenaktion prüft die aktive Regel. Kein Versand oder automatische Vergabe durch diesen Schalter.</p><form method="post">'.csrf_field().'<input type="hidden" name="action" value="state"><input type="hidden" name="revision" value="'.achievement_revision($a).'"><input type="hidden" name="active" value="'.((int)$a['active']===1?'0':'1').'"><button class="btn ghost">'.((int)$a['active']===1?'Meilenstein pausieren':'Meilenstein fortsetzen').'</button></form>';
    page('Meilenstein prüfen',$body.'</section>',$u);exit;
}
