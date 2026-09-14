<?php
// Explicit CI/local fixture runner. Never loads a forum config or accepts a
// caller-selected database name; only owns its fresh random loopback database.
if(PHP_SAPI!=='cli'||getenv('PHPBB_REBUILD_NATIVE')!=='1'){exit(2);}
mysqli_report(MYSQLI_REPORT_OFF);
$nativePort=getenv('PHPBB_REBUILD_TEST_PORT')?:'3306';
if(!preg_match('/^[0-9]{1,5}$/D',$nativePort)||(int)$nativePort<1||(int)$nativePort>65535){throw new RuntimeException('Invalid fixture port');}
$nativeAdmin=mysqli_connect('127.0.0.1','root',getenv('PHPBB_REBUILD_TEST_PASSWORD')?:'','',(int)$nativePort);
if(!$nativeAdmin){throw new RuntimeException('Loopback fixture server unavailable');}
$nativeFixtureName='codex_rebuild_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
if(!mysqli_query($nativeAdmin,'CREATE DATABASE `'.$nativeFixtureName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')){throw new RuntimeException('Fixture creation failed');}
putenv('PHPBB_REBUILD_TEST_DSN=mysql:host=127.0.0.1;port='.$nativePort.';dbname='.$nativeFixtureName.';charset=utf8mb4');
try{require __DIR__.'/check-rebuild-session-authority.php';}
finally{mysqli_query($nativeAdmin,'DROP DATABASE `'.$nativeFixtureName.'`');mysqli_close($nativeAdmin);putenv('PHPBB_REBUILD_TEST_DSN');}
