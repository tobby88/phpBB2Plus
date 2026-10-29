<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_lifecycle_authority.php';
require_once __DIR__ . '/album_photo_text.php';

class AlbumDiscussionFailure extends AlbumFileCoordinatorFailure
{
	var $language_key;
	function __construct($key) { $this->language_key=$key;parent::__construct('Album discussion action refused'); }
}

// Comments and votes share the same parent-picture namespace as deletion.
// Callers pass canonical HTML text prepared once from legacy-quoted HTTP input.
class AlbumPictureDiscussion
{
	private $database;
	private $prefix;
	function __construct($database,$prefix){$this->database=$database;$this->prefix=$prefix;}
	static function input_text($value,$limit){return album_photo_text($value,$limit);}
	static function input_guest_name($value){return album_photo_text($value,32);}
	private static function integer($value,$minimum,$maximum)
	{
		if((!is_int($value)&&!is_string($value))||!preg_match('/^(?:0|[1-9][0-9]*|-1)$/D',(string)$value)||(float)$value<$minimum||(float)$value>$maximum){throw new AlbumDiscussionFailure('Album_discussion_failed');}return (int)$value;
	}
	private static function literal($value){return $value===''?"''":'CONVERT(0x'.bin2hex($value).' USING utf8mb4) COLLATE utf8mb4_unicode_ci';}
	private static function named($row){$result=array();foreach($row as$key=>$value){if(!is_int($key)){$result[$key]=$value;}}return $result;}
	private function rows($storage,$sql)
	{
		$result=$storage->sql_query($sql);try{$rows=$storage->sql_fetchrowset($result);}finally{$storage->sql_freeresult($result);}
		if(!is_array($rows)){throw new AlbumFileCoordinatorFailure('Album discussion rows unavailable');}return $rows;
	}
	private static function canonical($value,$limit,$empty=false)
	{
		if(!is_string($value)||strlen($value)>$limit*6||preg_match('//u',$value)!==1){throw new AlbumDiscussionFailure('Album_discussion_failed');}
		$plain=html_entity_decode($value,ENT_QUOTES,'UTF-8');$count=preg_match_all('/./us',$plain);
		if($count===false||$count>$limit||htmlspecialchars($plain,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',true)!==$value){throw new AlbumDiscussionFailure('Comment_too_long');}
		if(!$empty&&trim($plain)===''){throw new AlbumDiscussionFailure('Comment_no_text');}return $value;
	}
	private function context(&$storage,$snapshot,$owner,$category,$picture,$checks)
	{
		if(!is_int($picture)||$picture<1||$picture>4294967295){throw new AlbumDiscussionFailure('Pic_not_exist');}
		$storage=new AlbumFileCoordinator($this->database,$this->prefix);$storage->begin();$authority=new AlbumLifecycleAuthority($storage,$this->prefix);
		$context=$authority->lock($snapshot,$owner,$category,$checks);
		if($context===false){throw new AlbumDiscussionFailure('Not_Authorised');}
		$photos=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album WHERE pic_id='.$picture.' AND pic_cat_id='.$category.' FOR UPDATE');
		if(count($photos)!==1){throw new AlbumDiscussionFailure('Pic_not_exist');}$photo=self::named($photos[0]);
		$approval=self::integer($photo['pic_approval'],0,1);$policy=self::integer($context['category']['cat_approval'],0,2);
		if($context['userdata']['user_level']!==ADMIN&&$approval!==1&&($policy===ALBUM_ADMIN||($policy===ALBUM_MOD&&empty($context['access']['moderator'])))){throw new AlbumDiscussionFailure('Not_Authorised');}
		$context['picture']=$photo;return $context;
	}
	private function comment_capacity($storage)
	{
		$columns=$this->rows($storage,"SELECT COLUMN_NAME,CHARACTER_MAXIMUM_LENGTH,CHARACTER_OCTET_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX(".self::literal($this->prefix.'album_comment').") AND COLUMN_NAME IN('comment_username','comment_text')");$limits=array();
		foreach($columns as$column){$limits[$column['COLUMN_NAME']]=$column;}
		if(count($limits)!==2||(float)$limits['comment_username']['CHARACTER_MAXIMUM_LENGTH']<255||(float)$limits['comment_text']['CHARACTER_OCTET_LENGTH']<393210){throw new AlbumDiscussionFailure('Album_discussion_failed');}
	}
	private function text_limit($context)
	{
		if(!isset($context['settings']['desc_length'])){throw new AlbumDiscussionFailure('Album_discussion_failed');}
		return max(1,self::integer($context['settings']['desc_length'],0,65535));
	}
	private function assert_values($row,$expected)
	{
		foreach($expected as$field=>$value){if(!array_key_exists($field,$row)||(is_int($value)?(string)$row[$field]!== (string)$value:$row[$field]!==$value)){throw new AlbumFileCoordinatorFailure('Album discussion readback differs');}}
	}
	private static function fingerprints($rows)
	{
		$values=array();foreach($rows as$row){$values[]=serialize(self::named($row));}sort($values,SORT_STRING);return $values;
	}
	private function vote($storage,$context,$point,$field='rate_point',$repeat=false)
	{
		if(!is_int($point)||$point<1||$point>255||!in_array($field,array('rate_point','rate_hon_point'),true)||!is_bool($repeat)||!isset($context['settings']['rate_scale'])||$point>self::integer($context['settings']['rate_scale'],1,255)){throw new AlbumDiscussionFailure('Not_Authorised');}
		$actor=$context['userdata'];$picture=(int)$context['picture']['pic_id'];$identity='rate_user_id='.$actor['user_id'];
		if(!$actor['session_logged_in']){$identity.=' AND rate_user_ip='.self::literal($actor['session_ip']);}
		$where='rate_pic_id='.$picture.' AND '.$identity;
		$order=' ORDER BY rate_point DESC,rate_hon_point DESC,HEX(rate_user_ip) FOR UPDATE';
		$before=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album_rate WHERE '.$where.$order);
		foreach($before as$row){self::integer($row['rate_point'],0,255);self::integer($row['rate_hon_point'],0,255);if(!$repeat&&(int)$row[$field]>0){throw new AlbumDiscussionFailure('Already_rated');}}
		if($before&&!$repeat)
		{
			$chosen=$before[0];$ip=$chosen['rate_user_ip'];if(!is_string($ip)||!preg_match('/^[a-f0-9]{8}$/iD',$ip)){throw new AlbumDiscussionFailure('Album_discussion_failed');}
			$storage->sql_query('UPDATE '.$this->prefix.'album_rate SET '.$field.'='.$point.' WHERE '.$where.' AND rate_point='.(int)$chosen['rate_point'].' AND rate_hon_point='.(int)$chosen['rate_hon_point']." AND HEX(rate_user_ip)='".strtoupper(bin2hex($ip))."' LIMIT 1");
			$expected=$before;$expected[0][$field]=is_int($chosen[$field])?$point:(string)$point;
			$after=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album_rate WHERE '.$where.$order);
			if(self::fingerprints($after)!==self::fingerprints($expected)){throw new AlbumFileCoordinatorFailure('Album vote update differs');}
		}
		else
		{
			$normal=$field==='rate_point'?$point:0;$hon=$field==='rate_hon_point'?$point:0;
			$storage->sql_query('INSERT INTO '.$this->prefix.'album_rate(rate_pic_id,rate_user_id,rate_user_ip,rate_point,rate_hon_point) VALUES('.$picture.','.$actor['user_id'].','.self::literal($actor['session_ip']).','.$normal.','.$hon.')');
			$after=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album_rate WHERE '.$where.$order);
			$remaining=self::fingerprints($before);$inserted=array();
			foreach($after as$row){$key=array_search(serialize(self::named($row)),$remaining,true);if($key===false){$inserted[]=$row;}else{unset($remaining[$key]);}}
			if($remaining||count($inserted)!==1){throw new AlbumFileCoordinatorFailure('Album vote insert differs');}
			$this->assert_values($inserted[0],array('rate_pic_id'=>$picture,'rate_user_id'=>$actor['user_id'],'rate_user_ip'=>$actor['session_ip'],'rate_point'=>$normal,'rate_hon_point'=>$hon));
		}
	}
	function publish($snapshot,$owner,$category,$picture,$text,$guest_name='',$point=null)
	{
		if(!is_string($text)||!is_string($guest_name)||($point!==null&&!is_int($point))||($text===''&&$point===null)){throw new AlbumDiscussionFailure('Comment_no_text');}
		$storage=null;$comment=null;
		try
		{
			$checks=ALBUM_AUTH_VIEW;if($text!==''){$checks|=ALBUM_AUTH_COMMENT;}if($point!==null){$checks|=ALBUM_AUTH_RATE;}
			$context=$this->context($storage,$snapshot,$owner,$category,$picture,$checks);$actor=$context['userdata'];
			if(self::integer($context['picture']['pic_lock'],0,1)===1&&empty($context['access']['moderator'])){throw new AlbumDiscussionFailure('Pic_Locked');}
			if($point!==null){$this->vote($storage,$context,$point);}
			if($text!=='')
			{
				$text=self::canonical($text,$this->text_limit($context));$this->comment_capacity($storage);
				$username=$actor['session_logged_in']?$actor['username']:self::canonical($guest_name,32,true);$now=time();
				$storage->sql_query('INSERT INTO '.$this->prefix.'album_comment(comment_pic_id,comment_cat_id,comment_user_id,comment_username,comment_user_ip,comment_time,comment_text,comment_edit_time,comment_edit_count,comment_edit_user_id) VALUES('.$picture.','.$category.','.$actor['user_id'].','.self::literal($username).','.self::literal($actor['session_ip']).','.$now.','.self::literal($text).',NULL,0,NULL)');
				$comment=self::integer($storage->sql_nextid(),1,4294967295);$saved=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album_comment WHERE comment_id='.$comment.' FOR UPDATE');
				if(count($saved)!==1){throw new AlbumFileCoordinatorFailure('Album inserted comment missing');}
				$this->assert_values($saved[0],array('comment_pic_id'=>$picture,'comment_cat_id'=>$category,'comment_user_id'=>$actor['user_id'],'comment_username'=>$username,'comment_user_ip'=>$actor['session_ip'],'comment_time'=>$now,'comment_text'=>$text,'comment_edit_time'=>null,'comment_edit_count'=>0,'comment_edit_user_id'=>null));
			}
			$storage->commit();return array('comment'=>$comment,'rated'=>$point!==null);
		}
		finally{if($storage){$storage->release();}}
	}
	function change($snapshot,$owner,$category,$picture,$comment,$text=null)
	{
		if(!is_int($comment)||$comment<1||$comment>4294967295||($text!==null&&!is_string($text))){throw new AlbumDiscussionFailure('Album_discussion_failed');}$storage=null;
		try
		{
			$checks=ALBUM_AUTH_VIEW|ALBUM_AUTH_COMMENT|($text===null?ALBUM_AUTH_DELETE:ALBUM_AUTH_EDIT);$context=$this->context($storage,$snapshot,$owner,$category,$picture,$checks);$actor=$context['userdata'];
			if(!$actor['session_logged_in']){throw new AlbumDiscussionFailure('Not_Authorised');}
			$rows=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album_comment WHERE comment_id='.$comment.' AND comment_pic_id='.$picture.' FOR UPDATE');
			if(count($rows)!==1){throw new AlbumDiscussionFailure('Album_discussion_failed');}$before=self::named($rows[0]);
			if($actor['user_level']!==ADMIN&&empty($context['access']['moderator'])&&(string)$before['comment_user_id']!==(string)$actor['user_id']){throw new AlbumDiscussionFailure('Not_Authorised');}
			if($text===null)
			{
				$storage->sql_query('DELETE FROM '.$this->prefix.'album_comment WHERE comment_id='.$comment.' AND comment_pic_id='.$picture);
				if($this->rows($storage,'SELECT comment_id FROM '.$this->prefix.'album_comment WHERE comment_id='.$comment.' FOR UPDATE')){throw new AlbumFileCoordinatorFailure('Album comment deletion differs');}
			}
			else
			{
				$text=self::canonical($text,$this->text_limit($context));$this->comment_capacity($storage);
				$count=min(65535,self::integer($before['comment_edit_count'],0,65535)+1);$now=time();
				$storage->sql_query('UPDATE '.$this->prefix.'album_comment SET comment_text='.self::literal($text).',comment_edit_time='.$now.',comment_edit_count='.$count.',comment_edit_user_id='.$actor['user_id'].' WHERE comment_id='.$comment.' AND comment_pic_id='.$picture);
				$after=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album_comment WHERE comment_id='.$comment.' AND comment_pic_id='.$picture.' FOR UPDATE');
				if(count($after)!==1){throw new AlbumFileCoordinatorFailure('Album edited comment missing');}
				$expected=$before;foreach(array('comment_text'=>$text,'comment_edit_time'=>$now,'comment_edit_count'=>$count,'comment_edit_user_id'=>$actor['user_id'])as$field=>$value){$expected[$field]=is_int($value)?(is_int($after[0][$field])?$value:(string)$value):$value;}
				if(self::named($after[0])!==$expected){throw new AlbumFileCoordinatorFailure('Album edited comment readback differs');}
			}
			$storage->commit();return true;
		}
		finally{if($storage){$storage->release();}}
	}

	function hotornot($snapshot,$owner,$category,$picture,$point)
	{
		$storage=null;
		try
		{
			$context=$this->context($storage,$snapshot,$owner,$category,$picture,ALBUM_AUTH_VIEW|ALBUM_AUTH_RATE);
			if(self::integer($context['picture']['pic_lock'],0,1)===1&&empty($context['access']['moderator'])){throw new AlbumDiscussionFailure('Pic_Locked');}
			if(!defined('ALBUM_SP_CONFIG_TABLE')||ALBUM_SP_CONFIG_TABLE!==$this->prefix.'album_sp_config'){throw new AlbumDiscussionFailure('Album_discussion_failed');}
			$storage->pin_authority_table('album_sp_config');$settings=array();
			foreach($this->rows($storage,'SELECT config_name,config_value FROM '.ALBUM_SP_CONFIG_TABLE." WHERE config_name IN('hon_rate_users','hon_rate_times','hon_rate_sep','hon_rate_where') ORDER BY config_name LOCK IN SHARE MODE")as$row)
			{
				if(!isset($row['config_name'],$row['config_value'])||!is_string($row['config_value'])||isset($settings[$row['config_name']])){throw new AlbumDiscussionFailure('Album_discussion_failed');}$settings[$row['config_name']]=$row['config_value'];
			}
			if(count($settings)!==4){throw new AlbumDiscussionFailure('Album_discussion_failed');}
			foreach(array('hon_rate_users','hon_rate_times','hon_rate_sep')as$key){self::integer($settings[$key],0,1);}
			$actor=$context['userdata'];if(!$actor['session_logged_in']&&$settings['hon_rate_users']!=='1'){throw new AlbumDiscussionFailure('Not_Authorised');}
			$allowed=array();foreach(preg_split('/[^0-9]+/',$settings['hon_rate_where'],-1,PREG_SPLIT_NO_EMPTY)as$id){$id=(int)$id;if($id>0){$allowed[]=$id;}}
			if($allowed&&!in_array($category,$allowed,true)){throw new AlbumDiscussionFailure('Not_Authorised');}
			$field=$settings['hon_rate_sep']==='1'?'rate_hon_point':'rate_point';
			// Preserve the configured repeat-vote mode, including enabled guests.
			$this->vote($storage,$context,$point,$field,!$actor['session_logged_in']||$settings['hon_rate_times']==='1');
			$storage->commit();return true;
		}
		finally{if($storage){$storage->release();}}
	}
}

function album_discussion_request($method,$arguments)
{
	global $db,$table_prefix,$lang;
	try
	{
		if(!in_array($method,array('publish','change','hotornot'),true)||!is_array($arguments)){throw new AlbumFileCoordinatorFailure('Unknown Album discussion operation');}
		return call_user_func_array(array(new AlbumPictureDiscussion($db,$table_prefix),$method),$arguments);
	}
	catch(Exception$error){$failure=$error;}
	catch(Error$error){$failure=$error;}
	$key=$failure instanceof AlbumDiscussionFailure?$failure->language_key:'Album_discussion_failed';
	$message=isset($lang[$key])&&is_string($lang[$key])?$lang[$key]:(isset($lang['Album_discussion_failed'])?$lang['Album_discussion_failed']:'The comment or rating could not be confirmed. Please reload the picture page.');
	if(!($failure instanceof AlbumDiscussionFailure)){error_log('phpBB Album discussion action failed: '.get_class($failure));}
	message_die(GENERAL_ERROR,$message);
}
