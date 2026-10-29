<?php
class AlbumDeleteBulkFailureSource extends sql_db
{
	var $mode='second-header';var $headers=0;var $armed=false;
	function sql_dedicated_connection(){return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(),$this);}
	function observed_query($connection,$sql,$transaction=false){
		if(strpos($sql,'INSERT INTO fixture_album_file_jobs(')===0&&++$this->headers===2&&$this->mode==='second-header'){return false;}
		if(strpos($sql,'DELETE FROM fixture_album WHERE ')===0){$this->armed=true;}
		$result=$connection->sql_query($sql,$transaction);if($sql==='COMMIT'&&$this->armed&&$this->mode==='lost-commit'){$this->armed=false;acd_check($result!==false,'Actual multi-receipt metadata commit occurred');return false;}return$result;
	}
}
function acd_bulk_seed($count)
{
	acd_reset();$values=array();for($id=2;$id<=$count;$id++){$values[]='('.$id.",'bulk-original-".$id.".png','bulk-thumbnail-".$id.".png','Unique bulk fixture',7,1)";}
	acd_sql('INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_time) VALUES'.implode(',',$values));
}
function acd_bulk_faults()
{
	global$base,$userdata,$host,$password,$schema;
	foreach(array('second-header','lost-commit')as$mode){
		acd_bulk_seed(501);$source=new AlbumDeleteBulkFailureSource($host,'root',$password,$schema,false);$source->mode=$mode;$denied=false;
		try{(new AlbumPictureDelete($source,'fixture_',$base))->remove($userdata,0,1,range(1,501));}catch(AlbumFileCoordinatorFailure$error){$denied=true;}finally{$source->sql_close();}
		acd_check($denied,'Multi-receipt fault/unconfirmed commit is reported');acd_files_intact();$jobs=acd_rows('SELECT * FROM fixture_album_file_jobs ORDER BY operation_id');
		if($mode==='second-header'){acd_check(!$jobs&&!acd_rows('SELECT operation_id FROM fixture_album_file_items')&&count(acd_rows('SELECT pic_id FROM fixture_album'))===501,'Second receipt failure rolls back every receipt and original picture');continue;}
		acd_check(count($jobs)===2&&!acd_rows('SELECT pic_id FROM fixture_album'),'Lost acknowledgement retains both committed receipts without starting file deletion');
		$native=new sql_db($host,'root',$password,$schema,false);try{foreach($jobs as$job){$descriptor=array('operation'=>$job['operation_id'],'binding'=>$job['binding_hash'],'capability'=>$job['capability_hash']);$done=false;for($batch=0;$batch<3;$batch++){$c=new AlbumFileCoordinator($native,'fixture_');try{$c->begin();$r=(new AlbumCleanupReceipt($c,'fixture_',$base))->resolve_batch($descriptor,1000);$c->commit();}finally{$c->release();}if($r['complete']){$done=true;break;}}acd_check($done,'Bounded later cleanup finishes every committed multi-picture receipt');}}finally{$native->sql_close();}
		acd_check(!acd_rows("SELECT operation_id FROM fixture_album_file_jobs WHERE operation_state<>'complete'"),'No lost-commit journal disappears or stays pending');
	}
}
function acd_bulk_shared($print=false)
{
	global$base,$userdata,$host,$password,$schema,$paths,$cases;
	acd_reset();$values=array();for($id=2;$id<=10001;$id++){$values[]="(".$id.",'a.png','t.png','Bulk synthetic',7,1)";}acd_sql('INSERT INTO fixture_album(pic_id,pic_filename,pic_thumbnail,pic_title,pic_user_id,pic_time) VALUES'.implode(',',$values));unset($values);$ids=range(1,10001);$ids[]=1;$native=new sql_db($host,'root',$password,$schema,false);
	try{$result=(new AlbumPictureDelete($native,'fixture_',$base))->remove($userdata,0,1,$ids);}finally{$native->sql_close();}
	$jobs=acd_rows('SELECT picture_count,item_count FROM fixture_album_file_jobs ORDER BY picture_count DESC');acd_check($result['deleted']===10001&&$result['pending']===0&&count($jobs)===21&&(int)$jobs[0]['picture_count']===500&&(int)$jobs[20]['picture_count']===1&&!acd_rows('SELECT pic_id FROM fixture_album'),'Bulk deletion spans bounded journal chunks without a total input limit or duplicate count');foreach($paths as$path){acd_check(!is_file($base.'/'.$path),'Deduplicated shared paths removed after all bulk metadata commits');}$cases++;
	if($print){echo "Native shared-path Album bulk deletion: 10001 pictures / 21 complete receipts PASS; duplicate IDs counted once, full controller/MariaDB, bounded manifests without a total cap.\n";}
}
function acd_bulk_unique()
{
	global$base,$userdata,$host,$password,$schema,$paths;
	acd_bulk_faults();acd_bulk_seed(1001);
	// Avoid the fault observer retaining every diagnostic SQL string: measure
	// the real controller/receipt working set rather than a growing test log.
	$native=new sql_db($host,'root',$password,$schema,false);
	try{$result=(new AlbumPictureDelete($native,'fixture_',$base))->remove($userdata,0,1,range(1,1001));}finally{$native->sql_close();}
	acd_check($result['deleted']===1001&&$result['pending']===0,'Every unique selected picture is removed without a total cap');
	$counts=acd_rows('SELECT COUNT(*) jobs,SUM(picture_count) pictures,SUM(item_count) items,MAX(picture_count) largest_picture_manifest,MAX(item_count) largest_file_manifest FROM fixture_album_file_jobs');$c=$counts[0];
	acd_check((int)$c['jobs']===3&&(int)$c['pictures']===1001&&(int)$c['items']===5005&&(int)$c['largest_picture_manifest']<=500&&(int)$c['largest_file_manifest']<=2500,'Three bounded manifests retain all 5005 unique paths');
	acd_check(!acd_rows("SELECT operation_id FROM fixture_album_file_jobs WHERE operation_state<>'complete'")&&!acd_rows("SELECT operation_id FROM fixture_album_file_items WHERE item_state='pending'")&&!acd_rows('SELECT pic_id FROM fixture_album'),'All metadata and exact terminal journals finish');
	foreach($paths as$path){acd_check(!is_file($base.'/'.$path),'Recorded original fixture bytes removed only after commit');}
	$peak=memory_get_peak_usage(true);acd_check($peak<64*1024*1024,'Actual 1001-photo controller completes below 64MiB');
	echo 'Native unique Album bulk deletion: 1001 pictures / 5005 paths / 3 complete receipts PASS; peak '.$peak." bytes, full controller/MariaDB, no total cap; second-receipt rollback and lost-commit bounded recovery.\n";
}
