<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_lifecycle_authority.php';
require_once __DIR__ . '/album_cleanup_receipt.php';

class AlbumPictureDelete
{
	private $database;
	private $prefix;
	private $root;
	function __construct($database, $prefix, $root = null)
	{
		$this->database = $database; $this->prefix = $prefix; $this->root = $root === null ? dirname(__DIR__) : $root;
	}
	private function rows($storage, $sql)
	{
		$result = $storage->sql_query($sql);
		try { $rows = $storage->sql_fetchrowset($result); } finally { $storage->sql_freeresult($result); }
		if (!is_array($rows)) { throw new AlbumFileCoordinatorFailure('Album deletion rows unavailable'); } return $rows;
	}

	function remove($snapshot, $owner, $category, $picture_ids)
	{
		if (!is_int($owner) || !is_int($category) || !is_array($picture_ids) || !$picture_ids) { throw new AlbumFileCoordinatorFailure('Invalid Album deletion target'); }
		$ids = array();
		foreach ($picture_ids as $id)
		{
			if (!is_int($id) || $id < 1 || $id > 4294967295) { throw new AlbumFileCoordinatorFailure('Invalid Album picture identifier'); } $ids[$id] = $id;
		}
		sort($ids, SORT_NUMERIC); $storage = null; $jobs = array();
		try
		{
			$storage = new AlbumFileCoordinator($this->database, $this->prefix); $storage->begin();
			$authority = new AlbumLifecycleAuthority($storage, $this->prefix);
			$context = $authority->lock($snapshot, $owner, $category, ALBUM_AUTH_VIEW | ALBUM_AUTH_DELETE);
			if ($context === false || !$context['userdata']['session_logged_in']) { throw new AlbumFileCoordinatorFailure('Current Album deletion denied'); }
			$actor = $context['userdata']; $moderator = !empty($context['access']['moderator']) || $actor['user_level'] === ADMIN;
			foreach (array_chunk($ids,1000) as $chunk)
			{
				$photos = $this->rows($storage,'SELECT pic_id,pic_user_id FROM '.$this->prefix.'album WHERE pic_cat_id='.$category.' AND pic_id IN('.implode(',',$chunk).') ORDER BY pic_id FOR UPDATE');
				if (count($photos) !== count($chunk)) { throw new AlbumFileCoordinatorFailure('Album deletion target changed'); }
				foreach ($photos as $index => $photo)
				{
					if ((string)$photo['pic_id'] !== (string)$chunk[$index] || (!$moderator && (string)$photo['pic_user_id'] !== (string)$actor['user_id'])) { throw new AlbumFileCoordinatorFailure('Album deletion author changed'); }
				}
			}
			$receipt = new AlbumCleanupReceipt($storage,$this->prefix,$this->root); $jobs = $receipt->prepare($actor['user_id'],$owner,$category,$ids);
			foreach (array_chunk($ids,1000) as $chunk)
			{
				$list = implode(',',$chunk);
				foreach (array('album_comment'=>'comment_pic_id','album_rate'=>'rate_pic_id','album'=>'pic_id') as $suffix => $field)
				{
					$storage->sql_query('DELETE FROM '.$this->prefix.$suffix.' WHERE '.$field.' IN('.$list.')');
					if ($this->rows($storage,'SELECT '.$field.' FROM '.$this->prefix.$suffix.' WHERE '.$field.' IN('.$list.') LIMIT 1')) { throw new AlbumFileCoordinatorFailure('Album deletion readback differs'); }
				}
			}
			$storage->commit();
		}
		finally { if ($storage) { $storage->release(); } }
		// This point is reached only after an acknowledged metadata commit. A
		// fresh coordinator reads committed receipts; no prior user lock survives.
		$pending = 0;
		foreach ($jobs as $job)
		{
			$cleanup = null;
			try
			{
				$cleanup = new AlbumFileCoordinator($this->database,$this->prefix); $cleanup->begin();
				$resolver = new AlbumCleanupReceipt($cleanup,$this->prefix,$this->root); $states = $resolver->resolve($job); $cleanup->commit();
				if (in_array('pending',$states,true)) { $pending++; }
			}
			catch (Exception $error) { $pending++; error_log('phpBB Album committed deletion cleanup pending: '.get_class($error)); }
			catch (Error $error) { $pending++; error_log('phpBB Album committed deletion cleanup pending: '.get_class($error)); }
			finally { if ($cleanup) { $cleanup->release(); } }
		}
		return array('deleted'=>count($ids),'jobs'=>$jobs,'pending'=>$pending,'category'=>$context['category']);
	}
}
