<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/functions_admin_delete_storage.php';

// Files may belong to unfinished posting forms. Only age-confirmed, abandoned
// uploads are eligible; an unavailable FTP MDTM is not proof of age.
function attach_shadow_expired_files($names, $now = null)
{
	global $upload_dir, $attach_config;
	$now = $now === null ? time() : (int) $now;
	$expired = array(); $connection = false;
	try
	{
		if (!$names) { return $expired; }
		if (intval($attach_config['allow_ftp_upload']))
		{
			if (!function_exists('ftp_mdtm')) { return $expired; }
			$connection = attach_init_ftp(false, true);
			if ($connection === false) { return $expired; }
		}
		foreach ($names as $name)
		{
			if (!is_string($name) || attach_ftp_listing_entry($name, '0') === false || !attach_inventory_file($name)) { continue; }
			if ($connection !== false) { $modified = @ftp_mdtm($connection, $name); }
			else
			{
				$directory=attach_local_storage_directory();if($directory===false){continue;}$path = $directory . '/' . $name;
				clearstatcache(true, $path);
				if (is_link($path) || !is_file($path)) { continue; }
				$modified = @filemtime($path);
			}
			if (is_int($modified) && $modified > 0 && $modified <= $now - 86400) { $expired[] = $name; }
		}
	}
	catch (Exception $exception) { return array(); }
	catch (Error $exception) { return array(); }
	finally { if ($connection !== false) { @ftp_close($connection); } }
	return $expired;
}

// Read-only guard retained for pending-PM checks outside Shadow cleanup.
function attach_shadow_query($database, $sql)
{
	global $lang;
	$result = $database->sql_query($sql);
	if (!$result) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_database_failed']); }
	return $result;
}

