<?php
declare(strict_types=1);
final class Auth {
 public static function user(): ?array { return $_SESSION['user']??null; }
 public static function requireUser(): array { $u=self::user();if(!$u)Response::json(['error'=>'Authentication required.'],401);return $u; }
 public static function role(): string { return (string)(self::user()['role']??'student'); }
 public static function isAdmin(): bool { return self::role()==='admin'; }
 public static function requireAdmin(): array { $u=self::requireUser();if(!self::isAdmin())Response::json(['error'=>'Admin access required.'],403);return $u; }
 public static function csrfToken(): string { if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));return $_SESSION['csrf_token']; }
 public static function verifyCsrf(): void { $t=$_SERVER['HTTP_X_CSRF_TOKEN']??'';if(!$t||!hash_equals(self::csrfToken(),$t))Response::json(['error'=>'Invalid security token. Refresh and try again.'],419); }
}
