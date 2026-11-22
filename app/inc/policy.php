<?php
declare(strict_types=1);

/** Shared write policy. Database triggers enforce it across every write surface. */
function plan_policy(int $userId, string $feature = '', string $create = ''): ?string
{
    if (!config('stripe.enforce_limits', false)) return null;
    $q=db()->prepare('SELECT * FROM subscriptions WHERE user_id=?');$q->execute([$userId]);$s=$q->fetch();
    if (!$s || !in_array($s['status'], ['active','trialing'], true)) return 'Abo nicht aktiv. Abrechnung bleibt erreichbar.';
    $plan=(array)config('stripe.plans.'.$s['plan_key'],[]);
    if (!$plan) return 'Plan nicht konfiguriert.';
    if ($feature!=='' && array_key_exists($feature,$plan['features'] ?? []) && !$plan['features'][$feature]) return 'Diese Funktion ist im Plan nicht enthalten: '.$feature;
    if ($create!=='') {
        $limit=(int)($plan['limits'][$create] ?? 0);
        if ($limit>0) {
            $sql=match($create) {
                'businesses'=>'SELECT COUNT(*) FROM businesses WHERE user_id=?',
                'programs'=>'SELECT COUNT(*) FROM programs p JOIN businesses b ON b.id=p.business_id WHERE b.user_id=?',
                'members'=>'SELECT COUNT(*) FROM members m JOIN businesses b ON b.id=m.business_id WHERE b.user_id=?',
                'staff'=>'SELECT COUNT(*) FROM staff s JOIN businesses b ON b.id=s.business_id WHERE b.user_id=?',
                default=>null,
            };
            if($sql) { $q=db()->prepare($sql);$q->execute([$userId]);if((int)$q->fetchColumn()>=$limit)return 'Planlimit erreicht: '.$create; }
        }
    }
    return null;
}

function policy_http_error(string $message): never
{
    http_response_code(402);
    header('Cache-Control: no-store');
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/') || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/integrations/')) {
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>'plan_restricted','message'=>$message],JSON_UNESCAPED_UNICODE);
    } else {
        page('Plan prüfen','<div class="auth-box"><h1>Plan prüfen</h1><p>'.h($message).'</p><p><a class="btn" href="/billing">Abrechnung öffnen</a></p><p class="muted">Bestehende Karten können weiter gelesen werden. Wende dich als Mitglied oder Staff an den Betreiber.</p></div>',current_user());
    }
    exit;
}

function require_plan_write(int $userId, string $feature=''): void
{
    $error=plan_policy($userId,$feature);
    if($error!==null)policy_http_error($error);
}

/**
 * Per-connection TEMP triggers cannot be accidentally disabled by another client.
 * SQLite serializes the count and INSERT in one statement, closing cap races.
 * Read-only clients do not need the callbacks. A manual DB writer is an admin,
 * not an application API, and must be protected by filesystem permissions.
 */