function attach_shadow_require_pm_idle($database, $id)
{
	global $lang;
	$result = attach_shadow_query($database, 'SELECT d.attach_id FROM ' . ATTACHMENTS_DESC_TABLE . ' d WHERE d.attach_id = ' . (int)$id
		. ' AND EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' p WHERE p.privmsgs_write_payload IS NOT NULL AND ('
		. 'HEX(p.privmsgs_write_token) = HEX(d.pm_write_token) OR EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE
		. ' a WHERE a.attach_id = d.attach_id AND a.privmsgs_id = p.privmsgs_id)))');
	$reserved = $database->sql_numrows($result) > 0; $database->sql_freeresult($result);
	if ($reserved) { message_die(GENERAL_ERROR, $lang['Attachment_shadow_pending']); }
}

function phpbb_attach_shadow_input($request)
{
 if(!is_array($request)){throw new PhpbbOwnedStorageFailure('Attachment_selection_invalid');}
 $ids=array_key_exists('attach_id_list',$request)?$request['attach_id_list']:array();$files=array_key_exists('attach_file_list',$request)?$request['attach_file_list']:array();
 if(!is_array($ids)||!is_array($files)||count($ids)+count($files)>1000||($files&&array_keys($files)!==range(0,count($files)-1))){throw new PhpbbOwnedStorageFailure('Attachment_selection_invalid');}
 if($ids){try{$ids=phpbb_attach_admin_delete_ids($ids);}catch(PhpbbOwnedStorageFailure$error){throw new PhpbbOwnedStorageFailure('Attachment_selection_invalid');}}
 $names=array();foreach($files as$value){if(!is_string($value)||strlen($value)>2040){throw new PhpbbOwnedStorageFailure('Attachment_selection_invalid');}$name=phpbb_request_raw_value($value);if(preg_match('//u',$name)!==1||preg_match_all('/./us',$name,$chars)>255||attach_ftp_listing_entry($name,'0')===false||!attach_inventory_file($name)||isset($names[$name])){throw new PhpbbOwnedStorageFailure('Attachment_selection_invalid');}$names[$name]=$name;}
 return array($ids,array_values($names));
}

class PhpbbAttachShadowStore extends PhpbbAttachAdminDeleteStore
{
 private $names;
 private $missing=array();
 private $bare=array();
 private $presence=array();
 function __construct($database,$actor,$names){$this->names=$names;parent::__construct($database,$actor);}
 function remove($ids,$confirm){if($confirm!==true){$this->storage_fail('Attachment_selection_invalid');}return parent::remove($ids,true);}
 protected function storage_route(){global$phpEx;return 'admin_attachments.'.$phpEx.'?mode=shadow';}
 protected function storage_fail($key='Attachment_shadow_database_failed')
 {
  $map=array('Owned_storage_failed'=>'Attachment_shadow_database_failed','Owned_storage_upgrade'=>'Attachment_admin_delete_upgrade','Attachment_admin_delete_invalid'=>'Attachment_selection_invalid','Attachment_admin_delete_failed'=>'Attachment_shadow_database_failed','Attachment_admin_delete_busy'=>'Attachment_shadow_pending');
  throw new PhpbbOwnedStorageFailure(isset($map[$key])?$map[$key]:$key);
 }
 private function inventory()
 {
  $main=attach_storage_file_entries(false,true);$thumb=attach_storage_file_entries(MODE_THUMBNAIL,true);if($main===false||$thumb===false){$this->storage_fail('Attachment_listing_failed');}
  $files=$thumbs=array();foreach($main as$row){if(attach_inventory_file($row['name'])){$files[$row['name']]=true;}}foreach($thumb as$row){$thumbs[$row['name']]=true;}return array($files,$thumbs);
 }
 private function pending_links($id)
 {
  if($this->rows('SELECT p.privmsgs_id FROM '.PRIVMSGS_TABLE.' p JOIN '.ATTACHMENTS_TABLE.' a ON a.privmsgs_id=p.privmsgs_id WHERE a.attach_id='.(int)$id.' AND p.privmsgs_write_payload IS NOT NULL LIMIT 1')){$this->storage_fail('Attachment_shadow_pending');}
 }
 protected function prepare_selection($ids)
 {
  list($files,$thumbs)=$this->inventory();$expired=attach_shadow_expired_files($this->names);$descs=$ids?$this->rows('SELECT * FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id IN ('.implode(',',$ids).') ORDER BY attach_id FOR UPDATE'):array();$known=array();
  foreach($descs as$row){$id=(int)$row['attach_id'];$known[$id]=true;$name=$row['physical_filename'];if(attach_ftp_listing_entry($name,'0')===false||!attach_inventory_file($name)){$this->storage_fail('Attachment_selection_invalid');}if($this->active_upload($row)){$this->storage_fail('Attachment_shadow_pending');}if(isset($files[$name])&&entry_exists($id,$this)){$this->storage_fail('Attachment_shadow_changed');}$this->presence[$name]=isset($files[$name]);if(isset($thumbs['t_'.$name])){$this->thumbnail_files[$name]=true;}}
  foreach($ids as$id){$this->pending_links($id);if(!isset($known[$id])){if(!$this->rows('SELECT attach_id FROM '.ATTACHMENTS_TABLE.' WHERE attach_id='.$id.' LIMIT 1 FOR UPDATE')){$this->storage_fail('Attachment_shadow_changed');}$this->missing[]=$id;}}
  // Validate the entire orphan selection before reserving any unregistered name.
  foreach($this->names as$name){if(!isset($files[$name])){$this->storage_fail('Attachment_shadow_changed');}if(!in_array($name,$expired,true)||attach_pm_stage_is_claimed($this,$name)){$this->storage_fail('Attachment_shadow_pending');}if($this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE." WHERE physical_filename='".$this->escape($name)."' LIMIT 1 FOR UPDATE")){$this->storage_fail('Attachment_shadow_changed');}}
  foreach($this->names as$name){
   // Avoid registering a new orphan under an existing dangling link ID.
   $last=$this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE.' ORDER BY attach_id DESC LIMIT 1 FOR UPDATE');$links=$this->rows('SELECT attach_id FROM '.ATTACHMENTS_TABLE.' ORDER BY attach_id DESC LIMIT 1 FOR UPDATE');$id=max($last?(int)$last[0]['attach_id']:0,$links?(int)$links[0]['attach_id']:0)+1;if($id>16777215){$this->storage_fail('Attachment_shadow_changed');}
   $escaped=$this->escape($name);$thumbnail=isset($thumbs['t_'.$name])?1:0;$this->query('INSERT INTO '.ATTACHMENTS_DESC_TABLE." (attach_id,physical_filename,real_filename,filesize,filetime,thumbnail) VALUES ($id,'$escaped','$escaped',0,0,$thumbnail)");$rows=$this->rows('SELECT * FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id='.$id.' FOR UPDATE');if(count($rows)!==1){$this->storage_fail();}$ids[]=$id;$descs[]=$rows[0];$this->bare[$name]=$id;$this->presence[$name]=true;
  }
  sort($ids,SORT_NUMERIC);usort($descs,function($a,$b){return(int)$a['attach_id']-(int)$b['attach_id'];});return array($ids,$descs);
 }
 protected function validate_locked_selection($ids,$descs)
 {
  parent::validate_locked_selection($ids,$descs);list($files,$thumbs)=$this->inventory();
  foreach($ids as$id){$this->pending_links($id);}foreach($descs as$row){if(isset($files[$row['physical_filename']])&&entry_exists((int)$row['attach_id'],$this)){$this->storage_fail('Attachment_shadow_changed');}}
  $this->check_presence($files);
 }
 private function check_presence($files)
 {
  foreach($this->presence as$name=>$present){if(isset($files[$name])!==$present){$this->storage_fail('Attachment_shadow_changed');}}
  if($this->bare&&count(attach_shadow_expired_files(array_map('strval',array_keys($this->bare))))!==count($this->bare)){$this->storage_fail('Attachment_shadow_pending');}
 }
 protected function finish_selection($ids)
 {
  if($this->missing&&$this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE.' WHERE attach_id IN ('.implode(',',$this->missing).')')){$this->storage_fail('Attachment_shadow_changed');}
  foreach($this->bare as$name=>$id){if($this->rows('SELECT attach_id FROM '.ATTACHMENTS_DESC_TABLE." WHERE physical_filename='".$this->escape($name)."' AND attach_id<>$id LIMIT 1")){$this->storage_fail('Attachment_shadow_changed');}}
  list($files,$thumbs)=$this->inventory();$this->check_presence($files);
 }
 protected function cleanup_ready($row)
 {
  list($files,$thumbs)=$this->inventory();$name=$row['physical_filename'];if(isset($this->presence[$name])&&!$this->presence[$name]&&isset($files[$name])){$this->storage_fail('Attachment_shadow_changed');}if(isset($this->bare[$name])&&isset($files[$name])&&!in_array($name,attach_shadow_expired_files(array($name)),true)){$this->storage_fail('Attachment_shadow_pending');}
 }
}

