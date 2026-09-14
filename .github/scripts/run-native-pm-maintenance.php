<?php
// Only creates its own randomly named loopback fixture; never reads forum config.
if(PHP_SAPI!=='cli'||getenv('PHPBB_PM_MAINTENANCE_NATIVE')!=='1'){exit(2);}
$nativeMode=isset($argv[1])?$argv[1]:'';
if(!in_array($nativeMode,array('counter','repair'),true)){throw new RuntimeException('Unknown PM fixture');}
mysqli_report(MYSQLI_REPORT_OFF);
$nativePort=getenv('PHPBB_PM_MAINTENANCE_TEST_PORT')?:'3306';
if(!preg_match('/^[0-9]{1,5}$/D',$nativePort)||(int)$nativePort<1||(int)$nativePort>65535){throw new RuntimeException('Invalid fixture port');}
$nativeAdmin=mysqli_connect('127.0.0.1','root',getenv('PHPBB_PM_MAINTENANCE_TEST_PASSWORD')?:'','',(int)$nativePort);
if(!$nativeAdmin){throw new RuntimeException('Loopback fixture server unavailable');}
$nativeFixtureName='codex_pm_'.$nativeMode.'_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
if(!mysqli_query($nativeAdmin,'CREATE DATABASE '.$nativeFixtureName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')){throw new RuntimeException('Fixture creation failed');}
$nativeDsnVariable='PHPBB_PM_'.strtoupper($nativeMode).'_TEST_DSN';
putenv($nativeDsnVariable.'=mysql:host=127.0.0.1;port='.$nativePort.';dbname='.$nativeFixtureName.';charset=utf8mb4');
try{require __DIR__.'/check-pm-'.$nativeMode.'-session.php';}
finally{mysqli_query($nativeAdmin,'DROP DATABASE '.$nativeFixtureName);mysqli_close($nativeAdmin);putenv($nativeDsnVariable);}
