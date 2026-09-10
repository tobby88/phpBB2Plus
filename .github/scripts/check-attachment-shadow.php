<?php
namespace ShadowCleanupFixture;
use RuntimeException;
use Exception;
use Error;
// Reuse the actual-writer database/file fixture, then exercise the ACP helper
// with controllable filesystem failures. No production database is loaded.
require __DIR__ . '/check-attachment-mutation.php';
require $forum_root . 'attach_mod/includes/functions_admin.php';
require_once $forum_root . 'attach_mod/includes/functions_pm_staging.php';
eval('namespace ShadowCleanupFixture; use Exception; use Error; ' . substr(file_get_contents($forum_root . 'attach_mod/includes/functions_shadow.php'), 5));
function attach_delete_file($name, $mode = false)
{
	if (is_callable($GLOBALS['shadow_file_hook'])) { call_user_func($GLOBALS['shadow_file_hook'], $name, $mode); }
	if ($GLOBALS['shadow_file_failure'] === ($mode ? 'thumb' : 'main')) { return false; }
	return \attach_delete_file($name, $mode);
}
function attach_storage_file_entries($mode = false, $quiet = false)
{
	return $GLOBALS['shadow_inventory_failure'] ? false : \attach_storage_file_entries($mode, $quiet);
}
function function_exists($name) { return $name === 'ftp_mdtm' ? true : \function_exists($name); }
function attach_init_ftp($mode = false, $quiet = false) { return $GLOBALS['shadow_ftp_connection']; }
function ftp_mdtm($connection, $name)
{
	if ($GLOBALS['shadow_ftp_time'] === 'throw') { throw new RuntimeException('Private FTP failure'); }
	return $GLOBALS['shadow_ftp_time'];
}
function ftp_close($connection) { $GLOBALS['shadow_ftp_closed']++; return true; }
$lang += array('Attachment_selection_invalid'=>'invalid','Attachment_listing_failed'=>'listing','Attachment_shadow_pending'=>'pending','Attachment_shadow_changed'=>'changed','Attachment_shadow_database_failed'=>'database');
$upload_dir = sys_get_temp_dir() . '/phpbb-shadow-' . uniqid('', true);
$shadow_file_failure = ''; $shadow_file_hook = null; $shadow_inventory_failure = false;
$shadow_ftp_connection = 1; $shadow_ftp_time = -1; $shadow_ftp_closed = 0;
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
function shadow_reset()
{
	global $mutation_server, $upload_dir, $shadow_file_failure, $shadow_file_hook, $shadow_inventory_failure, $attach_config;
	$mutation_server = new \MutationServer(); $shadow_file_failure = ''; $shadow_file_hook = null; $shadow_inventory_failure = false; $attach_config['allow_ftp_upload'] = 0;
	$mutation_server->pdo->exec('ALTER TABLE fixture_descriptions ADD COLUMN pm_write_token CHAR(32) DEFAULT NULL');
	$mutation_server->pdo->exec('ALTER TABLE fixture_messages ADD COLUMN privmsgs_write_token CHAR(32) DEFAULT NULL');
	$mutation_server->pdo->exec('ALTER TABLE fixture_messages ADD COLUMN privmsgs_write_payload TEXT DEFAULT NULL');
	foreach (array('fixture.txt', 'fresh.txt', 'thumbs/t_fixture.txt') as $name) { if (is_file($upload_dir . '/' . $name)) { unlink($upload_dir . '/' . $name); } }
	file_put_contents($upload_dir . '/fixture.txt', 'owned'); touch($upload_dir . '/fixture.txt', time() - 172800);
}
function shadow_publish()
{
	\mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment', 'pm', 20);
	return (int) $GLOBALS['mutation_server']->pdo->query('SELECT attach_id FROM fixture_descriptions')->fetchColumn();
}
try
{
	\mutation_check(mkdir($upload_dir,0700) && mkdir($upload_dir.'/thumbs',0700),'Owned shadow fixture directories');
	shadow_reset();
	attach_shadow_cleanup(array(),array());
	\mutation_check(!$mutation_server->connections,'Empty selection has no DB work');
	foreach (array(array(0),array('1 OR 1=1'),array(1,null),'1') as $ids) { \mutation_expect_failure(function () use ($ids) { attach_shadow_cleanup(array(),$ids); },'invalid'); }
	file_put_contents($upload_dir.'/fresh.txt','new');
	\mutation_expect_failure(function () { attach_shadow_cleanup(array('fixture.txt','fresh.txt'),array()); },'pending');
	\mutation_check(is_file($upload_dir.'/fixture.txt') && !$mutation_server->count_rows(ATTACHMENTS_DESC_TABLE),'Whole mixed-age selection rejected before mutations');
	$id=shadow_publish();
	\mutation_expect_failure(function () { attach_shadow_cleanup(array('fixture.txt'),array()); },'changed');
	\mutation_expect_failure(function () use ($id) { attach_shadow_cleanup(array(),array($id)); },'changed');
	\mutation_check($mutation_server->count_rows(ATTACHMENTS_TABLE)===1 && is_file($upload_dir.'/fixture.txt'),'Stale file and row selections preserve live attachment');
	$mutation_server->pdo->exec("INSERT INTO fixture_descriptions (physical_filename,thumbnail) VALUES ('orphan.txt',0)");
	$orphan=(int)$mutation_server->pdo->lastInsertId();
	\mutation_expect_failure(function () use ($orphan,$id) { attach_shadow_cleanup(array(),array($orphan,$id)); },'changed');
	\mutation_check($mutation_server->count_rows(ATTACHMENTS_DESC_TABLE)===2,'Valid orphan before stale row is not partially removed');
	\mutation_expect_failure(function () use ($id) { attach_shadow_cleanup(array(),array(999,$id)); },'changed');

	// A durable PN reservation is not an orphan even before its link INSERT.
	shadow_reset(); $id=shadow_publish(); $pending_token=str_repeat('a',32);
	$mutation_server->pdo->exec("UPDATE fixture_messages SET privmsgs_write_token='".$pending_token."',privmsgs_write_payload='pending' WHERE privmsgs_id=20");
	$mutation_server->pdo->exec("UPDATE fixture_descriptions SET pm_write_token='".$pending_token."' WHERE attach_id=".$id);
	$mutation_server->pdo->exec('DELETE FROM fixture_links');
	\mutation_expect_failure(function () use ($id) { attach_shadow_cleanup(array(),array($id)); },'pending');
	\mutation_check(is_file($upload_dir.'/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE)===1,'Pending unlinked description and bytes retained');
	$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_write_payload=NULL WHERE privmsgs_id=20');
	attach_shadow_cleanup(array(),array($id));
	\mutation_check(!is_file($upload_dir.'/fixture.txt') && !$mutation_server->count_rows(ATTACHMENTS_DESC_TABLE),'Retired orphan reservation can be cleaned normally');

	// File-only orphan reserves its name; concurrent publishers cannot interleave.
	shadow_reset();$stage='pm_8_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt';
	file_put_contents($upload_dir.'/'.$stage,'owned pending bytes');touch($upload_dir.'/'.$stage,time()-90000);
	try
	{
		$intent=json_encode(array('version'=>1,'content'=>array('attachments'=>array(array('id'=>0,'physical_filename'=>$stage)))));
		$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_write_payload='.$mutation_server->pdo->quote($intent).' WHERE privmsgs_id=20');
		\mutation_expect_failure(function()use($stage){attach_shadow_cleanup(array('fixture.txt',$stage),array());},'pending');
		\mutation_check(is_file($upload_dir.'/'.$stage)&&is_file($upload_dir.'/fixture.txt')&&!$mutation_server->count_rows(ATTACHMENTS_DESC_TABLE),'Accepted file-only intent protects whole selection before any cleanup reservation');
		$mutation_server->pdo->exec('UPDATE fixture_messages SET privmsgs_write_payload=NULL WHERE privmsgs_id=20');
		attach_shadow_cleanup(array($stage),array());
		\mutation_check(!is_file($upload_dir.'/'.$stage),'Expired stage can be cleaned after its pending claim is retired');
	}
	finally{if(is_file($upload_dir.'/'.$stage)){unlink($upload_dir.'/'.$stage);}}

	shadow_reset(); $called=false;
	$shadow_file_hook=function () use (&$called)
	{
		if ($called) { return; } $called=true;
		\mutation_expect_failure(function () { \mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment','pm',20); },'busy');
	};
	attach_shadow_cleanup(array('fixture.txt'),array());
	\mutation_check($called && !is_file($upload_dir.'/fixture.txt') && !$mutation_server->count_rows(ATTACHMENTS_DESC_TABLE),'Expired file-only orphan removed under reservation');

	foreach (array('main','thumb') as $failure)
	{
		shadow_reset(); file_put_contents($upload_dir.'/thumbs/t_fixture.txt','thumb'); $shadow_file_failure=$failure;
		\mutation_expect_failure(function () { attach_shadow_cleanup(array('fixture.txt'),array()); },'incomplete');
		\mutation_check(is_file($upload_dir.'/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE)===1,'Failed physical removal retains main and recovery description');
		$thumb=(int)$mutation_server->pdo->query('SELECT thumbnail FROM fixture_descriptions')->fetchColumn();
		\mutation_check($thumb===($failure==='thumb'?1:0),'Recovery records completed thumbnail removal');
		$shadow_file_failure=''; $id=(int)$mutation_server->pdo->query('SELECT attach_id FROM fixture_descriptions')->fetchColumn();
		attach_shadow_cleanup(array(),array($id));
		\mutation_check(!is_file($upload_dir.'/fixture.txt') && !$mutation_server->count_rows(ATTACHMENTS_DESC_TABLE),'Explicit recovery completes preserved orphan cleanup');
	}

	// Existing description with missing file; dangling parent; missing description.
	foreach (array('file','parent','description','zero') as $missing)
	{
		shadow_reset(); $id=shadow_publish();
		if ($missing==='file') { unlink($upload_dir.'/fixture.txt'); }
		if ($missing==='parent') { $mutation_server->pdo->exec('DELETE FROM fixture_messages WHERE privmsgs_id=20'); }
		if ($missing==='description') { $mutation_server->pdo->exec('DELETE FROM fixture_descriptions'); }
		if ($missing==='zero') { $mutation_server->pdo->exec('UPDATE fixture_links SET privmsgs_id=0,post_id=0'); }
		attach_shadow_cleanup(array(),array($id));
		\mutation_check(!$mutation_server->count_rows(ATTACHMENTS_TABLE) && !$mutation_server->count_rows(ATTACHMENTS_DESC_TABLE),'Clean classified orphan: '.$missing);
		if ($missing==='description') { \mutation_check(is_file($upload_dir.'/fixture.txt'),'Unknown file is not guessed from broken link'); }
		if ($missing==='file' || $missing==='description') { \mutation_check((int)$mutation_server->pdo->query('SELECT privmsgs_attachment FROM fixture_messages WHERE privmsgs_id=20')->fetchColumn()===0,'Broken live message flags synchronized'); }
	}
	shadow_reset(); $id=shadow_publish();
	$mutation_server->pdo->exec("INSERT INTO fixture_descriptions (physical_filename,thumbnail) VALUES ('fixture.txt',0)");
	$orphan=(int)$mutation_server->pdo->lastInsertId();
	attach_shadow_cleanup(array(),array($orphan));
	\mutation_check(is_file($upload_dir.'/fixture.txt') && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE)===1,'Orphan duplicate name cannot delete another live attachment');
	shadow_reset(); $shadow_inventory_failure=true;
	\mutation_expect_failure(function () { attach_shadow_cleanup(array('fixture.txt'),array()); },'listing');
	\mutation_check(!$mutation_server->count_rows(ATTACHMENTS_DESC_TABLE) && is_file($upload_dir.'/fixture.txt'),'Unavailable thumbnail inventory rejects before writes');
	shadow_reset(); $mutation_server->failure='INSERT INTO '.ATTACHMENTS_DESC_TABLE;
	\mutation_expect_failure(function () { attach_shadow_cleanup(array('fixture.txt'),array()); },'database');
	\mutation_check(is_file($upload_dir.'/fixture.txt'),'Reservation failure never deletes file');
	shadow_reset(); $called=false;
	$shadow_file_hook=function () use (&$called)
	{
		if ($called) { return; } $called=true;
		$GLOBALS['mutation_server']->owner->sql_close();
		\mutation_expect_failure(function () { \mutation_publisher('fixture.txt')->do_insert_attachment('last_attachment','pm',20); },'unavailable');
	};
	\mutation_expect_failure(function () { attach_shadow_cleanup(array('fixture.txt'),array()); },'database');
	\mutation_check($called && !$mutation_server->count_rows(ATTACHMENTS_TABLE) && $mutation_server->count_rows(ATTACHMENTS_DESC_TABLE)===1,'Reservation remains effective after owner loss during unlink');

	// Native local mtime and controlled FTP metadata failures/boundaries.
	shadow_reset(); $now=time();
	foreach (array($now-86399,$now+100,0) as $stamp)
	{
		touch($upload_dir.'/fixture.txt',$stamp);
		\mutation_check(attach_shadow_expired_files(array('fixture.txt'),$now)===array(),'Recent/future/invalid local age withheld');
	}
	touch($upload_dir.'/fixture.txt',$now-86400);
	\mutation_check(attach_shadow_expired_files(array('fixture.txt'),$now)===array('fixture.txt'),'Exact 24-hour local threshold');
	$attach_config['allow_ftp_upload']=1;
	foreach (array(-1,0,$now-86399,'throw',$now-86400) as $stamp)
	{
		$shadow_ftp_time=$stamp; $before=$shadow_ftp_closed;
		$expected=$stamp===$now-86400?array('fixture.txt'):array();
		\mutation_check(attach_shadow_expired_files(array('fixture.txt'),$now)===$expected && $shadow_ftp_closed===$before+1,'FTP age and connection cleanup');
	}
	$shadow_ftp_connection=false;
	\mutation_check(attach_shadow_expired_files(array('fixture.txt'),$now)===array(),'Failed FTP setup withholds file');
	foreach (array('german','english') as $language)
	{
		$strings=file_get_contents($forum_root.'language/lang_'.$language.'/lang_admin_attach.php');
		foreach (array('Attachment_shadow_pending','Attachment_shadow_changed','Attachment_shadow_database_failed') as $key) { \mutation_check(strpos($strings,"['".$key."']")!==false,'Localized shadow errors'); }
	}
	echo "Attachment shadow selection, upload grace, reservations, failure recovery and file/DB cleanup checks passed.\n";
}
finally
{
	if (isset($mutation_server) && $mutation_server->owner!==null) { $mutation_server->owner->sql_close(); }
	foreach (array('fixture.txt','fresh.txt','thumbs/t_fixture.txt') as $name) { if (is_file($upload_dir.'/'.$name)) { unlink($upload_dir.'/'.$name); } }
	if (is_dir($upload_dir.'/thumbs')) { rmdir($upload_dir.'/thumbs'); } if (is_dir($upload_dir)) { rmdir($upload_dir); }
	restore_error_handler();
}
