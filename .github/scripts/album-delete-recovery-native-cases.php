<?php
// Use the real dedicated connections and owned files from the deletion fixture.
function acr_prepare($other=false)
{
	acd_reset();
	if($other){acd_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_cat_id,pic_time) VALUES(2,'a.png','t.png','Other current picture',8,2,1)");}
	$storage=new AlbumFileCoordinator($GLOBALS['db'],'fixture_');
	try{
		$storage->begin();$receipt=new AlbumCleanupReceipt($storage,'fixture_',$GLOBALS['base']);$jobs=$receipt->prepare(7,0,1,array(1));
		$storage->sql_query('DELETE FROM fixture_album_comment WHERE comment_pic_id=1');$storage->sql_query('DELETE FROM fixture_album_rate WHERE rate_pic_id=1');$storage->sql_query('DELETE FROM fixture_album WHERE pic_id=1');$storage->commit();
	}finally{$storage->release();}
	acd_files_intact();return$jobs[0];
}
function acr_batch($job,$limit,$lost=false)
{
	$storage=null;
	try{$storage=new AlbumFileCoordinator($GLOBALS['db'],'fixture_');$storage->begin();$receipt=new AlbumCleanupReceipt($storage,'fixture_',$GLOBALS['base']);
		if($lost){$GLOBALS['db']->armed=true;$GLOBALS['db']->lost=true;}
		$result=$receipt->resolve_batch($job,$limit);$storage->commit();return$result;
	}finally{if($storage){$storage->release();}}
}
function acr_present(){ $count=0;foreach($GLOBALS['paths']as$path){if(is_file($GLOBALS['base'].'/'.$path)){$count++;}}return$count; }
function acr_finish($job)
{
	for($i=0;$i<6;$i++){$GLOBALS['db']->reset_case();$result=acr_batch($job,1);if($result['complete']){return$result;}}
	throw new RuntimeException('Bounded exact cleanup did not finish its five-item manifest');
}
function acr_cases()
{
	global $db,$cases,$base,$paths;
	$job=acr_prepare();$remaining=5;
	for($i=0;$i<5;$i++){
		$result=acr_batch($job,1);$remaining--;acd_check($result['attempted']===1&&count(array_keys($result['states'],'pending',true))===$remaining&&$result['complete']===($remaining===0)&&acr_present()===$remaining,'One-item batches advance exactly one persisted file without losing the rest');$cases++;
	}
	$before=acd_rows('SELECT * FROM fixture_album_file_items ORDER BY item_index');$again=acr_batch($job,1);acd_check($again['complete']&&$again['attempted']===0&&$before===acd_rows('SELECT * FROM fixture_album_file_items ORDER BY item_index'),'Completed bounded cleanup is a true no-op');$cases++;
	foreach(array(0,-1,65536,'1',1.0,true,array(),null)as$limit){
		$job=acr_prepare();$storage=new AlbumFileCoordinator($db,'fixture_');try{$storage->begin();$receipt=new AlbumCleanupReceipt($storage,'fixture_',$base);$before=count($db->queries);$denied=false;try{$receipt->resolve_batch($job,$limit);}catch(AlbumFileCoordinatorFailure$error){$denied=true;}acd_check($denied&&count($db->queries)===$before&&acr_present()===5,'Invalid typed budget refuses before SQL or file changes');$cases++;}finally{$storage->release();}
	}
	$job=acr_prepare(true);$other=acd_rows('SELECT * FROM fixture_album WHERE pic_id=2');$result=acr_finish($job);acd_check($result['states']===array_fill(0,5,'shared')&&acr_present()===5&&acd_rows('SELECT * FROM fixture_album WHERE pic_id=2')===$other,'Current references in another category survive every recovery batch');$cases++;
	$job=acr_prepare();$changed='Preserve exact different bytes';foreach($paths as$path){acd_check(file_put_contents($base.'/'.$path,$changed)===strlen($changed),'Own changed recovery file');}$result=acr_finish($job);acd_check($result['states']===array_fill(0,5,'changed')&&acr_present()===5,'Changed files remain intact instead of being removed by an old manifest');foreach($paths as$path){acd_check(file_get_contents($base.'/'.$path)===$changed,'Exact changed content retained');}$cases++;
	$job=acr_prepare();$denied=false;try{acr_batch($job,1,true);}catch(AlbumFileCoordinatorFailure$error){$denied=true;}acd_check($denied&&acr_present()===4&&count(acd_rows("SELECT item_index FROM fixture_album_file_items WHERE item_state='removed'"))===1,'Actual committed batch with lost acknowledgement is retained once');$result=acr_finish($job);acd_check($result['complete']&&acr_present()===0,'Resume after uncertain acknowledgement finishes remaining files without undoing prior work');$cases++;
	$job=acr_prepare();$db->reset_case();$result=acr_batch($job,2);$baseline=$db->queries;acd_check($result['attempted']===2&&!$result['complete']&&acr_present()===3,'Two-item native baseline respects its explicit budget');$cases++;
	if(getenv('PHPBB_ALBUM_DELETE_QUICK')!=='1'){
		foreach($baseline as$index=>$sql){$job=acr_prepare();$db->reset_case($index+1);try{acr_batch($job,2);}catch(AlbumFileCoordinatorFailure$error){}catch(AlbumFileStorageFailure$error){}
			acd_check(!acd_rows('SELECT pic_id FROM fixture_album')&&count(acd_rows('SELECT operation_id FROM fixture_album_file_jobs'))===1,'Recovery failure never resurrects pictures or loses its committed receipt');foreach($paths as$path){if(is_file($base.'/'.$path)){acd_check(file_get_contents($base.'/'.$path)===$GLOBALS['payload'],'Any retained file remains byte-exact');}}
			$result=acr_finish($job);acd_check($result['complete']&&acr_present()===0,'Every actual SQL failure resumes from durable per-item state, including already missing files');$cases++;
		}
	}
	echo 'Native Album bounded deletion recovery: '.$cases.' cases PASS; '.count($baseline)." actual statement boundaries, exact item budgets, resume, shared/changed preservation and lost acknowledgement; own files/schema only.\n";
}