function phpbb_attach_shadow_remove($database,$request)
{
 $store=null;
 try{$actor=phpbb_attach_admin_request_actor($request);list($ids,$names)=phpbb_attach_shadow_input($request);if(!$ids&&!$names){return array('count'=>0,'cleanup_complete'=>true);}$store=new PhpbbAttachShadowStore($database,$actor,$names);return $store->remove($ids,true);}
 catch(PhpbbOwnedStorageFailure$error){if($store&&$store->commit_attempted&&!$store->confirmed){throw new PhpbbOwnedStorageFailure('Attachment_shadow_unconfirmed');}throw$error;}
 catch(Exception$error){throw new PhpbbOwnedStorageFailure($store&&$store->commit_attempted&&!$store->confirmed?'Attachment_shadow_unconfirmed':'Attachment_shadow_database_failed');}
 catch(Error$error){throw new PhpbbOwnedStorageFailure($store&&$store->commit_attempted&&!$store->confirmed?'Attachment_shadow_unconfirmed':'Attachment_shadow_database_failed');}
 finally{if($store){$store->release();}}
}

function attach_shadow_cleanup($posted_files,$posted_ids)
{
 global$db;$request=$_POST;$request['attach_file_list']=$posted_files;$request['attach_id_list']=$posted_ids;return phpbb_attach_shadow_remove($db,$request);
}
