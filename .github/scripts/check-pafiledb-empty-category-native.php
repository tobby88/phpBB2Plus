<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_EMPTY_CATEGORY_NATIVE')!=='1'){echo "Empty download category fixture requires an explicitly enabled disposable database.\n";return;}
define('IN_ADMIN',true);putenv('PHPBB_PA_CATEGORY_ACTION_NATIVE=1');putenv('PHPBB_PA_FILE_COORDINATOR_NATIVE=1');
foreach(array('PORT','PASSWORD')as$key){$value=getenv('PHPBB_PA_EMPTY_CATEGORY_'.$key);if($value!==false){putenv('PHPBB_PA_FILE_COORDINATOR_'.$key.'='.$value);}}
require __DIR__.'/check-pafiledb-file-coordinator-native.php';
