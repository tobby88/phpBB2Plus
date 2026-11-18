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
		$stage = in_array($context['action'], array('cache-stage-snapshot','cache-stage-remove'), true);
		if (!in_array($context['action'], array('cache-snapshot','cache-remove','cache-stage-snapshot','cache-stage-remove'), true) || !in_array($context['scope'], array('small','medium'), true) || !($stage ? AlbumFileStorage::cache_stage_filename($context['name']) : AlbumFileStorage::cache_filename($context['name']))) { return false; }
		return in_array($context['action'], array('cache-snapshot','cache-stage-snapshot'), true) || (($stage ? AlbumFileStorage::cache_stage_metadata($context['metadata']) : AlbumFileStorage::metadata($context['metadata'])) && $context['metadata']['present'] === true);
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
					// The owned namespace lease excludes active cache publishers.
					// Include their interrupted stages, but never staged uploads,
					// index files, links, originals or nested directories.
					while (($name = readdir($handle)) !== false) {
						$stage = AlbumFileStorage::cache_stage_filename($name);
						if (!$stage && !AlbumFileStorage::cache_filename($name)) { continue; }
						$item = $path.DIRECTORY_SEPARATOR.$name; clearstatcache(true, $item);
						if (is_link($item) || !is_file($item)) { continue; }
						$expected = $stage ? $files->snapshot_cache_stage($scope, $name) : $files->snapshot_cache($scope, $name);
						$result = $stage ? $files->remove_cache_stage_exact($scope, $name, $expected) : $files->remove_cache_exact($scope, $name, $expected);
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
