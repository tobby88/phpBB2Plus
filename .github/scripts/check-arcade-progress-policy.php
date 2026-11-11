<?php
require __DIR__.'/arcade-import-fixture.php';require_once $as_app.'includes/functions_arcade_progress_storage.php';
class ProgressNoDatabase {var $calls=0;function sql_dedicated_connection(){$this->calls++;throw new RuntimeException('Invalid progress input must not open storage');}}
$userdata=array('user_id'=>2,'session_id'=>'fixture');$actor=$userdata;$cases=0;
foreach(array('', '0'," Grüße 😀 &#39; &amp; + %2B \0 \\ \r\n ",str_repeat('x',65535),str_repeat('😀',16383).'xyz')as$data){
 profile_fixture_request(array('gameData'=>$data));as_check(phpbb_arcade_progress_request($HTTP_GET_VARS,$HTTP_POST_VARS,'gameData')===$data,'Actual common.php normalization decoded once; opaque bytes unchanged');
 phpbb_arcade_progress_validate('saveGame',str_repeat('a',32),'41',$data,$actor);$cases++;
}
as_check(phpbb_arcade_progress_request(array(),array(),'gid','0')==='0','Missing optional game ID uses historical latest-game protocol');
as_check(phpbb_arcade_progress_request(array('gid'=>'42'),array('gid'=>'41'),'gid')==='42','Existing GET precedence preserved');
foreach(array(array(array(),array(),'gameData'),array(array('gameData'=>array()),array('gameData'=>'valid'),'gameData'),array(array(),array('gameData'=>null),'gameData'))as$args){$failed=false;try{call_user_func_array('phpbb_arcade_progress_request',$args);}catch(PhpbbOwnedStorageFailure$e){$failed=true;}as_check($failed,'Missing/nested explicit data never silently selects another request source');$cases++;}
$valid=array('saveGame',str_repeat('a',32),'41','progress');$bad=array();
foreach(array('', 'storeScore',false,array())as$v){$row=$valid;$row[0]=$v;$bad[]=$row;}
foreach(array('short',str_repeat('A',32),array(),null)as$v){$row=$valid;$row[1]=$v;$bad[]=$row;}
foreach(array('00','-1','41suffix','8388608',41,array())as$v){$row=$valid;$row[2]=$v;$bad[]=$row;}
foreach(array(str_repeat('x',65536),str_repeat('😀',16384),"bad\xff",array(),null,false)as$v){$row=$valid;$row[3]=$v;$bad[]=$row;}
foreach($bad as$args){$fake=new ProgressNoDatabase();$failed=false;try{phpbb_arcade_progress_transfer($fake,$args[0],$args[1],$args[2],$args[3]);}catch(PhpbbOwnedStorageFailure$e){$failed=true;}as_check($failed&&$fake->calls===0,'Invalid operation/token/ID/data rejected before storage');$cases++;}
foreach(array(-1,0,'02',16777216,array(),null)as$id){$userdata=$actor;$userdata['user_id']=$id;$fake=new ProgressNoDatabase();$failed=false;try{phpbb_arcade_progress_transfer($fake,'loadGame','','0');}catch(PhpbbOwnedStorageFailure$e){$failed=true;}as_check($failed&&$fake->calls===0,'Guest/invalid account cannot open private progress storage');$cases++;}
foreach(array('',str_repeat('x',33),array(),null)as$sid){$userdata=$actor;$userdata['session_id']=$sid;$fake=new ProgressNoDatabase();$failed=false;try{phpbb_arcade_progress_transfer($fake,'loadGame','','0');}catch(PhpbbOwnedStorageFailure$e){$failed=true;}as_check($failed&&$fake->calls===0,'Invalid forum session rejected before storage');$cases++;}
echo 'Arcade progress policy: '.$cases.' cases, '.$as_checks." assertions PASS; exact normalized raw input, full byte budget and rejection before SQL.\n";