function install_plan_guards(PDO $pdo): void
{
    $pdo->sqliteCreateFunction('loyalty_plan_ok', static function($uid,$feature,$create): int {
        return plan_policy((int)$uid,(string)$feature,(string)$create)===null ? 1 : 0;
    },3);
    $pdo->sqliteCreateFunction('loyalty_account_change',static fn():int=>(!empty($GLOBALS['consent_update_authorized'])||!empty($GLOBALS['password_reset_authorized'])||!empty($GLOBALS['password_change_authorized'])||!empty($GLOBALS['profile_update_authorized']))?1:0,0);
    $pdo->sqliteCreateFunction('loyalty_privacy_delete',static fn():int=>!empty($GLOBALS['privacy_delete_authorized'])?1:0,0);
    $direct='(SELECT user_id FROM businesses WHERE id=%s.business_id)';
    $program='(SELECT b.user_id FROM programs p JOIN businesses b ON b.id=p.business_id WHERE p.id=%s.program_id)';
    $member='(SELECT b.user_id FROM members m JOIN businesses b ON b.id=m.business_id WHERE m.id=%s.member_id)';
    $card='(SELECT b.user_id FROM cards c JOIN programs p ON p.id=c.program_id JOIN businesses b ON b.id=p.business_id WHERE c.id=%s.card_id)';
    $rules=[
        'businesses'=>['%s.user_id','core','businesses'],
        'networks'=>['%s.user_id','locations',''],
        'locations'=>[$direct,'locations',''],
        'programs'=>[$direct,'programs','programs'],
        'members'=>[$direct,'members','members'],
        'staff'=>[$direct,'staff','staff'],
        'cards'=>[$program,'programs',''],
        'transactions'=>[$card,'programs',''],
        'rewards'=>[$program,'programs',''],
        'voucher_codes'=>[$program,'vouchers',''],
        'prepaid_issues'=>[$direct,'prepaid',''],
        'prepaid_ledger'=>[$direct,'prepaid',''],
        'voucher_redemptions'=>[$direct,'vouchers',''],
        'tiers'=>[$program,'tiers',''],
        'bonus_hours'=>[$program,'bonus_hours',''],
        'achievements'=>[$direct,'achievements',''],
        'member_achievements'=>[$member,'achievements',''],
        'member_messages'=>[$member,'core',''],
        'segments'=>[$direct,'campaigns',''],
        'campaigns'=>[$direct,'campaigns',''],
        'mail_automations'=>[$direct,'campaigns',''],
        'campaign_sends'=>[$member,'campaigns',''],
        'webhooks'=>[$direct,'webhooks',''],
        'order_receipts'=>[$direct,'integrations',''],
        'merchant_operations'=>[$direct,'api',''],
        'webhook_deliveries'=>['(SELECT b.user_id FROM webhooks w JOIN businesses b ON b.id=w.business_id WHERE w.id=%s.webhook_id)','webhooks',''],
    ];
    foreach($rules as $table=>[$owner,$feature,$capacity]) {
        foreach(['INSERT'=>'NEW','UPDATE'=>'NEW','DELETE'=>'OLD'] as $op=>$ref) {
            $expr=sprintf($owner,$ref);$create=$op==='INSERT'?$capacity:'';
            // GDPR deletion cascades are allowed only inside the authenticated delete handler.
            $privacy=($table==='members' && $op==='UPDATE')?'loyalty_account_change()=0 AND ':'';
            $privacy.=($op==='DELETE' || (in_array($table,['prepaid_issues','merchant_operations'],true) && $op==='UPDATE'))?'loyalty_privacy_delete()=0 AND ':'';
            $pdo->exec("CREATE TEMP TRIGGER IF NOT EXISTS plan_{$table}_{$op} BEFORE {$op} ON main.{$table} WHEN {$privacy}loyalty_plan_ok({$expr},'{$feature}','{$create}')=0 BEGIN SELECT RAISE(ABORT,'PLAN_RESTRICTED'); END");
        }
    }
}

/** Fail before external effects (mail, delivery, uploads) as well as DB writes. */
function policy_browser_guard(string $path,string $method): void
{
    if($method!=='POST')return;
    // Auth, billing and privacy are not paid features. The admin allowlist is separate.
    if($path==='/account/password' || $path==='/ai' || str_starts_with($path,'/ai/approval/') || $path==='/contact' || $path==='/support' || str_starts_with($path,'/support/'))return;
    if(str_starts_with($path,'/unsubscribe/') || in_array($path,['/member/preferences','/member/password','/member/profile','/member/wallet-nearby'],true))return;
    if(in_array($path,['/login','/register','/staff/login','/member/login','/billing','/billing/paypal','/member/delete','/forgot-password','/reset-password'],true) || str_starts_with($path,'/admin'))return;
    if(preg_match('#^/join/([A-F0-9]{8})$#',$path,$m)) {
        $q=db()->prepare('SELECT user_id FROM businesses WHERE join_code=?');$q->execute([$m[1]]);$uid=$q->fetchColumn();
        if($uid)require_plan_write((int)$uid,'members');return;
    }
    if(str_starts_with($path,'/staff/') && $path!=='/staff/create') {
        $staff=current_staff();if(!$staff)return;
        $q=db()->prepare('SELECT user_id FROM businesses WHERE id=?');$q->execute([$staff['business_id']]);require_plan_write((int)$q->fetchColumn(),'staff');return;
    }
    $u=current_user();if(!$u)return;
    $feature=match(true) {
        str_contains($path,'/design')=>'designer',
        str_ends_with($path,'/mail')=>'campaigns',
        $path==='/organization'=>'locations',
        str_ends_with($path,'/team')=>'staff',
        str_starts_with($path,'/campaign/') || str_starts_with($path,'/segment/')=>'campaigns',
        str_starts_with($path,'/webhook/')=>'webhooks',
        str_starts_with($path,'/integration/')=>'integrations',
        str_starts_with($path,'/apikey/')=>'api',
        str_starts_with($path,'/staff/')=>'staff',
        default=>'core',
    };
    require_plan_write((int)$u['id'],$feature);
}
