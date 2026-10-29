<?php
require_once $root.'/phpBB2/album_mod/album_upload_recovery.php';

function aur_recovery_begin()
{
	aur_check($GLOBALS['coordinator']===null,'Previous recovery test lease must be explicitly released before another begins');
	// The server may process the previous socket close after the client returns.
	// Use the same bounded namespace wait as production; never adopt another lease.
	$GLOBALS['coordinator']=new AlbumFileCoordinator($GLOBALS['source'],'fixture_',3);$GLOBALS['coordinator']->begin();
	return new AlbumUploadRecovery($GLOBALS['coordinator'],'fixture_',$GLOBALS['base']);
}
function aur_recovery_release(){if($GLOBALS['coordinator']){$GLOBALS['coordinator']->release();$GLOBALS['coordinator']=null;}}
function aur_recovery_prepare($final=true,$stages=true,$old=true)
{
	aur_recovery_release();aur_clear();$GLOBALS['source']->reset_case();$recovery=aur_recovery_begin();$receipt=new AlbumUploadReceipt($GLOBALS['coordinator'],'fixture_',$GLOBALS['base']);$receipt->prepare($GLOBALS['descriptor'],$GLOBALS['items']);$files=$receipt->files($GLOBALS['descriptor']);
	foreach($GLOBALS['items']as$item){
		if($final){$files->publish($GLOBALS['descriptor']['operation'],$item['scope'],$item['name'],$GLOBALS['input'],$item['metadata']);}
		if($stages){$stage=$files->stage_name($GLOBALS['descriptor']['operation'],$item['scope'],$item['name']);aur_check(file_put_contents($GLOBALS['base'].$GLOBALS['scopes'][$item['scope']].$stage,'Owned incomplete stage')===22,'Own deterministic abandoned stage');}
	}
	$GLOBALS['coordinator']->commit();aur_recovery_release();if($old){aur_sql('UPDATE fixture_album_file_jobs SET created_at='.(time()-90000));}
}
function aur_recovery_files()
{
	$result=array();foreach($GLOBALS['items']as$item){$path=$GLOBALS['base'].$GLOBALS['scopes'][$item['scope']].$item['name'];$stage=dirname($path).'/.album-stage-'.$GLOBALS['descriptor']['operation'].'-'.substr(hash('sha256',$item['scope']."\0".$item['name']),0,16).'.tmp';foreach(array($path,$stage)as$name){$result[$name]=is_file($name)?file_get_contents($name):null;}}return$result;
}
function aur_recovery_mark($lost=false)
{
	try{$recovery=aur_recovery_begin();$state=$recovery->mark_abandoning($GLOBALS['descriptor'],time()-86400);if($lost){$GLOBALS['source']->lost='1';}$GLOBALS['coordinator']->commit();return$state;}finally{aur_recovery_release();}
}
function aur_recovery_batch($limit=1,$lost=false)
{
	try{$recovery=aur_recovery_begin();$result=$recovery->resolve_batch($GLOBALS['descriptor'],$limit);if($lost){$GLOBALS['source']->lost='1';}$GLOBALS['coordinator']->commit();return$result;}finally{aur_recovery_release();}
}
function aur_recovery_finish()
{
	for($i=0;$i<4;$i++){$GLOBALS['source']->reset_case();$result=aur_recovery_batch();if($result['complete']){return$result;}}throw new RuntimeException('Three-item abandoned upload failed to finish bounded recovery');
}
function aur_recovery_barrier($boundary)
{
	aur_check(file_put_contents($GLOBALS['base'].'/crash-ready',$boundary)===strlen($boundary),'Own recovery worker reached exact boundary');$deadline=microtime(true)+20;while(microtime(true)<$deadline){usleep(10000);}throw new RuntimeException('Recovery worker must be killed at confirmed boundary');
}
class AlbumUploadRecoverySource extends AlbumUploadReceiptSource
{
	var $boundary='';var $revoking=false;var $completing=false;
	function observed_query($database,$sql,$transaction=false)
	{
		if(strpos($sql,"UPDATE fixture_album_file_jobs SET operation_state='abandoning'")===0){$this->revoking=true;}
		if(strpos($sql,"UPDATE fixture_album_file_jobs SET operation_state='abandoned'")===0){$this->completing=true;}
		if($sql==='COMMIT'&&$this->revoking&&$this->boundary==='before-revocation'){aur_recovery_barrier($this->boundary);}
		if(strpos($sql,'UPDATE fixture_album_file_items SET item_state=')===0&&$this->boundary==='file-removed'){aur_recovery_barrier($this->boundary);}
		$result=parent::observed_query($database,$sql,$transaction);
		if($result!==false&&$sql==='COMMIT'&&(($this->revoking&&$this->boundary==='revoked')||($this->completing&&$this->boundary==='complete'))){aur_recovery_barrier($this->boundary);}
		return$result;
	}
}
function aur_recovery_worker($boundary)
{
	$recovery=aur_recovery_begin();if(in_array($boundary,array('before-revocation','revoked'),true)){$recovery->mark_abandoning($GLOBALS['descriptor'],time()-86400);}else{$recovery->resolve_batch($GLOBALS['descriptor'],3);}$GLOBALS['coordinator']->commit();throw new RuntimeException('Expected exact recovery crash barrier');
}
function aur_recovery_cases()
{
	global $source,$coordinator,$descriptor,$items,$base,$cases;
	foreach(array(false,true)as$final){foreach(array(false,true)as$stages){
		aur_recovery_prepare($final,$stages);$before=aur_recovery_files();aur_check(aur_recovery_mark()==='abandoning'&&aur_recovery_files()===$before,'Durable revocation changes no final or partial bytes');$cases++;
		for($i=0;$i<3;$i++){$result=aur_recovery_batch();aur_check($result['attempted']===1&&count(array_keys($result['states'],'pending',true))===2-$i&&$result['complete']===($i===2),'Explicit item budget includes exact final/stage pair and preserves remaining manifest');$cases++;}
		aur_check(count(array_filter(aur_recovery_files(),function($v){return$v!==null;}))===0,'All exact abandoned finals and stages removed');$again=aur_recovery_batch();aur_check($again['complete']&&$again['attempted']===0,'Completed upload abandonment no-op');$cases++;
	}}
	aur_recovery_prepare();$before=aur_recovery_files();$recovery=aur_recovery_begin();$recovery->mark_abandoning($descriptor,time()-86400);aur_denied(function()use($recovery,$descriptor){$recovery->resolve_batch($descriptor,1);},'Uncommitted revocation cannot authorize physical deletion');aur_check(aur_recovery_files()===$before,'Every byte intact before revocation commit');aur_recovery_release();$cases++;
	aur_recovery_prepare(true,true,false);$before=aur_recovery_files();aur_check(aur_recovery_mark()==='prepared'&&aur_recovery_files()===$before,'Recent live retry cannot be revoked by old-upload maintenance');$cases++;
	foreach(array(0,-1,'1',1.0,true,time(),time()-1000)as$cutoff){aur_recovery_prepare();$recovery=aur_recovery_begin();$before=count($source->queries);aur_denied(function()use($recovery,$descriptor,$cutoff){$recovery->mark_abandoning($descriptor,$cutoff);},'Typed minimum one-day revocation age enforced before reads');aur_check(count($source->queries)===$before,'Bad age makes no coordinated SQL');aur_recovery_release();}
	foreach(array(0,-1,4,'1',1.0,true,array(),null)as$limit){aur_recovery_prepare();aur_recovery_mark();$recovery=aur_recovery_begin();$before=count($source->queries);aur_denied(function()use($recovery,$descriptor,$limit){$recovery->resolve_batch($descriptor,$limit);},'Invalid recovery item budget refuses before SQL/files');aur_check(count($source->queries)===$before,'No SQL for invalid item budget');aur_recovery_release();}
	aur_recovery_prepare();aur_recovery_mark();$recovery=aur_recovery_begin();$receipt=new AlbumUploadReceipt($coordinator,'fixture_',$base);
	aur_denied(function()use($receipt,$descriptor){$receipt->committed($descriptor);},'Ordinary publication cannot adopt revoked operation');aur_denied(function()use($receipt,$descriptor){$receipt->retry_files($descriptor);},'Ordinary retry cannot clear revoked stage');aur_recovery_release();aur_recovery_finish();
	aur_recovery_prepare();$changed="Keep changed bytes Größe 😀";$path=$base.$GLOBALS['scopes']['original'].$items[0]['name'];file_put_contents($path,$changed);aur_recovery_mark();$result=aur_recovery_finish();aur_check($result['states'][0]==='changed'&&file_get_contents($path)===$changed,'Changed original survives old prepared manifest');$cases++;
	aur_recovery_prepare();$coordinator=new AlbumFileCoordinator($source,'fixture_',0);$coordinator->begin();aur_photo($coordinator,$descriptor);$coordinator->commit();aur_recovery_release();$before=aur_recovery_files();aur_recovery_mark();$result=aur_recovery_finish();aur_check($result['states']===array_fill(0,3,'shared')&&count(aur_rows('SELECT pic_id FROM fixture_album'))===1,'Current picture references protect every final scope');foreach($items as$item){$path=$base.$GLOBALS['scopes'][$item['scope']].$item['name'];aur_check(file_get_contents($path)===$GLOBALS['payload'],'Referenced original/thumbnail bytes remain exact');}$cases++;
	aur_recovery_prepare();$stage='.album-stage-'.$descriptor['operation'].'-'.substr(hash('sha256',$items[0]['scope']."\0".$items[0]['name']),0,16).'.tmp';aur_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_time,pic_user_id,pic_cat_id) VALUES(8,".aur_literal($stage).",'Preserve legacy stage reference',1,7,1)");$stage_path=$base.$GLOBALS['scopes']['original'].$stage;$stage_bytes=file_get_contents($stage_path);aur_recovery_mark();$result=aur_recovery_finish();aur_check($result['states'][0]==='shared'&&file_get_contents($stage_path)===$stage_bytes,'Even malformed historical picture references protect their exact stage file');$cases++;
	aur_recovery_prepare();aur_recovery_mark();aur_sql("CREATE TRIGGER own_recovery_completion BEFORE UPDATE ON fixture_album_file_jobs FOR EACH ROW SET NEW.completed_at=IF(NEW.operation_state='abandoned',1,NEW.completed_at)");$source->reset_case();aur_denied(function(){aur_recovery_batch(3);},'Unexpected completed timestamp fails exact readback');aur_sql('DROP TRIGGER own_recovery_completion');aur_recovery_finish();$cases++;
	aur_clear();$source->reset_case();try{aur_attempt($descriptor);}finally{aur_recovery_release();}$before=aur_recovery_files();aur_check(aur_recovery_mark()==='published'&&aur_recovery_files()===$before,'Published upload stays published and untouched');$recovery=aur_recovery_begin();aur_denied(function()use($recovery,$descriptor){$recovery->resolve_batch($descriptor,1);},'Published receipt never grants abandoned-file cleanup');aur_recovery_release();
	foreach(array('mark','batch')as$phase){aur_recovery_prepare();if($phase==='batch'){aur_recovery_mark();}$source->reset_case();aur_denied(function()use($phase){if($phase==='mark'){aur_recovery_mark(true);}else{aur_recovery_batch(1,true);}},'Actual committed '.$phase.' with lost acknowledgement reports uncertainty');$state=aur_rows('SELECT operation_state FROM fixture_album_file_jobs')[0]['operation_state'];aur_check($state==='abandoning','Lost acknowledgement retains durable revocation');aur_recovery_finish();$cases++;}
	foreach(array('before-revocation','revoked','file-removed','complete')as$boundary){aur_recovery_prepare();if(in_array($boundary,array('file-removed','complete'),true)){aur_recovery_mark();}aur_crash_worker($boundary,true);$state=aur_rows('SELECT operation_state FROM fixture_album_file_jobs')[0]['operation_state'];aur_check($state===($boundary==='before-revocation'?'prepared':($boundary==='complete'?'abandoned':'abandoning')),'Killed worker leaves exact durable recovery phase');if($state==='prepared'){aur_recovery_mark();}aur_recovery_finish();aur_check(count(array_filter(aur_recovery_files(),function($v){return$v!==null;}))===0,'Native worker kill resumes without losing receipt or restoring deleted bytes');$cases++;}
	if(getenv('PHPBB_ALBUM_RECOVERY_QUICK')!=='1'){
		foreach(array('mark','batch')as$phase){aur_recovery_prepare();if($phase==='batch'){aur_recovery_mark();}$source->reset_case();if($phase==='mark'){aur_recovery_mark();}else{aur_recovery_batch(1);}$baseline=$source->queries;
			foreach($baseline as$index=>$sql){aur_recovery_prepare();if($phase==='batch'){aur_recovery_mark();}$before=aur_recovery_files();$source->reset_case($index+1);try{if($phase==='mark'){aur_recovery_mark();}else{aur_recovery_batch(1);}}catch(AlbumFileCoordinatorFailure$error){}catch(AlbumFileStorageFailure$error){}
				if($phase==='mark'){aur_check(aur_recovery_files()===$before,'Every revocation SQL failure preserves all bytes');}
				foreach(aur_recovery_files()as$path=>$bytes){aur_check($bytes===null||$bytes===$before[$path],'Recovery failure preserves exact original data or its intended removal');}$source->reset_case();aur_recovery_mark();aur_recovery_finish();$cases++;
			}
		}
	}
	echo 'Native Album abandoned upload recovery: '.$cases.' cases PASS; '.(getenv('PHPBB_ALBUM_RECOVERY_QUICK')==='1'?'functional phase/budget/retry checks':'actual SQL boundaries plus phase/budget/retry checks').", shared/changed preservation, lost commits and four forced process kills; own files/schema only.\n";
}
