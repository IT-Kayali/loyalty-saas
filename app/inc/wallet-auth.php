<?php
declare(strict_types=1);

/** Capture the legacy credential before its API-key input changes. Caller owns the write lock. */
function wallet_auth_preserve_business(PDO $pdo,int $businessId,string $oldKey): void
{
    if($oldKey==='')throw new RuntimeException('Cannot preserve pass authentication without its previous key.');
    $query=$pdo->prepare('SELECT id,code FROM members WHERE business_id=?');
    $query->execute([$businessId]);
    $insert=$pdo->prepare('INSERT INTO member_wallet_auth(member_id,auth_token,image_token)VALUES(?,?,?) ON CONFLICT(member_id) DO NOTHING');
    while($member=$query->fetch())$insert->execute([(int)$member['id'],hash_hmac('sha256','pass:'.$member['code'],$oldKey),hash_hmac('sha256','stamp-image:'.$member['code'],$oldKey)]);
}

/** Freeze existing nonempty-key legacy credentials once, before any supported rotation. */
function wallet_auth_migrate(PDO $pdo): void
{
    $check=$pdo->prepare("SELECT value FROM platform_settings WHERE key='migration_wallet_auth_v1'");
    $check->execute();$state=$check->fetchColumn();
    if($state!==false){if($state!=='1')throw new RuntimeException('Wallet credential migration state is invalid.');return;}
    $pdo->exec('BEGIN IMMEDIATE');
    try{
        $check->execute();$state=$check->fetchColumn();
        if($state!==false&&$state!=='1')throw new RuntimeException('Wallet credential migration state is invalid.');
        if($state===false){
            $businesses=$pdo->query("SELECT id,api_key FROM businesses WHERE api_key IS NOT NULL AND api_key<>''")->fetchAll();
            foreach($businesses as $business)wallet_auth_preserve_business($pdo,(int)$business['id'],(string)$business['api_key']);
            $pdo->exec("INSERT INTO platform_settings(key,value)VALUES('migration_wallet_auth_v1','1')");
        }
        $pdo->exec('COMMIT');
    }catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
}

/** New members get an independent random credential on first use; concurrent calls converge. */
function wallet_member_credential(array $member,string $purpose): string
{
    $column=match($purpose){'pass'=>'auth_token','image'=>'image_token',default=>throw new InvalidArgumentException('Unknown Wallet credential purpose.')};
    $pdo=db();$id=(int)$member['id'];
    $query=$pdo->prepare('SELECT '.$column.' FROM member_wallet_auth WHERE member_id=?');
    $query->execute([$id]);$token=$query->fetchColumn();
    if($token===false){
        $pdo->prepare('INSERT INTO member_wallet_auth(member_id,auth_token,image_token)VALUES(?,?,?) ON CONFLICT(member_id) DO NOTHING')->execute([$id,bin2hex(random_bytes(32)),bin2hex(random_bytes(32))]);
        $query->execute([$id]);$token=$query->fetchColumn();
    }
    if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Stored pass authentication is invalid.');
    return $token;
}

function wallet_auth_for_member(array $member): string { return wallet_member_credential($member,'pass'); }
function wallet_image_token(array $member): string { return wallet_member_credential($member,'image'); }

/** Initialize missing keys, never freeze a publicly derivable empty-key credential. */
function wallet_auth_fill_empty_business_keys(PDO $pdo): void
{
    if(!$pdo->query("SELECT 1 FROM businesses WHERE api_key IS NULL OR api_key='' LIMIT 1")->fetchColumn())return;
    $pdo->exec('BEGIN IMMEDIATE');
    try{
        $query=$pdo->query("SELECT id,api_key FROM businesses WHERE api_key IS NULL OR api_key=''");
        $rows=$query->fetchAll();
        $update=$pdo->prepare('UPDATE businesses SET api_key=? WHERE id=?');
        foreach($rows as $business){
            $update->execute([bin2hex(random_bytes(24)),(int)$business['id']]);
        }
        $pdo->exec('COMMIT');
    }catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
}
