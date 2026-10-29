<?php
// Privileged CLI maintenance only; public controllers do not grant recovery.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }

function plus_album_recovery_installation()
{
	$project=dirname(__DIR__);$candidate=is_dir($project.'/phpBB2')?$project.'/phpBB2':$project;
	if(is_link($candidate)||($root=realpath($candidate))===false||!is_file($root.'/album_mod/album_upload_recovery.php')||!is_file($root.'/album_mod/album_cleanup_receipt.php')){throw new RuntimeException('Album recovery must belong to the configured installation');}return$root;
}

function plus_album_recovery_options($arguments)
{
	$options = array('apply'=>false, 'backup-confirmed'=>false, 'help'=>false, 'jobs'=>10, 'items'=>100, 'older-than-days'=>7, 'after'=>''); $seen = array();
	foreach ($arguments as $argument) {
		if (!is_string($argument) || !preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $argument, $match) || !array_key_exists($match[1],$options) || isset($seen[$match[1]])) { throw new InvalidArgumentException('Unknown, duplicate or positional recovery argument'); }
		$key=$match[1]; $seen[$key]=true;
		if (in_array($key,array('apply','backup-confirmed','help'),true)) { if (isset($match[2])) { throw new InvalidArgumentException('Recovery flags take no value'); } $options[$key]=true; continue; }
		if (!isset($match[2])) { throw new InvalidArgumentException('Recovery option requires a value'); }
		$value=$match[2];
		if ($key==='after') { if (!preg_match('/^[a-f0-9]{32}$/D',$value)) { throw new InvalidArgumentException('Recovery cursor must be an operation ID'); } $options[$key]=$value; continue; }
		$maximum=array('jobs'=>100,'items'=>1000,'older-than-days'=>3650);
		if (!preg_match('/^[1-9][0-9]{0,3}$/D',$value) || (int)$value>$maximum[$key]) { throw new InvalidArgumentException('Recovery limit outside supported range'); } $options[$key]=(int)$value;
	}
	if ($options['apply'] && !$options['backup-confirmed']) { throw new InvalidArgumentException('Applying recovery requires a verified database and Album file backup (--backup-confirmed)'); }
	return $options;
}

