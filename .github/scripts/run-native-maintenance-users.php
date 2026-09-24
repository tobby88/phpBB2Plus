<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_GUEST_PROFILE_NATIVE')!=='1'){exit(2);}
mysqli_report(MYSQLI_REPORT_OFF);
$port=getenv('PHPBB_GUEST_PROFILE_PORT')?:'3306';
if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Invalid fixture port');}
$password=getenv('PHPBB_GUEST_PROFILE_PASSWORD')?:'';
$control=mysqli_connect('127.0.0.1','root',$password,'',(int)$port);
if(!$control){throw new RuntimeException('Loopback fixture unavailable');}
$fixture='codex_session_reset_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
if(!mysqli_query($control,'CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')){throw new RuntimeException('Fixture creation failed');}
putenv('PHPBB_SESSION_RESET_TEST_DSN=mysql:host=127.0.0.1;port='.$port.';dbname='.$fixture.';charset=utf8mb4');
putenv('PHPBB_SESSION_RESET_TEST_PASSWORD='.$password);
try{require __DIR__.(isset($argv[1])&&$argv[1]==='erc'?'/check-profile-guest.php':'/check-maintenance-users.php');}
finally{
 if(!mysqli_query($control,'DROP DATABASE '.$fixture)){throw new RuntimeException('Owned fixture cleanup failed');}
 mysqli_close($control);putenv('PHPBB_SESSION_RESET_TEST_DSN');putenv('PHPBB_SESSION_RESET_TEST_PASSWORD');
}
