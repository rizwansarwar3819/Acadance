<?php
declare(strict_types=1);
final class Database {
 public static function connection(): PDO {
  static $pdo=null; if($pdo instanceof PDO) return $pdo;
  $file=__DIR__.'/../database.php';
  if(!is_file($file)) throw new RuntimeException('Database config missing. Copy config/database.example.php to config/database.php and set credentials.');
  $c=require $file;
  $dsn=sprintf('mysql:host=%s;dbname=%s;charset=%s',$c['host'],$c['database'],$c['charset']??'utf8mb4');
  $pdo=new PDO($dsn,$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
  return $pdo;
 }
}
