<?php
require __DIR__.'/arcade-statistics-fixture.php';
$count=0;
foreach(array('reset_played','reset_last_player','reset_last_time')as$mode){as_check(phpbb_arcade_statistics_mode($mode)===$mode,'Preserved statistics action');$count++;}
foreach(array(null,false,true,0,1,array('reset_played'),'','RESET_PLAYED','reset_played ','reset_scores')as$bad){$failed=false;try{phpbb_arcade_statistics_mode($bad);}catch(PhpbbOwnedStorageFailure$e){$failed=true;}as_check($failed,'Reject malformed statistics action');$count++;}
$routes=jr_admin_authorization_routes();$keys=array_keys($routes,'admin_arcade_reset.php?mode=home',true);as_check(count($keys)===1,'Existing exact registered Reset module');
foreach(array('english','german')as$locale){ag_language($locale);foreach(array('title','help','confirm','reset_played','reset_last_player','reset_last_time','done','failed')as$key){as_check(isset($lang['arcade_statistics_'.$key])&&$lang['arcade_statistics_'.$key]!==''&&preg_match('//u',$lang['arcade_statistics_'.$key])===1,'Complete UTF-8 statistics translation '.$locale.'/'.$key);}$count++;}
echo 'Arcade statistics inputs: '.$count.' cases, '.$as_checks." assertions PASS; strict actions, exact existing ACP route and EN/DE captions.\n";
