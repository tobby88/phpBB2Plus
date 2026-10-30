<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_file_delete.php';

function pafiledb_category_action_rows($storage,$sql)
{
    $r=$storage->sql_query($sql);try{return$storage->sql_fetchrowset($r);}finally{$storage->sql_freeresult($r);}
}
function pafiledb_category_action_parent($row){return$row['cat_parent']===null?0:PaFileDbFileAuthority::id($row['cat_parent'],true);}
function pafiledb_category_action_ancestry($tree,$id)
{
    $seen=array();while($id!==0){if(isset($seen[$id])||!isset($tree[$id])){pafiledb_file_fail('PA_category_tree_invalid');}$seen[$id]=true;$id=pafiledb_category_action_parent($tree[$id]);}
}
function pafiledb_category_action_order($storage,$parent,$move_id=0,$direction=0)
{
    $where=$parent===0?'(cat_parent=0 OR cat_parent IS NULL)':'cat_parent='.$parent;
    $rows=pafiledb_category_action_rows($storage,'SELECT cat_id FROM '.PA_CATEGORY_TABLE.' WHERE '.$where.' ORDER BY cat_order,cat_id FOR UPDATE');$ids=array();foreach($rows as$row){$ids[]=PaFileDbFileAuthority::id($row['cat_id']);}
    if($move_id){$index=array_search($move_id,$ids,true);if($index===false){pafiledb_file_fail('PA_category_changed');}$other=$index+$direction;if(isset($ids[$other])){$ids[$index]=$ids[$other];$ids[$other]=$move_id;}}
    if(count($ids)>214748364){pafiledb_file_fail('PA_category_invalid');}$order=10;
    foreach($ids as$id){$storage->sql_query('UPDATE '.PA_CATEGORY_TABLE.' SET cat_order='.$order.' WHERE cat_id='.$id);$order+=10;}
}

