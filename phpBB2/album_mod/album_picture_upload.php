<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_lifecycle_authority.php';
require_once __DIR__ . '/album_upload_receipt.php';
require_once __DIR__ . '/album_upload_stage.php';

// Final upload publication only. Preprocessing owns no final files; prepared
// receipts survive every rollback/uncertain commit. Never unlink on failure.
class AlbumPictureUpload
{
	private $database;
	private $prefix;
	private $root;

	function __construct($database, $prefix, $root = null)
	{
		$this->database = $database; $this->prefix = $prefix; $this->root = $root === null ? dirname(__DIR__) : $root;
	}

	private static function literal($value) { return $value === '' ? "''" : 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci'; }

	static function insert_sql($table, $picture)
	{
		$fields=array('pic_filename','pic_thumbnail','pic_title','pic_desc','pic_user_id','pic_user_ip','pic_username','pic_time','pic_cat_id','pic_approval');
		if(!is_string($table)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)||!is_array($picture)||count($picture)!==count($fields)){throw new AlbumUploadFailure('Not_Authorised');}
		foreach($fields as$field){if(!array_key_exists($field,$picture)){throw new AlbumUploadFailure('Not_Authorised');}}
		if(!AlbumFileStorage::filename($picture['pic_filename'])||($picture['pic_thumbnail']!==''&&!AlbumFileStorage::filename($picture['pic_thumbnail']))){throw new AlbumUploadFailure('Not_Authorised');}
		self::canonical_text($picture['pic_title'],255,true);self::canonical_text($picture['pic_desc'],65535);
		if(!is_string($picture['pic_user_ip'])||!preg_match('/^[a-f0-9]{8}$/iD',$picture['pic_user_ip'])||!is_string($picture['pic_username'])||preg_match('//u',$picture['pic_username'])!==1||preg_match_all('/./us',$picture['pic_username'])>255){throw new AlbumUploadFailure('Not_Authorised');}
		$numbers=array('pic_user_id'=>array(-1,8388607),'pic_time'=>array(1,4294967295),'pic_cat_id'=>array(1,16777215),'pic_approval'=>array(0,1));
		foreach($numbers as$field=>$bounds){$value=$picture[$field];if((!is_int($value)&&!is_string($value))||!preg_match('/^(?:0|[1-9][0-9]*|-1)$/D',(string)$value)||(float)$value<$bounds[0]||(float)$value>$bounds[1]||($field==='pic_user_id'&&(int)$value===0)){throw new AlbumUploadFailure('Not_Authorised');}}
		$values=array();foreach($fields as$field){$values[]=isset($numbers[$field])?(string)(int)$picture[$field]:self::literal($picture[$field]);}
		return'INSERT INTO '.$table.'('.implode(',',$fields).') VALUES('.implode(',',$values).')';
	}
	private static function config_integer($settings, $name, $minimum, $maximum)
	{
		if (!isset($settings[$name]) || (!is_int($settings[$name]) && !is_string($settings[$name])) || !preg_match('/^-?[0-9]{1,10}$/D',(string)$settings[$name]) || (float)$settings[$name] < $minimum || (float)$settings[$name] > $maximum) { throw new AlbumUploadFailure('Upload_resize_failed'); }
		return (int)$settings[$name];
	}

