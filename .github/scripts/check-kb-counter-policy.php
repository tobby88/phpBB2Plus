<?php
define('IN_PHPBB',true);
require dirname(dirname(__DIR__)).'/phpBB2/includes/functions_kb_counter_policy.php';
function kbc_policy_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
foreach(array(array(1,1),array(-1,-1),array('+ 1',1),array('- 1',-1),array('+1',1),
 array(" \t+ 1\t",1),array('00000001',1),array('0',0),array('- 0',0),array(0,0),
 array(16777215,16777215),array('-16777215',-16777215))as$case){
 kbc_policy_check(kb_counter_delta($case[0])===$case[1],'Complete numeric delta/legacy-operator contract');
}
foreach(array(null,true,false,array(),new stdClass(),1.0,'','1.0','1e0','1 OR 1=1','+ +1',
 "1\0","1\n","+\r\n1",'16777216',-16777216,str_repeat(' ',33).'1')as$bad){
 kbc_policy_check(kb_counter_delta($bad)===null,'Invalid delta must not be partially parsed');
}
$root=dirname(dirname(__DIR__)).'/phpBB2/';$calls=0;
foreach(array('includes/kb_add.php','includes/kb_edit.php','includes/kb_moderator.php','admin/admin_kb_art.php','includes/functions_kb_delete_storage.php','includes/functions_kb_write_storage.php')as$file){
 $source=file_get_contents($root.$file);preg_match_all('/update_kb_number\([^;]+;/', $source,$matches);
 foreach($matches[0]as$call){kbc_policy_check(preg_match('/,\s*-?1\s*\);$/D',$call)===1,'Built-in article transition passes an integer delta');$calls++;}
}
kbc_policy_check($calls===3,'Review owned add/edit and delete numeric counter caller inventory');
$publication=file_get_contents($root.'includes/functions_kb_publication.php');
kbc_policy_check(substr_count($publication,'update_kb_number(')===1&&strpos($publication,'$approved ? 1 : -1')!==false,'Central approval owner supplies a numeric delta');
foreach(array('admin/admin_kb_art.php'=>'acp','includes/kb_moderator.php'=>'moderator')as$file=>$context){$source=file_get_contents($root.$file);foreach(array(0,1)as$approved){kbc_policy_check(strpos($source,"kb_publish_approval(\$db, \$article_id, ".$approved.", '".$context."')")!==false,'Both approval states use the exact current-authority owner');}}
$categories=array(
 array('category_id'=>1,'parent'=>0,'number_articles'=>'9'),
 array('category_id'=>2,'parent'=>1,'number_articles'=>'0'),
 array('category_id'=>3,'parent'=>2,'number_articles'=>null),
 array('category_id'=>4,'parent'=>0,'number_articles'=>'5'));
$before=$categories;$counts=array(2=>2,3=>3);$plan=kb_counter_rebuild_plan($categories,$counts);
kbc_policy_check($plan['expected']===array(1=>5,2=>5,3=>3,4=>0)&&count($plan['changes'])===4,'Rebuild counts approved articles through complete ancestor paths, including empty categories');
kbc_policy_check($categories===$before&&$counts===array(2=>2,3=>3),'Pure plan never changes input inventories');
foreach($categories as&$row){$row['number_articles']=$plan['expected'][$row['category_id']];}unset($row);
kbc_policy_check(kb_counter_rebuild_plan($categories,$counts)['changes']===array(),'Already correct counters are a no-op');
kbc_policy_check(kb_counter_rebuild_plan(array(),array())===array('expected'=>array(),'changes'=>array()),'Empty installation requires no data repair');
$bad_cases=array();
$bad=$categories;$bad[]=$bad[0];$bad_cases[]=array($bad,$counts);
$bad=$categories;$bad[0]['parent']=3;$bad_cases[]=array($bad,$counts);
$bad=$categories;$bad[0]['parent']=99;$bad_cases[]=array($bad,$counts);
$bad=$categories;$bad[0]['category_id']=0;$bad_cases[]=array($bad,$counts);
$bad=$categories;$bad[0]['number_articles']='1 OR 1=1';$bad_cases[]=array($bad,$counts);
$bad_cases[]=array($categories,array(99=>1));
$bad_cases[]=array($categories,array(2=>16777215,3=>1));
$bad_cases[]=array($categories,array(2=>-1));
foreach($bad_cases as$bad_case){$denied=false;try{kb_counter_rebuild_plan($bad_case[0],$bad_case[1]);}catch(RuntimeException $e){$denied=true;}kbc_policy_check($denied,'Ambiguous, cyclic, orphaned or overflowing inventories require review, not a partial repair');}
$deep=array();for($id=1;$id<=100;$id++){$deep[]=array('category_id'=>$id,'parent'=>$id-1,'number_articles'=>0);}
kbc_policy_check(kb_counter_rebuild_plan($deep,array(100=>1))['expected'][1]===1,'Maximum supported ancestor path is complete');
$deep[]=array('category_id'=>101,'parent'=>100,'number_articles'=>0);$denied=false;try{kb_counter_rebuild_plan($deep,array(101=>1));}catch(RuntimeException $e){$denied=true;}
kbc_policy_check($denied,'Unsupported depth is not silently truncated');
echo "Knowledge Base counter argument/caller checks passed\n";