// All current descendants, their files, permission rows and physical intents
// belong to one transaction under the same lease as upload/edit/mirror writers.
// The visible request model and its historical ancestry cache are not authority.
function pafiledb_category_mutate($model,$action,$id,$values=array())
{
    global$db,$table_prefix,$phpbb_root_path,$lang;
    $storage=$journal=null;$operation=null;$result=null;$root=realpath($phpbb_root_path);$url='';
    try{
        $id=PaFileDbFileAuthority::id($id);$actor=pafiledb_mutation_actor();
        if(!defined('IN_ADMIN')||!IN_ADMIN||!in_array($action,array('delete','clear-files','order'),true)||!is_array($values)){pafiledb_file_fail('PA_category_invalid');}
        $storage=new PaFileDbFileCoordinator($db,$table_prefix);$storage->begin(true);$authority=new PaFileDbFileAuthority($storage,$table_prefix,$actor,'categories');$authority->select('category',array());$storage->guard_commit(function()use($authority){return$authority->check();});
        $tree=array();foreach(pafiledb_category_action_rows($storage,'SELECT * FROM '.PA_CATEGORY_TABLE.' ORDER BY cat_id FOR UPDATE')as$row){$key=PaFileDbFileAuthority::id($row['cat_id']);$tree[$key]=$row;}
        if(!isset($tree[$id])){pafiledb_file_fail('Cat_not_exist');}pafiledb_category_action_ancestry($tree,$id);$parent=pafiledb_category_action_parent($tree[$id]);
        if($action==='order'){
            if(!isset($values['move'])||!(is_int($values['move'])||is_string($values['move']))||!in_array((string)$values['move'],array('-15','15'),true)){pafiledb_file_fail('PA_category_invalid');}
            pafiledb_category_action_order($storage,$parent,$id,(string)$values['move']==='-15'?-1:1);
        }else{
            $scope=array($id=>$id);$file_mode='delete';$subcat_mode='delete';$file_to=$subcat_to=0;
            if($action==='delete'){
                foreach(array('file_mode','subcat_mode')as$key){if(!isset($values[$key])||!is_string($values[$key])||!in_array($values[$key],array('delete','move'),true)){pafiledb_file_fail('PA_category_invalid');}}
                $file_mode=$values['file_mode'];$subcat_mode=$values['subcat_mode'];
                $descendants=array($id=>$id);$front=array($id);
                while($front){$next=array();foreach($tree as$key=>$row){if(in_array(pafiledb_category_action_parent($row),$front,true)){if(isset($descendants[$key])){pafiledb_file_fail('PA_category_tree_invalid');}$descendants[$key]=$key;$next[]=$key;}}$front=$next;}
                if($subcat_mode==='delete'){$scope=$descendants;}
                $future=$tree;
                if($subcat_mode==='move'){
                    $subcat_to=PaFileDbFileAuthority::id(isset($values['subcat_to_cat_id'])?$values['subcat_to_cat_id']:null,true);
                    if(isset($descendants[$subcat_to])||($subcat_to&&!isset($tree[$subcat_to]))){pafiledb_file_fail('PA_category_tree_invalid');}
                    pafiledb_category_action_ancestry($tree,$subcat_to);
                    foreach($tree as$key=>$row){if(pafiledb_category_action_parent($row)===$id){if($subcat_to&&empty($tree[$subcat_to]['cat_allow_file'])&&empty($row['cat_allow_file'])){pafiledb_file_fail('Cat_conflict');}$future[$key]['cat_parent']=$subcat_to;}}
                }
                foreach($scope as$key){unset($future[$key]);}
                if($file_mode==='move'){
                    $file_to=PaFileDbFileAuthority::id(isset($values['file_to_cat_id'])?$values['file_to_cat_id']:null);
                    if(!isset($future[$file_to])||(string)$future[$file_to]['cat_allow_file']!==(string)PA_CAT_ALLOW_FILE){pafiledb_file_fail('PA_category_tree_invalid');}pafiledb_category_action_ancestry($future,$file_to);
                }
            }
            $files=array();foreach(array_chunk(array_values($scope),500)as$chunk){foreach(pafiledb_category_action_rows($storage,'SELECT * FROM '.PA_FILES_TABLE.' WHERE file_catid IN('.implode(',',$chunk).') ORDER BY file_id FOR UPDATE')as$row){$files[PaFileDbFileAuthority::id($row['file_id'])]=$row;}}
            if($file_mode==='delete'&&$files){
                if($root===false||!function_exists('phpbb_board_url')){pafiledb_file_fail();}$url=phpbb_board_url();$settings=$authority->settings();$journal=new PaFileDbFileJournal($storage,$table_prefix,$actor['id'],pafiledb_file_directories($settings),$url,$root);$operation=$journal->operation_id();
                pafiledb_file_delete_rows($storage,$journal,$files,$settings,$root);
            }elseif($file_mode==='move'){
                foreach(array_chunk(array_keys($files),500)as$chunk){$storage->sql_query('UPDATE '.PA_FILES_TABLE.' SET file_catid='.$file_to.' WHERE file_id IN('.implode(',',$chunk).')');if((int)$storage->sql_affectedrows()!==count($chunk)){pafiledb_file_fail();}}
            }
            if($action==='delete'){
                if($subcat_mode==='move'){
                    foreach($tree as$key=>$row){if(pafiledb_category_action_parent($row)===$id){$storage->sql_query('UPDATE '.PA_CATEGORY_TABLE.' SET cat_parent='.$subcat_to.' WHERE cat_id='.$key);}}
                }
                foreach(array_chunk(array_values($scope),500)as$chunk){$list=implode(',',$chunk);$storage->sql_query('DELETE FROM '.PA_AUTH_ACCESS_TABLE.' WHERE cat_id IN('.$list.')');$storage->sql_query('DELETE FROM '.PA_CATEGORY_TABLE.' WHERE cat_id IN('.$list.')');if((int)$storage->sql_affectedrows()!==count($chunk)){pafiledb_file_fail();}}
                pafiledb_category_action_order($storage,$parent);if($subcat_mode==='move'&&$parent!==$subcat_to){pafiledb_category_action_order($storage,$subcat_to);}
            }
            if($action==='delete'||$files){$storage->sql_query('UPDATE '.PA_CATEGORY_TABLE." SET cat_files=-1,cat_last_file_id=0,cat_last_file_name='',cat_last_file_time=0");}
        }
        $authority->check();$storage->commit();$result=true;
    }catch(PaFileDbFileActionFailure$error){$key=$error->getMessage();$model->error[]=isset($lang[$key])?$lang[$key]:$lang['PA_category_failed'];}
    catch(PaFileDbMutationFailure$error){$key=$error->getMessage();$model->error[]=isset($lang[$key])?$lang[$key]:$lang['PA_category_failed'];}
    catch(Exception$error){$model->error[]=$lang['PA_category_failed'];}catch(Error$error){$model->error[]=$lang['PA_category_failed'];}
    finally{
        $model->modified(true);$model->modified(false);if($storage){$storage->release();}if($operation!==null&&$root!==false&&$url!==''){pafiledb_file_recover($db,$table_prefix,$operation,$root,$url);}
    }
    if($result===true){$model->init();}return$result;
}
