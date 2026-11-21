<?php
declare(strict_types=1);

/** Fixed-window atomic counters; no emails, PINs or API tokens in stored keys. */
function rate_limit_take(string $scope, string $identity, int $limit, int $seconds, ?int $now=null): bool
{
    $now ??= time();$window=intdiv($now,$seconds)*$seconds;
    $key=hash('sha256',$scope.'|'.$identity);
    $q=db()->prepare('INSERT INTO rate_limits (key,window_start,hits) VALUES (?,?,1) ON CONFLICT(key) DO UPDATE SET hits=CASE WHEN window_start=excluded.window_start THEN hits+1 ELSE 1 END,window_start=excluded.window_start RETURNING hits');
    $q->execute([$key,$window]);$hits=(int)$q->fetchColumn();$q->closeCursor();
    // Bound retained personal metadata. Hashed keys are not anonymization.
    db()->prepare('DELETE FROM rate_limits WHERE window_start<?')->execute([$now-86400]);
    return $hits<=$limit;
}

function request_ip(): string
{
    // Forwarded headers are untrusted. A trusted reverse proxy must configure REMOTE_ADDR.
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'local');
}

function rate_limit_stop(): never
{
    http_response_code(429);header('Retry-After: 900');header('Cache-Control: no-store');
    if(str_starts_with($_SERVER['REQUEST_URI'] ?? '','/api/')) {
        header('Content-Type: application/json');echo json_encode(['error'=>'rate_limited']);
    } else page('Zu viele Versuche','<div class="auth-box"><h1>Zu viele Versuche</h1><p>Bitte in 15 Minuten erneut versuchen.</p></div>');
    exit;
}

function auth_rate_guard(string $path,string $method): void
{
    if($method!=='POST')return;
    if(!in_array($path,['/login','/staff/login','/member/login','/register','/forgot-password','/reset-password'],true) && !str_starts_with($path,'/join/'))return;
    $identity=strtolower(trim((string)($_POST['email'] ?? $_POST['name'] ?? $_POST['token'] ?? '')));
    if(!rate_limit_take('auth-ip',request_ip(),60,900)
        || !rate_limit_take('auth-target:'.$path,request_ip().'|'.$identity,10,900)) rate_limit_stop();
}

function safe_session_start(): void
{
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    session_set_cookie_params([
        'lifetime'=>0,'path'=>'/',
        'secure'=>str_starts_with((string)config('base_url',''),'https://'),
        'httponly'=>true,'samesite'=>'Lax',
    ]);
    session_start();
    if (PHP_SAPI!=='cli') {
        $idle=(int)config('session_idle_seconds',7200);
        if(isset($_SESSION['last_active']) && time()-(int)$_SESSION['last_active']>$idle) {
            $_SESSION=[];session_regenerate_id(true);
        }
        $_SESSION['last_active']=time();
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; media-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if(str_starts_with((string)config('base_url',''),'https://'))header('Strict-Transport-Security: max-age=31536000');
    }
}

function application_exception(Throwable $e): never
{
    if(PHP_SAPI==='cli') { fwrite(STDERR, 'Operation failed: '.$e->getMessage().PHP_EOL);exit(1); }
    if(str_contains($e->getMessage(),'PLAN_RESTRICTED'))policy_http_error('Dein Plan erlaubt diese Änderung nicht oder ein Mengenlimit wurde erreicht.');
    $id=bin2hex(random_bytes(6));
    // Do not send file paths, SQL, access keys or exception text to clients.
    error_log('Loyalty error '.$id.' '.get_class($e));
    http_response_code(500);header('Cache-Control: no-store');
    if(str_starts_with($_SERVER['REQUEST_URI'] ?? '','/api/') || str_starts_with($_SERVER['REQUEST_URI'] ?? '','/integrations/')) {
        header('Content-Type: application/json');echo json_encode(['error'=>'internal_error','reference'=>$id]);
    } else page('Interner Fehler','<h1>Interner Fehler</h1><p>Bitte erneut versuchen. Referenz: '.h($id).'</p>');
    exit;
}
