<?php
$fixture=str_replace("\r\n","\n",file_get_contents(__DIR__.'/check-pm-compose-attachments.php'));
$end=strpos($fixture,'mutation_check(mkdir($upload_dir');
if ($end===false) { throw new RuntimeException('Compose definitions missing'); }
eval(substr($fixture,5,$end-5));
class PmEditUploadFixture extends attach_pm
{
	function upload_attachment()
	{
		$this->compose_database->require_write(); $GLOBALS['pm_edit_uploads']++;
		$this->filename='replacement.txt'; $this->attach_filename='pm_8_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee.txt';
		$this->extension='txt'; $this->type='text/plain'; $this->filesize=11; $this->filetime=123; $this->thumbnail=0;
		file_put_contents($GLOBALS['upload_dir'].'/'.$this->attach_filename,'replacement');
	}
}
function pm_edit_fixture($thumbnail=false)
{
	pm_compose_fixture(); pm_compose_post(); $GLOBALS['pm_edit_uploads']=0;
	if ($thumbnail)
	{
		$GLOBALS['mutation_server']->pdo->exec('UPDATE fixture_descriptions SET thumbnail=1');
		file_put_contents($GLOBALS['upload_dir'].'/thumbs/t_fixture.txt','thumbnail');
		$_POST['del_thumbnail']=array('fixture.txt'=>'1'); $_POST['attach_thumbnail_list']=array('1');
	}
	else { $_POST['update_attachment']=array('1'=>'1'); }
	$pm=new PmEditUploadFixture(); $pm->filename=$thumbnail?'':'replacement.txt'; return $pm;
}
function pm_edit_file($message)
{
	return $GLOBALS['mutation_server']->pdo->query('SELECT d.physical_filename FROM fixture_descriptions d,fixture_links a WHERE d.attach_id=a.attach_id AND a.privmsgs_id='.(int)$message)->fetchColumn();
}
mutation_check(mkdir($upload_dir,0700) && mkdir($upload_dir.'/thumbs',0700),'Owned attachment-edit files');
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try
{
	foreach(array('english','german') as $locale)
	{
		include $forum_root.'language/lang_'.$locale.'/lang_main.php'; include $forum_root.'language/lang_'.$locale.'/lang_main_attach.php';
		foreach(array('shared','unshared','shared-filename','thumbnail') as $case)
		{
			$pm=pm_edit_fixture($case==='thumbnail');
			if ($case==='unshared' || $case==='shared-filename') { $mutation_server->pdo->exec('DELETE FROM fixture_links WHERE privmsgs_id=21'); }
			if ($case==='shared-filename')
			{
				$mutation_server->pdo->exec("INSERT INTO fixture_descriptions (attach_id,physical_filename,thumbnail) VALUES (9,'fixture.txt',0)");
				$mutation_server->pdo->exec('INSERT INTO fixture_links VALUES (9,0,21,8,7)');
			}
			$pm->handle_attachments('edit');
			$own=pm_edit_file(20);
			mutation_check($own===($case==='thumbnail'?'fixture.txt':'pm_8_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee.txt'),'Current message uses prepared replacement');
			mutation_check(is_file($upload_dir.'/fixture.txt')===($case!=='unshared'),'Only unreferenced original bytes removed');
			if ($case!=='unshared') { mutation_check(pm_edit_file(21)==='fixture.txt','Other message retains original file'); }
			$new_id=(int)$mutation_server->pdo->query('SELECT attach_id FROM fixture_links WHERE privmsgs_id=20')->fetchColumn();
			mutation_check($new_id!==1 && (int)$pm->attachment_id_list[0]===$new_id,'Actual parser hidden list follows copy-on-write ID');
			if ($case==='thumbnail')
			{
				mutation_check((int)$mutation_server->pdo->query('SELECT thumbnail FROM fixture_descriptions WHERE attach_id=1')->fetchColumn()===1,'Other copy retains thumbnail flag');
				mutation_check((int)$mutation_server->pdo->query('SELECT thumbnail FROM fixture_descriptions WHERE attach_id='.$new_id)->fetchColumn()===0 && is_file($upload_dir.'/thumbs/t_fixture.txt'),'Own thumbnail hidden without removing shared thumbnail bytes');
			}
			$export=$pm->prepared_write_attachments();
			mutation_check(count($export)===1 && $export[0]['id']===$new_id,'Replacement exports once under new stored ID');
		}
		foreach(array('INSERT INTO fixture_descriptions ','UPDATE fixture_links SET attach_id') as $boundary)
		{
			foreach(array(false,true) as $lost_ack)
			{
				$pm=pm_edit_fixture();
				if ($lost_ack)
				{
					$mutation_server->hook=function($sql) use($boundary)
					{
						if(strpos($sql,$boundary)!==0){return;} $s=$GLOBALS['mutation_server']; $s->hook=null; $s->pdo->exec($sql); $s->failure=$boundary;
					};
				}
				else { $mutation_server->failure=$boundary; }
				pm_compose_deny(function()use($pm){$pm->handle_attachments('edit');},$lang['PM_cleanup_failed']);
				mutation_check(pm_edit_file(21)==='fixture.txt' && is_file($upload_dir.'/fixture.txt'),'Failure/lost reply preserves unrelated original');
				mutation_check(pm_edit_file(20)===($lost_ack && strpos($boundary,'UPDATE')===0?'pm_8_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee.txt':'fixture.txt'),'Only completed atomic link switch changes own message');
			}
		}
		foreach(array('read','revoked','metadata','foreign-new-link') as $case)
		{
			$pm=pm_edit_fixture();
			$mutation_server->hook=function($sql)use($case)
			{
				if(strpos($sql,'UPDATE fixture_links SET attach_id')!==0){return;} $s=$GLOBALS['mutation_server']; $s->hook=null;
				if($case==='read'){$s->pdo->exec('UPDATE fixture_messages SET privmsgs_type=0 WHERE privmsgs_id=20');}
				elseif($case==='revoked'){$s->pdo->exec('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=8');}
				elseif($case==='metadata'){$s->pdo->exec("UPDATE fixture_descriptions SET comment='changed' WHERE attach_id=1");}
				else{$s->pdo->exec('INSERT INTO fixture_links SELECT MAX(attach_id),0,99,99,7 FROM fixture_descriptions');}
			};
			pm_compose_deny(function()use($pm){$pm->handle_attachments('edit');},$lang[in_array($case,array('read','revoked'),true)?'Not_Authorised':'PM_journal_changed']);
			mutation_check(pm_edit_file(20)==='fixture.txt' && pm_edit_file(21)==='fixture.txt' && is_file($upload_dir.'/fixture.txt'),'Switch requalifies source and private reservation: '.$case);
		}
		foreach(array('unknown','multiple','nested') as $case)
		{
			$pm=pm_edit_fixture();
			$_POST['update_attachment']=$case==='unknown'?array('99'=>'1'):($case==='multiple'?array('1'=>'1','99'=>'1'):array('1'=>array('1')));
			pm_compose_deny(function()use($pm){$pm->handle_attachments('edit');},$lang[$case==='unknown'?'Not_Authorised':'PM_journal_changed']);
			mutation_check($pm_edit_uploads===0 && pm_edit_file(20)==='fixture.txt','Invalid complete action selection rejected before upload');
		}
		echo $locale." actual PM replacement/thumbnail copy-on-write and failure checks passed.\n";
	}
}
finally
{
	if($mutation_server->owner!==null){$mutation_server->owner->sql_close();}
	foreach(array('fixture.txt','pm_8_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee.txt','thumbs/t_fixture.txt') as $file){if(is_file($upload_dir.'/'.$file)){unlink($upload_dir.'/'.$file);}}
	rmdir($upload_dir.'/thumbs'); rmdir($upload_dir); restore_error_handler();
}
