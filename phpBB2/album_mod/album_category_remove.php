<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_lifecycle_authority.php';
require_once __DIR__ . '/album_cleanup_receipt.php';

class AlbumCategoryRemove
{
	private $database;
	private $prefix;
	private $root;
	function __construct($database,$prefix,$root=null)
	{
		$this->database=$database; $this->prefix=$prefix; $this->root=$root===null?dirname(__DIR__):$root;
	}
	private function rows($storage,$sql)
	{
		$result=$storage->sql_query($sql);
		try{$rows=$storage->sql_fetchrowset($result);}finally{$storage->sql_freeresult($result);}
		if(!is_array($rows)){throw new AlbumFileCoordinatorFailure('Album category rows unavailable');}return $rows;
	}
	private static function identifier($value,$minimum,$maximum)
	{
		if((!is_int($value)&&!is_string($value))||!preg_match('/^(?:0|[1-9][0-9]*|-1)$/D',(string)$value)||(float)$value<$minimum||(float)$value>$maximum){throw new AlbumFileCoordinatorFailure('Invalid Album category identity');}return (int)$value;
	}
	private static function named($row)
	{
		$result=array();foreach($row as$key=>$value){if(!is_int($key)){$result[$key]=$value;}}return $result;
	}
	private static function ancestry($categories,$start,$forbidden=null)
	{
		$seen=array();
		while($start>0)
		{
			if($start===$forbidden||isset($seen[$start])||!isset($categories[$start])){throw new AlbumFileCoordinatorFailure('Album category ancestry changed');}
			$seen[$start]=true;$start=self::identifier($categories[$start]['cat_parent'],-1,16777215);
		}
	}
	function remove($snapshot,$owner,$category,$target)
	{
		if(!is_int($owner)||$owner<0||$owner>8388607||!is_int($category)||$category<1||$category>16777215||!is_int($target)||
			($target!==ALBUM_JUMPBOX_DELETE&&($target<1||$target>16777215))||$target===$category){throw new AlbumFileCoordinatorFailure('Invalid Album category removal request');}
		$storage=null;$jobs=array();$picture_ids=array();$children=0;$parent=null;$parent_title='';
		try
		{
			$storage=new AlbumFileCoordinator($this->database,$this->prefix);$storage->begin();$authority=new AlbumLifecycleAuthority($storage,$this->prefix);
			$context=$owner===0?$authority->lock_admin($snapshot,$category):$authority->lock($snapshot,$owner,$category,ALBUM_AUTH_MANAGE_PERSONAL_CATEGORIES);
			if($context===false||!$context['userdata']['session_logged_in']||($owner>0&&$context['userdata']['user_level']!==ADMIN&&$context['userdata']['user_id']!==$owner)){throw new AlbumFileCoordinatorFailure('Current Album category management denied');}
			$categories=array();
			foreach($this->rows($storage,'SELECT * FROM '.$this->prefix.'album_cat WHERE cat_user_id='.$owner.' ORDER BY cat_id FOR UPDATE')as$row)
			{
				$id=self::identifier($row['cat_id'],1,16777215);if(isset($categories[$id])||(string)$row['cat_user_id']!==(string)$owner){throw new AlbumFileCoordinatorFailure('Album category owner changed');}$categories[$id]=self::named($row);
			}
			if(!isset($categories[$category])){throw new AlbumFileCoordinatorFailure('Album category disappeared');}
			$parent=self::identifier($categories[$category]['cat_parent'],-1,16777215);self::ancestry($categories,$category);
			if($owner>0&&$parent===0&&$context['userdata']['user_level']!==ADMIN){throw new AlbumFileCoordinatorFailure('Personal gallery root requires administrator');}
			if($parent>0){$parent_title=$categories[$parent]['cat_title'];}
			if($this->rows($storage,'SELECT cat_id FROM '.$this->prefix.'album_cat WHERE cat_parent='.$category.' AND cat_user_id<>'.$owner.' LIMIT 1 LOCK IN SHARE MODE')){throw new AlbumFileCoordinatorFailure('Cross-owner child category requires repair');}
			if($target!==ALBUM_JUMPBOX_DELETE)
			{
				if(!isset($categories[$target])){throw new AlbumFileCoordinatorFailure('Album category destination owner changed');}self::ancestry($categories,$target,$category);
			}
			foreach($this->rows($storage,'SELECT pic_id FROM '.$this->prefix.'album WHERE pic_cat_id='.$category.' ORDER BY pic_id FOR UPDATE')as$photo){$picture_ids[]=self::identifier($photo['pic_id'],1,4294967295);}
			if($target===ALBUM_JUMPBOX_DELETE&&$picture_ids)
			{
				$receipt=new AlbumCleanupReceipt($storage,$this->prefix,$this->root);$jobs=$receipt->prepare($context['userdata']['user_id'],$owner,$category,$picture_ids);
			}
			foreach(array_chunk($picture_ids,1000)as$chunk)
			{
				$list=implode(',',$chunk);
				if($target===ALBUM_JUMPBOX_DELETE)
				{
					foreach(array('album_comment'=>'comment_pic_id','album_rate'=>'rate_pic_id','album'=>'pic_id')as$table=>$field)
					{
						$storage->sql_query('DELETE FROM '.$this->prefix.$table.' WHERE '.$field.' IN('.$list.')');
						if($this->rows($storage,'SELECT '.$field.' FROM '.$this->prefix.$table.' WHERE '.$field.' IN('.$list.') LIMIT 1')){throw new AlbumFileCoordinatorFailure('Album category removal readback differs');}
					}
				}
				else
				{
					$before=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album WHERE pic_cat_id='.$category.' AND pic_id IN('.$list.') ORDER BY pic_id FOR UPDATE');
					if(count($before)!==count($chunk)){throw new AlbumFileCoordinatorFailure('Album category pictures changed');}
					$storage->sql_query('UPDATE '.$this->prefix.'album SET pic_cat_id='.$target.' WHERE pic_cat_id='.$category.' AND pic_id IN('.$list.')');
					$after=$this->rows($storage,'SELECT * FROM '.$this->prefix.'album WHERE pic_id IN('.$list.') ORDER BY pic_id FOR UPDATE');
					if(count($after)!==count($before)){throw new AlbumFileCoordinatorFailure('Album category moved pictures missing');}
					foreach($before as$index=>$photo){$expected=self::named($photo);$saved=self::named($after[$index]);$expected['pic_cat_id']=$saved['pic_cat_id'];if((string)$saved['pic_cat_id']!==(string)$target||$saved!==$expected){throw new AlbumFileCoordinatorFailure('Album category moved picture differs');}}
					$storage->sql_query('UPDATE '.$this->prefix.'album_comment SET comment_cat_id='.$target.' WHERE comment_pic_id IN('.$list.')');
					if($this->rows($storage,'SELECT comment_id FROM '.$this->prefix.'album_comment WHERE comment_pic_id IN('.$list.') AND comment_cat_id<>'.$target.' LIMIT 1')){throw new AlbumFileCoordinatorFailure('Album category moved comment differs');}
				}
			}
			$expected=$categories;unset($expected[$category]);
			foreach($expected as$id=>$row){if((string)$row['cat_parent']===(string)$category){$expected[$id]['cat_parent']=is_int($row['cat_parent'])?$parent:(string)$parent;$children++;}}
			// Retain direct children, their relative order and all unrelated data.
			$storage->sql_query('UPDATE '.$this->prefix.'album_cat SET cat_parent='.$parent.' WHERE cat_user_id='.$owner.' AND cat_parent='.$category);
			if($this->rows($storage,'SELECT pic_id FROM '.$this->prefix.'album WHERE pic_cat_id='.$category.' LIMIT 1')){throw new AlbumFileCoordinatorFailure('Album category is not empty');}
			$storage->sql_query('DELETE FROM '.$this->prefix.'album_cat WHERE cat_id='.$category.' AND cat_user_id='.$owner);
			$after=array();foreach($this->rows($storage,'SELECT * FROM '.$this->prefix.'album_cat WHERE cat_user_id='.$owner.' ORDER BY cat_id FOR UPDATE')as$row){$after[self::identifier($row['cat_id'],1,16777215)]=self::named($row);}
			if($after!==$expected){throw new AlbumFileCoordinatorFailure('Album category removal hierarchy differs');}
			$storage->commit();
		}
		finally{if($storage){$storage->release();}}
		$pending=0;
		foreach($jobs as$job)
		{
			$cleanup=null;
			try{$cleanup=new AlbumFileCoordinator($this->database,$this->prefix);$cleanup->begin();$resolver=new AlbumCleanupReceipt($cleanup,$this->prefix,$this->root);$states=$resolver->resolve($job);$cleanup->commit();if(in_array('pending',$states,true)){$pending++;}}
			catch(Exception$error){$pending++;error_log('phpBB Album category cleanup pending: '.get_class($error));}
			catch(Error$error){$pending++;error_log('phpBB Album category cleanup pending: '.get_class($error));}
			finally{if($cleanup){$cleanup->release();}}
		}
		return array('deleted'=>$target===ALBUM_JUMPBOX_DELETE?count($picture_ids):0,'moved'=>$target===ALBUM_JUMPBOX_DELETE?0:count($picture_ids),'children'=>$children,'parent'=>$parent,'parent_title'=>$parent_title,'jobs'=>$jobs,'pending'=>$pending);
	}
}

function album_category_remove_request($owner,$category,$target)
{
	global $db,$table_prefix,$phpbb_root_path,$userdata,$lang;
	try
	{
		$writer=new AlbumCategoryRemove($db,$table_prefix,$phpbb_root_path);
		return $writer->remove($userdata,$owner,$category,$target);
	}
	catch(Exception$error){error_log('phpBB Album category removal not confirmed: '.get_class($error));}
	catch(Error$error){error_log('phpBB Album category removal not confirmed: '.get_class($error));}
	message_die(GENERAL_MESSAGE,isset($lang['Album_category_removal_failed'])?$lang['Album_category_removal_failed']:'The category change could not be confirmed. Please reload the album page.');
}
