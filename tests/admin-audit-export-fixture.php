<?php
function admin_audit_export_fixture(int $count=205): array
{
    $d=db();$d->exec('DELETE FROM audit_logs');
    $q=$d->prepare('INSERT OR IGNORE INTO users(name,email,password_hash)VALUES(?,?,?)');
    foreach([['Audit admin','admin@audit.invalid','adminpass1'],['Literal','literal%_!@audit.invalid','unused'],['Legacy','=SUM(1,2)@audit.invalid','unused']] as [$name,$email,$password])$q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
    $owner=(int)$d->query("SELECT id FROM users WHERE email='demo@loyalty.local'")->fetchColumn();
    $literal=(int)$d->query("SELECT id FROM users WHERE email='literal%_!@audit.invalid'")->fetchColumn();
    $risky=(int)$d->query("SELECT id FROM users WHERE email='=SUM(1,2)@audit.invalid'")->fetchColumn();
    $q=$d->prepare('INSERT INTO audit_logs(user_id,action,details,created_at)VALUES(?,?,?,?)');$d->exec('BEGIN');
    for($i=0;$i<$count;$i++)$q->execute([$owner,'fixture.export','PRIVATE-JSON-DETAILS','2026-11-01 12:00:00']);
    foreach([
        [$owner,'fixture.export.extra','2026-11-01 12:00:00'],
        [$owner,'fixture.boundary','2026-10-31 23:59:59'],
        [$owner,'fixture.boundary','2026-11-01 00:00:00'],
        [$owner,'fixture.boundary','2026-11-01 23:59:59'],
        [$owner,'fixture.boundary','2026-11-02 00:00:00'],
        [null,'fixture.missing_actor','2026-11-01 12:00:00'],
        [$owner,'fixture.literal','2026-11-01 12:00:00'],
        [$literal,'fixture.literal','2026-11-01 12:00:00'],
        [$owner,"=1+2,\"quoted\"\r\nsecond line",'2026-11-01 12:00:00'],
        [$risky,'fixture.account_formula','2026-11-01 12:00:00'],
        [$owner,'fixture.raw_time','2026-11-01T12:00:00'],
        [$owner,'fixture.raw_time','not-a-time'],
    ] as [$user,$action,$created])$q->execute([$user,$action,'PRIVATE-JSON-DETAILS',$created]);
    $q->execute([$owner,'fixture.huge_details',str_repeat('PRIVATE-LARGE-DETAILS',60000),'2026-11-01 12:00:00']);
    $d->exec('COMMIT');return ['owner'=>$owner,'literal'=>$literal,'risky'=>$risky,'rows'=>$count+13];
}
function admin_audit_export_state(): array
{
    $state=[];$tables=db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as $table){$rows=db()->query('SELECT * FROM "'.str_replace('"','""',$table).'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);$state[$table]=['count'=>count($rows),'hash'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];}
    return $state;
}
