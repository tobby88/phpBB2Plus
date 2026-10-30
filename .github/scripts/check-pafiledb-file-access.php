<?php
define('IN_PHPBB',true);$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';$table_prefix='fixture_';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'pafiledb/includes/functions_toplist.php';
function pfa_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['pfa_cases']++;}
function pfa_fixture(){return(object)array('cat_rowset'=>array(1=>array('cat_parent'=>0),2=>array('cat_parent'=>1)),'auth'=>array(1=>array('auth_view'=>true,'auth_read'=>true,'auth_view_file'=>true),2=>array('auth_view'=>true,'auth_read'=>true,'auth_view_file'=>true)));}
class PfaNoSql{function sql_query($query){throw new RuntimeException('File access helpers must not query or write a database');}}
$db=new PfaNoSql();$pfa_cases=0;set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $p=pfa_fixture();pfa_check(pafiledb_file_category_accessible($p,2),'Readable leaf and parent');pfa_check(pafiledb_file_category_accessible($p,'2'),'Canonical SQL string identity');pfa_check(pafiledb_toplist_categories($p)===array(1,2),'Lists use the same helper');
 foreach(array(0,-1,2147483648,'02','2x',true,null,array())as$id){pfa_check(!pafiledb_file_category_accessible($p,$id),'Reject malformed direct category identity');}
 foreach(array('auth_view','auth_read','auth_view_file')as$field){$p=pfa_fixture();$p->auth[2][$field]=false;pfa_check(!pafiledb_file_category_accessible($p,2),'Every leaf grant is required');}
 foreach(array('auth_view','auth_read')as$field){$p=pfa_fixture();$p->auth[1][$field]=false;pfa_check(!pafiledb_file_category_accessible($p,2),'Every ancestor view/read grant is required');}
 $p=pfa_fixture();$p->auth[1]['auth_view_file']=false;pfa_check(pafiledb_file_category_accessible($p,2),'Ancestor file-specific grant does not replace its view/read requirements');
 $p=pfa_fixture();unset($p->cat_rowset[1]);pfa_check(!pafiledb_file_category_accessible($p,2),'Missing ancestor denied');
 $p=pfa_fixture();$p->cat_rowset[1]['cat_parent']=2;pfa_check(!pafiledb_file_category_accessible($p,2),'Cycle denied');
 $p=pfa_fixture();unset($p->auth[2]);pfa_check(!pafiledb_file_category_accessible($p,2),'Missing leaf policy denied');
 foreach(array(null,0,'0')as$parent){$p=pfa_fixture();$p->cat_rowset[2]['cat_parent']=$parent;pfa_check(pafiledb_file_category_accessible($p,2),'Canonical root forms accepted');}
 foreach(array(-1,'bad','2147483648')as$parent){$p=pfa_fixture();$p->cat_rowset[2]['cat_parent']=$parent;pfa_check(!pafiledb_file_category_accessible($p,2),'Invalid parent identity denied');}
 $p=pfa_fixture();$file=array('file_approved'=>0,'file_catid'=>2,'user_id'=>8);$viewer=array('session_logged_in'=>true,'user_id'=>8,'user_level'=>USER);
 pfa_check(pafiledb_download_approval_allowed($p,$file,$viewer),'Authenticated owner may download pending file');
 $viewer['user_id']=9;pfa_check(!pafiledb_download_approval_allowed($p,$file,$viewer),'Other member has no pending-file exception');
 $p->auth[2]['auth_mod']=true;pfa_check(pafiledb_download_approval_allowed($p,$file,$viewer),'Current category moderator exception');
 $p->auth[2]['auth_mod']=false;$viewer['user_level']=ADMIN;
 foreach(array(true,1,'1',false,0,'0','false',2,null,array())as$flag){$viewer['session_logged_in']=$flag;pfa_check(pafiledb_download_approval_allowed($p,$file,$viewer)===in_array($flag,array(true,1,'1'),true),'Only strict authenticated flags grant administrator exception');}
 $viewer['session_logged_in']=true;foreach(array(0,-1,'09','9x',true,array(),null,2147483648)as$id){$viewer['user_id']=$id;pfa_check(!pafiledb_download_approval_allowed($p,$file,$viewer),'Invalid account identity cannot grant approval exception');}
 $file['file_approved']=1;pfa_check(pafiledb_download_approval_allowed($p,$file,array()),'Approved-file status needs no account exception; caller still checks category policy');
 $file['file_approved']=2;pfa_check(!pafiledb_download_approval_allowed($p,$file,array()),'Unexpected status cannot bypass normal approval');
 echo 'File access helpers: ',$pfa_cases," actual category/ancestry/approval/account/role cases PASS; no database.\n";
}finally{restore_error_handler();}