class PlusAlbumFileRecovery
{
	private $source;
	private $prefix;
	private $root;
	function __construct($source,$prefix,$root)
	{
		if (!is_object($source) || !method_exists($source,'sql_dedicated_connection') || !is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix) || !is_string($root) || is_link($root) || realpath($root)!==$root) { throw new InvalidArgumentException('Invalid trusted Album recovery installation'); }
		require_once$root.'/album_mod/album_upload_recovery.php';require_once$root.'/album_mod/album_cleanup_receipt.php';
		$this->source=$source; $this->prefix=$prefix; $this->root=$root;
	}
	private function rows($database,$sql)
	{
		$result=$database->sql_query($sql); if (!$result) { throw new RuntimeException('Album recovery inventory unavailable'); }
		try { $rows=$database->sql_fetchrowset($result); } finally { $database->sql_freeresult($result); }
		if (!is_array($rows)) { throw new RuntimeException('Album recovery rows unavailable'); }
		$out=array(); foreach($rows as$row){$named=array();foreach($row as$key=>$value){if(is_string($key)){$named[$key]=$value;}}$out[]=$named;} return$out;
	}
	private function integer($value,$minimum,$maximum)
	{
		if ((!is_int($value)&&!is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]*|-1)$/D',(string)$value) || (float)$value<$minimum || (float)$value>$maximum) { throw new RuntimeException('Invalid Album recovery journal integer'); } return(int)$value;
	}
	private function descriptor($row)
	{
		foreach(array('operation_id'=>32,'binding_hash'=>64,'capability_hash'=>64)as$field=>$length){if(!isset($row[$field])||!is_string($row[$field])||strlen($row[$field])!==$length||!preg_match('/^[a-f0-9]+$/D',$row[$field])){throw new RuntimeException('Invalid Album recovery journal identity');}}
		$descriptor=array('operation'=>$row['operation_id'],'binding'=>$row['binding_hash'],'capability'=>$row['capability_hash']);
		if($row['operation_kind']==='upload'){$actor=$this->integer($row['actor_id'],-1,8388607);if($actor===0){throw new RuntimeException('Invalid upload recovery actor');}$descriptor['actor']=$actor;$descriptor['owner']=$this->integer($row['owner_id'],0,8388607);$descriptor['category']=$this->integer($row['category_id'],1,16777215);}
		elseif($row['operation_kind']!=='delete'){throw new RuntimeException('Unsupported Album recovery operation');}
		return$descriptor;
	}
	private function owned_job($row,$items,$cutoff)
	{
		$descriptor=$this->descriptor($row);
		if($row['operation_kind']==='upload'){
			// A prepared upload first loses publication permission durably. Never
			// remove bytes within the transaction which revokes that permission.
			$coordinator=new AlbumFileCoordinator($this->source,$this->prefix);
			try{$coordinator->begin();$recovery=new AlbumUploadRecovery($coordinator,$this->prefix,$this->root);$state=$recovery->mark_abandoning($descriptor,$cutoff);$coordinator->commit();}finally{$coordinator->release();}
			if(!in_array($state,array('abandoning','abandoned'),true)){return array('states'=>array(),'attempted'=>0,'complete'=>true,'skipped'=>true);}
		}
		$coordinator=new AlbumFileCoordinator($this->source,$this->prefix);
		try{
			$coordinator->begin();
			if($row['operation_kind']==='upload'){$recovery=new AlbumUploadRecovery($coordinator,$this->prefix,$this->root);$result=$recovery->resolve_batch($descriptor,min(3,$items));}
			else{$recovery=new AlbumCleanupReceipt($coordinator,$this->prefix,$this->root);$result=$recovery->resolve_batch($descriptor,$items);}
			$coordinator->commit(); return$result;
		}finally{$coordinator->release();}
	}
	function run($options)
	{
		// Validate even internal callers; budgets never silently fall back.
		if(!is_array($options)||count($options)!==7||array_diff(array('apply','backup-confirmed','help','jobs','items','older-than-days','after'),array_keys($options))||!is_bool($options['apply'])||!is_bool($options['backup-confirmed'])||!is_bool($options['help'])||!is_int($options['jobs'])||$options['jobs']<1||$options['jobs']>100||!is_int($options['items'])||$options['items']<1||$options['items']>1000||!is_int($options['older-than-days'])||$options['older-than-days']<1||$options['older-than-days']>3650||!is_string($options['after'])||($options['after']!==''&&!preg_match('/^[a-f0-9]{32}$/D',$options['after']))||($options['apply']&&!$options['backup-confirmed'])){throw new InvalidArgumentException('Invalid Album recovery budget or confirmation');}
		$cutoff=time()-$options['older-than-days']*86400;if($cutoff<1){throw new RuntimeException('Recovery age precedes supported journal time');}
		$after=$options['after'];$where="operation_id>'".$after."' AND ((operation_kind='delete' AND operation_state='cleanup') OR (operation_kind='upload' AND (operation_state='abandoning' OR (operation_state='prepared' AND created_at<=".$cutoff.'))))';
		$jobs=$this->rows($this->source,'SELECT * FROM '.$this->prefix.'album_file_jobs WHERE '.$where.' ORDER BY operation_id LIMIT '.($options['jobs']+1));$more=count($jobs)>$options['jobs'];$jobs=array_slice($jobs,0,$options['jobs']);
		$report=array('mode'=>$options['apply']?'apply':'preview','selected'=>count($jobs),'attempted_items'=>0,'next_after'=>$after,'incomplete'=>false,'more_available'=>$more,'jobs'=>array());
		foreach($jobs as$row){
			$this->descriptor($row);$count=$this->integer($row['item_count'],1,65535);$created=$this->integer($row['created_at'],1,4294967295);
			$item=array('operation'=>$row['operation_id'],'kind'=>$row['operation_kind'],'state'=>$row['operation_state'],'items'=>$count,'created_utc'=>gmdate('c',$created));
			if(!$options['apply']){$item['manifest_validation_required']=true;$report['jobs'][]=$item;$report['next_after']=$row['operation_id'];continue;}
			$remaining=$options['items']-$report['attempted_items'];if($remaining<1){break;}
			$result=$this->owned_job($row,$remaining,$cutoff);$report['attempted_items']+=$result['attempted'];$item['attempted']=$result['attempted'];$item['complete']=$result['complete'];$item['status']=!empty($result['skipped'])?'no-longer-eligible':($result['complete']?'complete':'pending');$item['outcomes']=array_count_values($result['states']);$report['jobs'][]=$item;
			if(!$result['complete']){$report['incomplete']=true;break;} $report['next_after']=$row['operation_id'];
		}
		// An unfinished operation keeps the preceding cursor, so a subsequent
		// bounded run resumes it rather than silently skipping pending files.
		$report['more_available']=$report['more_available']||$report['incomplete']||count($report['jobs'])<count($jobs);return$report;
	}
}
