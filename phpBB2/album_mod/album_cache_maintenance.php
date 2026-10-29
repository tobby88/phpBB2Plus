<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__.'/album_lifecycle_authority.php';
require_once __DIR__.'/album_file_storage.php';

class AlbumCacheMaintenance
{
	private $database;
	private $prefix;
	private $root;
	private $storage = null;
	private $authority = null;

	function __construct($database, $prefix, $root = null)
	{
		$this->database = $database; $this->prefix = $prefix;
		$this->root = $root === null ? dirname(__DIR__) : $root;
	}

	function current($context)
	{
		if (!$this->storage || !$this->authority || !$this->authority->current() || !$this->storage->can_publish() || !is_array($context) || !isset($context['action'])) { return false; }
		if ($context['action'] === 'roots') { return $context['scope'] === null && $context['name'] === null; }
		if (!in_array($context['action'], array('cache-snapshot', 'cache-remove'), true) || !in_array($context['scope'], array('small','medium'), true) || !AlbumFileStorage::cache_filename($context['name'])) { return false; }
		return $context['action'] === 'cache-snapshot' || (AlbumFileStorage::metadata($context['metadata']) && $context['metadata']['present'] === true);
	}

	function clear($snapshot)
	{
		if ($this->storage !== null || !is_array($snapshot) || !isset($snapshot['session_id']) || !is_string($snapshot['session_id']) ||
			!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($snapshot['session_id'], $_POST['sid'])) { throw new AlbumFileCoordinatorFailure('Invalid Album cache administration request'); }
		$removed = 0;
		try {
			$this->storage = new AlbumFileCoordinator($this->database, $this->prefix); $this->storage->begin();
			$this->authority = new AlbumLifecycleAuthority($this->storage, $this->prefix);
			if ($this->authority->lock_cache_admin($snapshot) === false) { throw new AlbumFileCoordinatorFailure('Current Album cache administration denied'); }
			$files = new AlbumFileStorage(array($this, 'current'), $this->root);
			$base = realpath($this->root);
			foreach (array('small'=>'cache','medium'=>'med_cache') as $scope=>$directory) {
				$path = $base.DIRECTORY_SEPARATOR.'album_mod'.DIRECTORY_SEPARATOR.'upload'.DIRECTORY_SEPARATOR.$directory;
				$handle = @opendir($path); if ($handle === false) { throw new AlbumFileStorageFailure('Album cache directory unavailable'); }
				try {
					// Stream one flat directory: staged uploads, index files, links,
					// originals and nested directories never enter the deletion set.
					while (($name = readdir($handle)) !== false) {
						if (!AlbumFileStorage::cache_filename($name)) { continue; }
						$item = $path.DIRECTORY_SEPARATOR.$name; clearstatcache(true, $item);
						if (is_link($item) || !is_file($item)) { continue; }
						$expected = $files->snapshot_cache($scope, $name);
						$result = $files->remove_cache_exact($scope, $name, $expected);
						if (!in_array($result, array('removed','missing'), true)) { throw new AlbumFileStorageFailure('Album cache item changed or could not be removed'); }
						if ($result === 'removed') { $removed++; }
					}
				} finally { closedir($handle); }
			}
			$this->storage->commit();
			return array('removed'=>$removed);
		} finally { if ($this->storage) { $this->storage->release(); } }
	}
}
