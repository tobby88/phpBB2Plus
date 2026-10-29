<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_picture_delete.php';

class AlbumPictureModeration
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
		if (!is_array($rows)) { throw new AlbumFileCoordinatorFailure('Album moderation rows unavailable'); } return $rows;
	}
	function apply($snapshot, $owner, $category, $picture_ids, $mode, $target = null)
	{
		if (!is_int($owner) || !is_int($category) || !is_array($picture_ids) || !$picture_ids ||
			!in_array($mode, array('move','lock','unlock','approval','unapproval','delete'), true) ||
			($mode === 'move' ? (!is_int($target) || $target < 1 || $target > 16777215) : $target !== null)) { throw new AlbumFileCoordinatorFailure('Invalid Album moderation request'); }
		$ids = array();
		foreach ($picture_ids as $id)
		{
			if (!is_int($id) || $id < 1 || $id > 4294967295) { throw new AlbumFileCoordinatorFailure('Invalid Album moderation identifier'); } $ids[$id] = $id;
		}
		sort($ids, SORT_NUMERIC);
		if ($mode === 'delete') { return (new AlbumPictureDelete($this->database,$this->prefix,$this->root))->remove($snapshot,$owner,$category,$ids,true); }
		$storage = null;
		try
		{
			$storage = new AlbumFileCoordinator($this->database,$this->prefix); $storage->begin();
			$authority = new AlbumLifecycleAuthority($storage,$this->prefix);
			$context = $authority->lock($snapshot,$owner,$category,ALBUM_AUTH_MODERATOR);
			if ($context === false || !$context['userdata']['session_logged_in']) { throw new AlbumFileCoordinatorFailure('Current Album moderation denied'); }
			if (in_array($mode,array('approval','unapproval'),true) && (int)$context['category']['cat_approval'] === ALBUM_ADMIN && $context['userdata']['user_level'] !== ADMIN) { throw new AlbumFileCoordinatorFailure('Current Album approval requires administrator'); }
			$destination = $category;
			if ($mode === 'move')
			{
				// Legacy moves stay within the same public or personal gallery.
				// Recheck upload/view access to the actual destination, not its UI snapshot.
				$target_authority = new AlbumLifecycleAuthority($storage,$this->prefix);
				$destination_context = $target_authority->lock($snapshot,$owner,$target,ALBUM_AUTH_VIEW_AND_UPLOAD);
				if ($destination_context === false) { throw new AlbumFileCoordinatorFailure('Current Album move destination denied'); }
				$destination = $target;
			}
			$field = $mode === 'move' ? 'pic_cat_id' : (in_array($mode,array('lock','unlock'),true) ? 'pic_lock' : 'pic_approval');
			$value = $mode === 'move' ? $destination : (in_array($mode,array('lock','approval'),true) ? 1 : 0);
			foreach (array_chunk($ids,1000) as $chunk)
			{
				$list = implode(',',$chunk);
				$before = $this->rows($storage,'SELECT * FROM '.$this->prefix.'album WHERE pic_id IN('.$list.') ORDER BY pic_id FOR UPDATE');
				if (count($before) !== count($chunk)) { throw new AlbumFileCoordinatorFailure('Album moderation selection changed'); }
				foreach ($before as $index => $photo)
				{
					if ((string)$photo['pic_id'] !== (string)$chunk[$index] || (string)$photo['pic_cat_id'] !== (string)$category) { throw new AlbumFileCoordinatorFailure('Album moderation category changed'); }
				}
				$storage->sql_query('UPDATE '.$this->prefix.'album SET '.$field.'='.$value.' WHERE pic_cat_id='.$category.' AND pic_id IN('.$list.')');
				$after = $this->rows($storage,'SELECT * FROM '.$this->prefix.'album WHERE pic_id IN('.$list.') ORDER BY pic_id FOR UPDATE');
				if (count($after) !== count($before)) { throw new AlbumFileCoordinatorFailure('Album moderation readback missing'); }
				foreach ($before as $index => $photo)
				{
					foreach ($photo as $column => $previous)
					{
						if (is_int($column)) { continue; }
						if (!array_key_exists($column,$after[$index]) || ($column === $field ? (string)$after[$index][$column] !== (string)$value : $after[$index][$column] !== $previous)) { throw new AlbumFileCoordinatorFailure('Album moderation readback differs'); }
					}
				}
				if ($mode === 'move')
				{
					$storage->sql_query('UPDATE '.$this->prefix.'album_comment SET comment_cat_id='.$destination.' WHERE comment_pic_id IN('.$list.')');
					if ($this->rows($storage,'SELECT comment_id FROM '.$this->prefix.'album_comment WHERE comment_pic_id IN('.$list.') AND comment_cat_id<>'.$destination.' LIMIT 1')) { throw new AlbumFileCoordinatorFailure('Album moved comment category differs'); }
				}
			}
			$storage->commit();
			return array('mode'=>$mode,'updated'=>count($ids),'category'=>$destination);
		}
		finally { if ($storage) { $storage->release(); } }
	}
}
