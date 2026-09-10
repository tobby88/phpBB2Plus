<?php
$fixture=str_replace("\r\n","\n",file_get_contents(__DIR__.'/check-pm-write-attachments.php'));
$end=strpos($fixture,"\nset_error_handler(");
if($end===false){throw new RuntimeException('Attachment fixture boundary missing');}
eval(substr($fixture,5,$end-5));
require_once $root.'includes/functions_pm_attachment_edit.php';
function pm_edit_storage_fixture($engine)
{
	pm_attachment_fixture($engine);
	$GLOBALS['pm_repair_server']->pdo->exec("UPDATE fixture_descriptions SET physical_filename='original.txt',real_filename='original.txt',comment='Original',extension='txt',mimetype='text/plain',filesize=5,filetime=123,thumbnail=1,download_count=17 WHERE attach_id=1");
	file_put_contents($GLOBALS['upload_dir'].'/original.txt','owned');
	file_put_contents($GLOBALS['upload_dir'].'/thumbs/t_original.txt','thumbnail');
}
function pm_edit_storage_run($thumbnail=false)
{
	global $db;
	$lock=attach_require_mutation_lock($db);
	try
	{
		$compose=new PhpbbPmComposeDatabase($lock->connection,'edit',12);
		$entry=pm_attachment_data(); $entry=$entry['attachments'][1]; unset($entry['id']);
		return phpbb_pm_edit_attachment($compose,1,$thumbnail?array('thumbnail'=>0):$entry,!$thumbnail);
	}
	finally{$lock->release();}
}
pm_repair_check(mkdir($upload_dir.'/thumbs',0700),'Owned thumbnail directory');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine)
	{
		foreach(array('english','german') as $locale)
		{
			include $root.'language/lang_'.$locale.'/lang_main.php'; include $root.'language/lang_'.$locale.'/lang_main_attach.php';
			foreach(array('shared','unshared','same-file','thumbnail') as $case)
			{
				pm_edit_storage_fixture($engine);
				if($case==='unshared'||$case==='same-file'){$pm_repair_server->pdo->exec('DELETE FROM fixture_links WHERE privmsgs_id=18');}
				if($case==='same-file'){$pm_repair_server->pdo->exec("INSERT INTO fixture_descriptions (attach_id,physical_filename,thumbnail) VALUES (99,'original.txt',1)");}
				$id=pm_edit_storage_run($case==='thumbnail');
				pm_repair_check($id!==1 && pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=12 AND attach_id='.$id)===1,'Only selected link changes');
				pm_repair_check(is_file($upload_dir.'/original.txt')===($case!=='unshared') && is_file($upload_dir.'/thumbs/t_original.txt')===($case!=='unshared'),'Native shared original/thumbnail preserved; unreferenced old bytes cleaned');
				pm_repair_check(pm_repair_value('SELECT download_count FROM fixture_descriptions WHERE attach_id='.$id)===17,'Copy preserves download count');
				if($case==='shared'||$case==='thumbnail'){pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=18 AND attach_id=1')===1,'Other copy retains original registration');}
			}
			foreach(array('INSERT INTO fixture_descriptions ','UPDATE fixture_links SET attach_id','DELETE FROM fixture_descriptions') as $boundary)
			{
				foreach(array(false,true) as $lost_ack)
				{
					pm_edit_storage_fixture($engine);
					// Same physical file under another registration also exercises
					// orphan-description cleanup without deleting reserved bytes.
					$pm_repair_server->pdo->exec('DELETE FROM fixture_links WHERE privmsgs_id=18');
					$pm_repair_server->pdo->exec("INSERT INTO fixture_descriptions (attach_id,physical_filename,thumbnail) VALUES (99,'original.txt',1)");
					if($lost_ack)
					{
						$pm_repair_server->hook=function($sql)use($boundary)
						{
							if(strpos($sql,$boundary)!==0){return;} $s=$GLOBALS['pm_repair_server'];$s->hook=null;$s->pdo->exec($sql);$s->failure=$boundary;
						};
					}
					else{$pm_repair_server->failure=$boundary;}
					mailbox_storage_fail(function(){pm_edit_storage_run();},$lang['PM_cleanup_failed']);
					pm_repair_check(is_file($upload_dir.'/original.txt') && is_file($upload_dir.'/thumbs/t_original.txt'),'Failure never removes independently registered bytes');
					pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_links WHERE privmsgs_id=12')===1,'One usable link after any failure boundary');
				}
			}
			echo $engine.' '.$locale." PM attachment copy-on-write storage checks passed.\n";
		}
	}
}
finally
{
	if(isset($pm_repair_server)&&$pm_repair_server->owner!==null){$pm_repair_server->owner->sql_close();}
	foreach(array('original.txt','thumbs/t_original.txt','pm_1_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.txt') as $file){if(is_file($upload_dir.'/'.$file)){unlink($upload_dir.'/'.$file);}}
	rmdir($upload_dir.'/thumbs');rmdir($upload_dir);restore_error_handler();
}