	private static function canonical_text($value, $maximum, $required = false)
	{
		if (!is_string($value) || strlen($value) > $maximum*24 || preg_match('//u',$value)!==1) { throw new AlbumUploadFailure('Not_Authorised'); }
		$plain = html_entity_decode($value,ENT_QUOTES,'UTF-8'); $length = preg_match_all('/./us',$plain);
		if ($length===false || $length>$maximum || ($required && trim($plain)==='') || htmlspecialchars($plain,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',true)!==$value) { throw new AlbumUploadFailure($required?'Missed_pic_title':'Not_Authorised'); }
		return $length;
	}

	private function rows($storage, $sql)
	{
		$result=$storage->sql_query($sql);try{$rows=$storage->sql_fetchrowset($result);}finally{$storage->sql_freeresult($result);}
		if(!is_array($rows)){throw new AlbumUploadFailure('Not_Authorised');}return$rows;
	}

	private function count($storage,$where)
	{
		$rows=$this->rows($storage,'SELECT COUNT(*) AS count FROM '.$this->prefix.'album '.$where);
		if(count($rows)!==1||!isset($rows[0]['count'])||!preg_match('/^[0-9]{1,10}$/D',(string)$rows[0]['count'])||(float)$rows[0]['count']>4294967295){throw new AlbumUploadFailure('Not_Authorised');}
		return(int)$rows[0]['count'];
	}

	private function quota($storage,$context,$owner,$category)
	{
		$settings=$context['settings'];$actor=$context['userdata'];
		if($owner===0)
		{
			$maximum=self::config_integer($settings,'max_pics',-2147483648,2147483647);
			if($maximum>=0&&$this->count($storage,'WHERE pic_cat_id='.$category)>=$maximum){throw new AlbumUploadFailure('Album_reached_quota');}
			if($actor['session_logged_in']&&$actor['user_level']!==ADMIN)
			{
				$key=!empty($context['access']['moderator'])?'mod_pics_limit':'user_pics_limit';$limit=self::config_integer($settings,$key,-2147483648,2147483647);
				if($limit>=0&&$this->count($storage,'WHERE pic_cat_id='.$category.' AND pic_user_id='.$actor['user_id'])>=$limit){throw new AlbumUploadFailure('User_reached_pics_quota');}
			}
		}
		else
		{
			$limit=self::config_integer($settings,'personal_gallery_limit',-2147483648,2147483647);
			if($limit>=0&&$this->count($storage,'p INNER JOIN '.$this->prefix.'album_cat c ON c.cat_id=p.pic_cat_id WHERE c.cat_user_id='.$owner)>=$limit){throw new AlbumUploadFailure('Album_reached_quota');}
		}
	}

	private function validate_image($context,$stage,$description_length)
	{
		$settings=$context['settings'];$source=$stage->original();$small=$stage->small();
		if(AlbumUploadStage::policy($settings)!==$stage->processing_policy()){throw new AlbumUploadFailure('Upload_resize_failed');}
		$allowed=array('.jpg'=>'jpg_allowed','.png'=>'png_allowed','.gif'=>'gif_allowed');
		if(!isset($allowed[$source['extension']])||self::config_integer($settings,$allowed[$source['extension']],0,1)!==1){throw new AlbumUploadFailure('Not_allowed_file_type');}
		$maximum=self::config_integer($settings,'max_file_size',1,2147483647);
		if($source['metadata']['bytes']>$maximum||($stage->provided()!==null&&$stage->provided()['bytes']>$maximum)){throw new AlbumUploadFailure('Bad_upload_file_size');}
		if($source['width']>self::config_integer($settings,'max_width',1,10000)||$source['height']>self::config_integer($settings,'max_height',1,10000)){throw new AlbumUploadFailure('Upload_image_size_too_big');}
		$bound=$stage->processing_policy()['thumbnail_size'];
		if($small!==null&&($small['width']>$bound||$small['height']>$bound)){throw new AlbumUploadFailure('Upload_thumbnail_size_too_big');}
		if($description_length>self::config_integer($settings,'desc_length',0,65535)){throw new AlbumUploadFailure('Upload_description_too_long');}
	}

	function publish($snapshot,$owner,$category,$title,$description,$guest_name,$stage)
	{
		if(!($stage instanceof AlbumUploadStage)||!$stage->matches_root($this->root)||!is_int($owner)||!is_int($category)){throw new AlbumUploadFailure('Not_Authorised');}
		self::canonical_text($title,255,true);$description_length=self::canonical_text($description,65535);self::canonical_text($guest_name,32);
		$storage=null;
		try
		{
			$storage=new AlbumFileCoordinator($this->database,$this->prefix);$storage->begin();$authority=new AlbumLifecycleAuthority($storage,$this->prefix);
			$context=$authority->lock($snapshot,$owner,$category,ALBUM_AUTH_VIEW_AND_UPLOAD);if($context===false||!$stage->owner_matches($context['userdata'])){throw new AlbumUploadFailure('Not_Authorised');}
			$storage->guard_commit(function()use($stage,$context){return$stage->owner_matches($context['userdata']);});
			$actor=$context['userdata'];$original=$stage->original();$provided=$stage->provided();
			$payload=json_encode(array('version'=>1,'destination'=>hash('sha256',realpath($this->root)),'actor'=>$actor['user_id'],'owner'=>$owner,'category'=>$category,'title'=>$title,'description'=>$description,'guest_name'=>$actor['session_logged_in']?null:$guest_name,'original'=>$original['metadata'],'provided'=>$provided));
			if(!is_string($payload)){throw new AlbumUploadFailure('Not_Authorised');}
			$descriptor=array('operation'=>$stage->operation(),'actor'=>$actor['user_id'],'owner'=>$owner,'category'=>$category,'binding'=>hash('sha256',$payload),'capability'=>$stage->capability());
			$receipt=new AlbumUploadReceipt($storage,$this->prefix,$this->root);$existing=$receipt->committed($descriptor);$artifacts=$stage->artifacts();
			$filename=$artifacts[0]['name'];$thumbnail=count($artifacts)>1?$artifacts[1]['name']:'';
			if($existing!==null&&$existing['state']==='published')
			{
				$photos=$this->rows($storage,'SELECT pic_id,pic_user_id,pic_cat_id,pic_filename,pic_thumbnail,pic_approval FROM '.$this->prefix.'album WHERE pic_id='.$existing['picture'].' FOR UPDATE');
				$present=count($photos)===1&&(int)$photos[0]['pic_user_id']===$actor['user_id']&&(int)$photos[0]['pic_cat_id']===$category&&$photos[0]['pic_filename']===$filename;
				$storage->commit();return array('state'=>$present?'published':'gone','reused'=>true,'picture'=>$present?$existing['picture']:null,'filename'=>$filename,'thumbnail'=>$present?(string)$photos[0]['pic_thumbnail']:'','approval'=>$present?(int)$photos[0]['pic_approval']:0,'category'=>$context['category'],'username'=>$actor['session_logged_in']?$actor['username']:$guest_name);
			}
			$this->validate_image($context,$stage,$description_length);$this->quota($storage,$context,$owner,$category);
			$items=array();foreach($artifacts as$artifact){$items[]=array('scope'=>$artifact['scope'],'name'=>$artifact['name'],'metadata'=>$artifact['metadata']);}
			$receipt->prepare($descriptor,$items);$files=$receipt->retry_files($descriptor);
			foreach($artifacts as$artifact)
			{
				// Retry prepared bytes only when the exact persisted file is already
				// complete. Otherwise publish exclusively; never overwrite/adopt it.
				$current=$files->snapshot($artifact['scope'],$artifact['name']);
				if($current['present']&&$current!==$artifact['metadata']){throw new AlbumUploadFailure('Not_Authorised');}
				if(!$receipt->publication_allowed($descriptor,array('action'=>'publish','scope'=>$artifact['scope'],'name'=>$artifact['name'],'operation'=>$descriptor['operation'],'metadata'=>$artifact['metadata']))){throw new AlbumUploadFailure('Not_Authorised');}
				$stage_result=$files->remove_stage($descriptor['operation'],$artifact['scope'],$artifact['name']);if($stage_result!=='missing'&&$stage_result!=='removed'){throw new AlbumUploadFailure('Not_Authorised');}
				if(!$current['present']){$files->publish($descriptor['operation'],$artifact['scope'],$artifact['name'],$artifact['stream'],$artifact['metadata']);}
			}
			$approval=(int)$context['category']['cat_approval']===0?1:0;$username=$actor['session_logged_in']?$actor['username']:$guest_name;$time=time();
			$expected=array('pic_filename'=>$filename,'pic_thumbnail'=>$thumbnail,'pic_title'=>$title,'pic_desc'=>$description,'pic_user_id'=>(string)$actor['user_id'],'pic_user_ip'=>$actor['session_ip'],'pic_username'=>$username,'pic_time'=>(string)$time,'pic_cat_id'=>(string)$category,'pic_approval'=>(string)$approval,'pic_view_count'=>'0','pic_lock'=>'0');
			$storage->sql_query(self::insert_sql($this->prefix.'album',array_slice($expected,0,10,true)));
			$picture=$storage->sql_nextid();if((!is_int($picture)&&!is_string($picture))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$picture)||(float)$picture>4294967295||(int)$storage->sql_affectedrows()!==1){throw new AlbumUploadFailure('Not_Authorised');}$picture=(int)$picture;
			$saved=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album WHERE pic_id='.$picture.' FOR UPDATE');if(count($saved)!==1){throw new AlbumUploadFailure('Not_Authorised');}
			foreach($expected as$field=>$value){if(!array_key_exists($field,$saved[0])||(string)$saved[0][$field]!==$value){throw new AlbumUploadFailure('Not_Authorised');}}
			$receipt->mark_published($descriptor,$picture);$storage->commit();
			return array('state'=>'published','reused'=>false,'picture'=>$picture,'filename'=>$filename,'thumbnail'=>$thumbnail,'approval'=>$approval,'category'=>$context['category'],'username'=>$username);
		}
		finally{if($storage){$storage->release();}}
	}
}
