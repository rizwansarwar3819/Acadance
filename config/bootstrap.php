<?php
declare(strict_types=1);
$c=require __DIR__.'/app.php';session_name($c['session_name']);
session_set_cookie_params(['httponly'=>true,'secure'=>$c['session_secure'],'samesite'=>'Lax','path'=>'/']);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once __DIR__.'/core/Database.php';require_once __DIR__.'/core/Response.php';require_once __DIR__.'/core/Auth.php';
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: strict-origin-when-cross-origin');
