<?php
define('IN_PHPBB',true);
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require$root.'includes/php_compat.php';require$root.'pafiledb/includes/functions.php';require$root.'pafiledb/includes/functions_pafiledb.php';
function append_sid($url,$html=true){return$url;}
class PaNavTemplate{public $rows=array();function assign_block_vars($block,$data){if($block!=='navlinks'){throw new RuntimeException('Unexpected navigation block');}$this->rows[]=$data;}}
class PaNavNoSql{function sql_query($sql){throw new RuntimeException('Navigation must not read or write its old cache');}}
function pn_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['pn_cases']++;}
function pn_render($policy,$id){$GLOBALS['pafiledb_template']=new PaNavTemplate();$policy->generate_category_nav($id);return$GLOBALS['pafiledb_template']->rows;}
function pn_category($id,$parent,$name,$cache=''){return array('cat_id'=>$id,'cat_parent'=>$parent,'cat_name'=>$name,'parents_data'=>$cache);}
$db=new PaNavNoSql();$pn_cases=0;$policy=new pafiledb();
set_error_handler(function($severity,$message){throw new RuntimeException($message);});
try{
 $policy->cat_rowset=array(1=>pn_category(1,0,'<Root & current>'),2=>pn_category(2,1,'Current parent'),3=>pn_category(3,2,'Current leaf',serialize(array(99=>'Stale secret',1=>'Old root'))));
 $policy->auth=array(1=>array('auth_view'=>true,'auth_read'=>true),2=>array('auth_view'=>true,'auth_read'=>false),3=>array('auth_view'=>true,'auth_read'=>true));
 $rows=pn_render($policy,3);
 pn_check(array_column($rows,'CAT_NAME')===array('&lt;Root &amp; current&gt;','Current parent','Current leaf'),'Current escaped ancestry replaces every cached label');
 pn_check(array_column($rows,'U_VIEW_CAT')===array('dload.php?action=category&cat_id=1','dload.php?action=category&cat_id=2','dload.php?action=category&cat_id=3'),'Current canonical integer identities in root-to-leaf order');
 pn_check(count($rows)===3,'Visible folder without read permission remains in navigation');
 foreach(array('', 'not serialized',serialize(array('1" onclick="bad'=>'Private stale cache')),serialize(array(5=>array('nested'=>'secret'))))as$cache){
  $policy->cat_rowset[3]['parents_data']=$cache;pn_check(pn_render($policy,3)===$rows,'Empty/malformed/stale cache does not affect output or cause SQL');
 }
 $policy->cat_rowset[2]['cat_name']='Renamed parent';pn_check(pn_render($policy,3)[1]['CAT_NAME']==='Renamed parent','Rename visible immediately without invalidating legacy cache');
 $policy->auth[2]['auth_view']=false;$only=pn_render($policy,3);pn_check(count($only)===1&&$only[0]['CAT_NAME']==='Current leaf','Hidden ancestor and ancestors beyond it are omitted');
 $policy->auth[2]['auth_view']=true;$missing=$policy->cat_rowset[2];unset($policy->cat_rowset[2]);pn_check(pn_render($policy,3)===$only,'Missing ancestor keeps only the reachable current leaf');$policy->cat_rowset[2]=$missing;
 $policy->cat_rowset[3]['cat_parent']=1;pn_check(count(pn_render($policy,3))===2,'Moved leaf uses new current parent');
 $policy->cat_rowset[3]['cat_parent']=3;pn_check(pn_render($policy,3)===$only,'Self-cycle terminates without duplicate current leaf');
 $policy->cat_rowset[3]['cat_parent']=2;$policy->cat_rowset[1]['cat_parent']=2;pn_check(pn_render($policy,3)===$only,'Ancestor cycle terminates and omits inconsistent path');
 $policy->cat_rowset[1]['cat_parent']=0;
 foreach(array(0,-1,99,'invalid',array())as$id){pn_check(pn_render($policy,$id)===array(),'Invalid/missing current identity emits no navigation');}
 $policy->auth[3]['auth_view']=false;pn_check(pn_render($policy,3)===array(),'No navigation for a hidden current category');unset($policy->auth[3]);pn_check(pn_render($policy,3)===array(),'Missing current authorization emits no navigation');
 // A long valid path is iterative and preserves the complete current ancestry.
 $policy->cat_rowset=$policy->auth=array();
 for($id=1;$id<=2000;$id++){$policy->cat_rowset[$id]=pn_category($id,$id-1,'Category '.$id);$policy->auth[$id]=array('auth_view'=>true);}
 $long=pn_render($policy,2000);pn_check(count($long)===2000&&$long[0]['CAT_NAME']==='Category 1'&&$long[1999]['CAT_NAME']==='Category 2000','Deep valid tree remains complete without recursive calls');
 $policy->cat_rowset[1]['cat_parent']=1999;$cycle=pn_render($policy,2000);pn_check(count($cycle)===1&&$cycle[0]['CAT_NAME']==='Category 2000','Deep cycle remains bounded');
 echo 'Download category navigation: ',$pn_cases," actual-method/current-path/visibility/cache/cycle/no-SQL cases PASS.\n";
}finally{restore_error_handler();}
