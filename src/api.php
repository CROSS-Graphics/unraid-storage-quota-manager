<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';
require_once __DIR__.'/update.php';
ignore_user_abort(true);
set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); throw new SQM\Fault('POST required'); }
    // The route is served exclusively by Unraid's authenticated admin nginx location.
    // Token is also checked here, independently of nginx's request handling.
    $var=parse_ini_file('/var/local/emhttp/var.ini');
    if (!SQM\requestCsrf($_POST,$_SERVER,$var['csrf_token']??null)) { http_response_code(403); throw new SQM\Fault('Invalid CSRF token; refresh the page'); }
    if (!function_exists('posix_geteuid') || posix_geteuid()!==0) throw new SQM\Fault('Unraid privileged PHP runtime required');
    $m=new SQM\Manager(new SQM\System());
    $action=$_POST['action']??'';
    $input=json_decode($_POST['data']??'{}',true,32,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new SQM\Fault('Invalid request');
    if ($action==='status') {$result=$m->locked(fn()=>$m->status());$result['update']=SQM\cachedPluginUpdate();}
    elseif ($action==='logs') {
        $lines=@file('/var/log/syslog',FILE_IGNORE_NEW_LINES)?:[];
        $result=array_slice(array_values(array_filter($lines,fn($s)=>str_contains($s,'quota-manager'))),-150);
    } else $result=$m->action($action,$input);
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (http_response_code()<400) http_response_code(400);
    error_log('quota-manager: '.$e->getMessage());
    if (isset($m)) $m->sys->log('Request failed: '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE);
}
