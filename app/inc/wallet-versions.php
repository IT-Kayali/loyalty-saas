<?php
declare(strict_types=1);

/** Local change metadata, not a delivery receipt. Never stores pass text or credentials. */
function apple_pass_tick(PDO $db): int
{
    $db->exec('UPDATE apple_pass_clock SET revision=revision+1 WHERE id=1');
    return (int)$db->query('SELECT revision FROM apple_pass_clock WHERE id=1')->fetchColumn();
}
function apple_pass_content_hash(array $member,?array $payload=null): string
{
    $assets=[];
    foreach(['logo','stamp_image'] as $field){
        $name=(string)($member['design'][$field] ?? '');
        $path=(string)config('upload_dir',ROOT_DIR.'/data/uploads').'/'.basename($name);
        $assets[$field]=[$name,$name!==''&&is_file($path)&&is_readable($path)?hash_file('sha256',$path):null];
    }
    return wallet_push_hash(['format'=>'apple-pass-content-v1','pass'=>$payload??apple_pass_json($member),'accent'=>$member['design']['accent_color'],'letter'=>mb_strtoupper(mb_substr($member['business_name'],0,1)),'assets'=>$assets,'signer'=>wallet_message_provider_identity('apple')]);
}
/** Caller holds BEGIN IMMEDIATE. Separate from the wrapper to avoid nested transactions. */
function apple_pass_snapshot_locked(array $member,?array $payload=null): array
{
    $db=db();$hash=apple_pass_content_hash($member,$payload);
    $q=$db->prepare('SELECT * FROM apple_pass_versions WHERE member_id=?');$q->execute([$member['id']]);$old=$q->fetch();
    if(!$old||!hash_equals($old['content_hash'],$hash)){
        $now=time();$revision=apple_pass_tick($db);
        // Old deployments and same-second changes cannot safely share an HTTP date validator.
        $safe=$old&&$now>(int)$old['date_highwater'];
        $highwater=max($now,(int)($old['date_highwater'] ?? 0));
        $db->prepare('INSERT INTO apple_pass_versions(member_id,content_hash,revision,modified_at,date_highwater,date_safe)VALUES(?,?,?,?,?,?) ON CONFLICT(member_id) DO UPDATE SET content_hash=excluded.content_hash,revision=excluded.revision,modified_at=excluded.modified_at,date_highwater=excluded.date_highwater,date_safe=excluded.date_safe')->execute([$member['id'],$hash,$revision,$now,$highwater,(int)$safe]);
        $q->execute([$member['id']]);$old=$q->fetch();
    }
    $generation=(string)$db->query('SELECT generation FROM apple_pass_clock WHERE id=1')->fetchColumn();
    return $old+['generation'=>$generation,'etag'=>'W/"apple-'.hash_hmac('sha256','etag.'.$old['revision'],hex2bin($generation)).'"'];
}
function apple_pass_snapshot(array $member): array
{
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try{$result=apple_pass_snapshot_locked($member);$db->exec('COMMIT');return $result;}
    catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Already authenticated by the route. New registrations receive their own catch-up revision. */
function apple_pass_register(string $device,string $type,string $serial,string $token): bool
{
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT id,registration_revision FROM pass_devices WHERE device_library_id=? AND pass_type_id=? AND serial=?');$q->execute([$device,$type,$serial]);$old=$q->fetch();
        $generation=apple_pass_tick($db);
        $revision=$old&&(int)$old['registration_revision']>0?(int)$old['registration_revision']:$generation;
        if(!pass_member($serial))throw new InvalidArgumentException('Pass nicht mehr vorhanden.');
        // A separate monotonic registration fence changes even for an identical token.
        // Keep the existing catch-up revision, row identity and original creation date.
        $registeredAt=(int)floor(microtime(true)*1000);
        $db->prepare('INSERT INTO pass_devices(device_library_id,pass_type_id,serial,push_token,registration_revision,registration_generation,registered_at_ms)VALUES(?,?,?,?,?,?,?) ON CONFLICT(device_library_id,pass_type_id,serial) DO UPDATE SET push_token=excluded.push_token,registration_revision=excluded.registration_revision,registration_generation=excluded.registration_generation,registered_at_ms=excluded.registered_at_ms')->execute([$device,$type,$serial,$token,$revision,$generation,$registeredAt]);
        $db->exec('COMMIT');return (bool)$old;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Opaque authenticated cursors do not expose the global cross-tenant counter. */
function apple_pass_cursor(int $revision,string $device,string $type,string $secret): string
{
    $nonce=random_bytes(12);$tag='';
    $cipher=openssl_encrypt(sprintf('%019d',$revision),'aes-256-gcm',hex2bin($secret),OPENSSL_RAW_DATA,$nonce,$tag,$device."\0".$type);
    if($cipher===false)throw new RuntimeException('Pass cursor encryption failed.');
    return 'v1.'.rtrim(strtr(base64_encode($nonce.$cipher.$tag),'+/','-_'),'=');
}
function apple_pass_cursor_revision(?string $cursor,string $device,string $type,string $secret): ?int
{
    if($cursor===null||!preg_match('/^v1\.([A-Za-z0-9_-]{63})$/D',$cursor,$match))return null;
    $raw=base64_decode(strtr($match[1],'-_','+/'),true);if($raw===false||strlen($raw)!==47)return null;
    $plain=openssl_decrypt(substr($raw,12,19),'aes-256-gcm',hex2bin($secret),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,-16),$device."\0".$type);
    if(!is_string($plain)||!preg_match('/^[0-9]{19}$/D',$plain)||strcmp($plain,sprintf('%019d',PHP_INT_MAX))>0)return null;
    return (int)$plain;
}
/** Null means HTTP 204. Unknown/old/foreign-generation cursors safely request a full resync. */
function apple_pass_changes(string $device,string $type,?string $since): ?array
{
    if($type!==(string)config('apple_wallet.pass_type_id',''))return null;
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT serial,registration_revision FROM pass_devices WHERE device_library_id=? AND pass_type_id=? ORDER BY serial');$q->execute([$device,$type]);$registrations=$q->fetchAll();$versions=[];
        foreach($registrations as $row){$member=pass_member($row['serial']);if(!$member)continue;$snapshot=apple_pass_snapshot_locked($member);$versions[]=['serial'=>$row['serial'],'revision'=>max((int)$snapshot['revision'],(int)$row['registration_revision'])];}
        $clock=$db->query('SELECT generation,revision FROM apple_pass_clock WHERE id=1')->fetch();$previous=null;
        $previous=apple_pass_cursor_revision($since,$device,$type,$clock['generation']);
        if($previous!==null&&$previous>(int)$clock['revision'])$previous=null;
        $serials=[];$latest=0;
        foreach($versions as $row){if($previous===null||$row['revision']>$previous){$serials[]=$row['serial'];$latest=max($latest,$row['revision']);}}
        $db->exec('COMMIT');return $serials?['lastUpdated'=>apple_pass_cursor($latest,$device,$type,$clock['generation']),'serialNumbers'=>$serials]:null;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function apple_pass_not_modified(array $snapshot,?string $etagHeader,?string $dateHeader): bool
{
    if($etagHeader!==null){
        if(strlen($etagHeader)>4096)return false;
        foreach(explode(',',$etagHeader) as $candidate){$candidate=trim($candidate);if($candidate==='*'||preg_replace('/^W\//','',$candidate)===substr($snapshot['etag'],2))return true;}
        return false; // If-None-Match takes precedence over If-Modified-Since.
    }
    if(!$snapshot['date_safe']||$dateHeader===null||strlen($dateHeader)>100)return false;
    $parsed=DateTimeImmutable::createFromFormat(DATE_RFC7231,$dateHeader,new DateTimeZone('UTC'));
    if(!$parsed||$parsed->format(DATE_RFC7231)!==$dateHeader)return false;
    $date=$parsed->getTimestamp();
    return $date<=time()&&$date>=(int)$snapshot['modified_at'];
}
/** Authorization and configured signing readiness are checked before this function. */
function apple_pass_response(array $member,bool $requireAuthorization=false): never
{
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try{
        $current=pass_member($member['code']);
        if(!$current){$db->exec('ROLLBACK');not_found();}
        if($requireAuthorization)pass_service_auth($member['code']);
        $member=$current;$payload=apple_pass_json($member);$snapshot=apple_pass_snapshot_locked($member,$payload);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    header('Cache-Control: private, no-cache');header('Vary: Authorization');header('Content-Type: application/vnd.apple.pkpass');
    header('ETag: '.$snapshot['etag']);header('Last-Modified: '.gmdate('D, d M Y H:i:s',min(time(),(int)$snapshot['modified_at'])).' GMT');
    if(apple_pass_not_modified($snapshot,$_SERVER['HTTP_IF_NONE_MATCH'] ?? null,$_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null)){http_response_code(304);exit;}
    header('Content-Disposition: attachment; filename="'.$member['code'].'.pkpass"');
    echo apple_pkpass($member,$payload);exit;
}
