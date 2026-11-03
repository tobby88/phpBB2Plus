<?php
require __DIR__.'/birthday-popup-fixture.php';
class BirthdayPopupNoDatabase{function __call($name,$args){throw new RuntimeException('Popup must not read or update database state: '.$name);}}
$db=new BirthdayPopupNoDatabase();
foreach(array('english','german')as$locale){bp_locale($locale);
    foreach(bp_vectors()as$vector){$days=bp_days($vector[0]);foreach(array($days,(string)$days)as$value){bp_verify(bp_run(bp_actor($value),$vector[1],$vector[2],$vector[3]),$vector);}}
    foreach(array(999999,20101103,-999999,999998,'','invalid',null,false,true,1.5,array(1),new stdClass(),'1e3','1000000','-1000000',(int)mkrealdate(1,1,1901)-1,(int)mkrealdate(31,12,2099)+1)as$value){bp_rejected(bp_actor($value));}
    $missing=bp_actor(0);unset($missing['user_birthday']);bp_rejected($missing);
    $guest=bp_actor(bp_days('19891103'));$guest['session_logged_in']=false;bp_rejected($guest);
    $anonymous=bp_actor(bp_days('19891103'));$anonymous['user_id']=-1;bp_rejected($anonymous);
    bp_rejected(bp_actor(bp_days('20991231')));
}
echo 'Birthday popup: ',$bp_cases,' cases, ',$bp_checks," assertions PASS; actual controller/template, canonical signed days, epoch zero, EN/DE, forum-local ages, belated/leap greetings and authorization.\n";
restore_error_handler();
