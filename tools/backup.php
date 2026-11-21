<?php
/** Operator-only consistent backup/restore. Never exposed as an HTTP route. */
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
umask(0077);
$root=dirname(__DIR__);$data=$root.'/data';
if(!is_dir($data))mkdir($data,0700,true);
$lock=fopen($data.'/.maintenance.lock','c');
if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('Maintenance lock unavailable');
require $root.'/app/bootstrap.php';
$action=$argv[1] ?? '';$archive=$argv[2] ?? '';
if(!in_array($action,['backup','restore'],true) || $archive==='') {
    fwrite(STDERR,"Usage: php tools/backup.php backup /private/path/backup.zip\n       php tools/backup.php restore /private/path/backup.zip --yes\nBackups include secrets. Keep them private and encrypted at rest.\n");exit(2);
}
if(!class_exists(ZipArchive::class))throw new RuntimeException('ext-zip is required for backup/restore');
$parent=realpath(dirname($archive));
if(!$parent || str_starts_with($parent.DIRECTORY_SEPARATOR,realpath($root.'/public').DIRECTORY_SEPARATOR))throw new RuntimeException('Archive must be outside public/');
$archive=$parent.'/'.basename($archive);
$stage=sys_get_temp_dir().'/loyalty-maintenance-'.bin2hex(random_bytes(12));mkdir($stage,0700);
function clean_tree(string $path):void {
    if(!is_dir($path))return;
    foreach(new FilesystemIterator($path) as $f) {
        if($f->isDir() && !$f->isLink())clean_tree($f->getPathname());else unlink($f->getPathname());
    }
    rmdir($path);
}
function private_copy(string $src,string $dest):void {
    if(is_link($src) || !is_file($src))throw new RuntimeException('Invalid source file');
    if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);
    if(!copy($src,$dest))throw new RuntimeException('Could not copy file');chmod($dest,0600);
}
try {
    if($action==='backup') {
        if(file_exists($archive))throw new RuntimeException('Refusing to overwrite an existing backup');
        $snapshot=$stage.'/app.sqlite';
        db()->exec('VACUUM INTO '.db()->quote($snapshot));chmod($snapshot,0600);
        $zip=new ZipArchive();if($zip->open($archive,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create backup');
        $manifest=['version'=>1,'created_at'=>gmdate('c'),'files'=>[]];
        $files=['app.sqlite'=>$snapshot];
        if(is_file($data.'/mail-signing.key'))$files['mail-signing.key']=$data.'/mail-signing.key';
        if(is_file($root.'/config.php'))$files['config.php']=$root.'/config.php';
        foreach(['uploads','apple','google'] as $folder) {
            $dir=$folder==='uploads'?(string)config('upload_dir',$data.'/uploads'):$data.'/'.$folder;
            if(!is_dir($dir))continue;
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
            foreach($it as $f) {
                if($f->isLink())throw new RuntimeException('Symlink in backup source');
                if($f->isFile())$files[$folder.'/'.substr($f->getPathname(),strlen($dir)+1)]=$f->getPathname();
            }
        }
        foreach($files as $name=>$src){$zip->addFile($src,$name);$manifest['files'][$name]=hash_file('sha256',$src);}
        $zip->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));$zip->close();chmod($archive,0600);
        echo "Backup created. Contains database, images, wallet keys and config. Protect this file as a secret.\n";
    } else {
        if(($argv[3] ?? '')!=='--yes')throw new RuntimeException('Restore replaces database and files. Review a trusted backup and pass --yes.');
        if(!is_file($archive)||is_link($archive))throw new RuntimeException('Backup file missing or unsafe');
        $zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('Cannot open backup');
        $raw=$zip->getFromName('manifest.json');$manifest=is_string($raw)?json_decode($raw,true):null;
        if(($manifest['version'] ?? 0)!==1 || !is_array($manifest['files'] ?? null) || !isset($manifest['files']['app.sqlite']))throw new RuntimeException('Invalid manifest');
        $total=0;
        for($i=0;$i<$zip->numFiles;$i++) {
            $stat=$zip->statIndex($i);$name=$stat['name'];$total+=(int)$stat['size'];
            if($total>1024*1024*1024 || (int)$stat['size']>512*1024*1024)throw new RuntimeException('Backup exceeds safety limits');
            if($name==='manifest.json')continue;
            if(!preg_match('#^(app\.sqlite|config\.php|mail-signing\.key|(?:uploads|apple|google)/[A-Za-z0-9_./-]+)$#D',$name) || str_contains($name,'..') || !isset($manifest['files'][$name]))throw new RuntimeException('Unsafe backup member');
            $bytes=$zip->getFromIndex($i);if(!is_string($bytes)||!hash_equals($manifest['files'][$name],hash('sha256',$bytes)))throw new RuntimeException('Backup checksum mismatch');
            $dest=$stage.'/new/'.$name;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);file_put_contents($dest,$bytes);chmod($dest,0600);
        }
        $zip->close();
        foreach($manifest['files'] as $name=>$hash)if(!is_file($stage.'/new/'.$name))throw new RuntimeException('Backup incomplete');
        $test=new PDO('sqlite:'.$stage.'/new/app.sqlite');
        if($test->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Database integrity check failed');
        if(!$test->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn())throw new RuntimeException('Not a loyalty database');
        $test=null;
        $dbPath=(string)config('db_path',$data.'/app.sqlite');$uploads=(string)config('upload_dir',$data.'/uploads');
        if(is_link($dbPath)||is_link($uploads))throw new RuntimeException('Restore target is a symlink');
        // The application lock excludes HTTP requests. Preserve an automatic rollback bundle.
        $rollback=$data.'/restore-rollback-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));mkdir($rollback,0700);
        if(is_file($dbPath))private_copy($dbPath,$rollback.'/app.sqlite');
        $hadMailKey=is_file($data.'/mail-signing.key');if($hadMailKey)private_copy($data.'/mail-signing.key',$rollback.'/mail-signing.key');
        $targets=['uploads'=>$uploads,'apple'=>$data.'/apple','google'=>$data.'/google'];$moved=[];$touched=[];
        try {
            foreach($targets as $kind=>$target) {
                if(is_dir($target)){if(!rename($target,$rollback.'/'.$kind))throw new RuntimeException('Cannot preserve previous files');$moved[$kind]=true;$touched[$kind]=true;}
                if(is_dir($stage.'/new/'.$kind)) {$touched[$kind]=true;if(!rename($stage.'/new/'.$kind,$target))throw new RuntimeException('Cannot install restored files');}
            }
            // No db() call before replacement: no open app connection/WAL to the old DB.
            foreach(['-wal','-shm'] as $suffix)if(file_exists($dbPath.$suffix))throw new RuntimeException('WAL files present. Checkpoint and shut down external writers first.');
            private_copy($stage.'/new/app.sqlite',$dbPath.'.restore-tmp');
            if(!rename($dbPath.'.restore-tmp',$dbPath))throw new RuntimeException('Cannot install restored database');
            if(is_file($stage.'/new/mail-signing.key'))private_copy($stage.'/new/mail-signing.key',$data.'/mail-signing.key');
            if(is_file($stage.'/new/config.php'))private_copy($stage.'/new/config.php',$data.'/restored-config.php');
        }catch(Throwable $e) {
            foreach($targets as $kind=>$target){if(!isset($touched[$kind]))continue;clean_tree($target);if(isset($moved[$kind]))rename($rollback.'/'.$kind,$target);}
            if($hadMailKey)private_copy($rollback.'/mail-signing.key',$data.'/mail-signing.key');
            elseif(is_file($data.'/mail-signing.key'))unlink($data.'/mail-signing.key');
            if(is_file($rollback.'/app.sqlite'))private_copy($rollback.'/app.sqlite',$dbPath);
            throw $e;
        }
        echo "Restore complete. Previous data preserved in data/restore-rollback-*. Config was NOT activated; merge data/restored-config.php manually. Verify configuration, sign-in and wallet/Stripe settings before serving users.\n";
    }
}finally {clean_tree($stage);flock($lock,LOCK_UN);fclose($lock);}
