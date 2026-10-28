<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_file_coordinator.php';
require_once __DIR__ . '/album_file_storage.php';

// Internal deletion receipts. The caller must hold current actor/category
// authority and delete the corresponding metadata in this SAME transaction.
// Resolution requires a previously committed receipt, never a posted pathname.
class AlbumCleanupReceipt
{
	private $storage;
	private $prefix;
	private $root;
	private $root_hash;

	function __construct($storage, $prefix, $root = null)
	{
		if (!($storage instanceof AlbumFileCoordinator) || !$storage->namespace_matches($prefix)) { throw new AlbumFileCoordinatorFailure('Invalid Album cleanup namespace'); }
		$this->storage = $storage; $this->prefix = $prefix; $this->root = $root === null ? dirname(__DIR__) : $root;
		// Validate the actual fixed filesystem roots even for an empty inventory.
		$this->files(); $this->root_hash = hash('sha256', realpath($this->root));
	}

	private static function integer($value, $minimum, $maximum)
	{
		if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]*|-1)$/D', (string)$value) || (float)$value < $minimum || (float)$value > $maximum) { throw new AlbumFileCoordinatorFailure('Invalid Album cleanup integer'); }
		return (int)$value;
	}
	private static function hex($value, $length)
	{
		return is_string($value) && strlen($value) === $length && preg_match('/^[a-f0-9]+$/D', $value) === 1;
	}
	private static function literal($value) { return 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci'; }
	private static function named($row) { $result = array(); foreach ($row as $key => $value) { if (!is_int($key)) { $result[$key] = $value; } } return $result; }
	private function where($operation) { return "operation_id='" . $operation . "' AND HEX(operation_id)='" . strtoupper(bin2hex($operation)) . "'"; }
	private function rows($sql)
	{
		$result = $this->storage->sql_query($sql);
		try { $rows = $this->storage->sql_fetchrowset($result); } finally { $this->storage->sql_freeresult($result); }
		if (!is_array($rows)) { throw new AlbumFileCoordinatorFailure('Album cleanup rows unavailable'); } return $rows;
	}
	private function files($guard = null)
	{
		$storage = $this->storage;
		return new AlbumFileStorage(function($context) use ($storage, $guard) {
			if (!$storage->can_publish()) { return false; }
			if (in_array($context['action'], array('roots','snapshot'), true)) { return true; }
			return $guard !== null && call_user_func($guard, $context) === true;
		}, $this->root);
	}
	private function binding($actor, $owner, $category, $picture, $picture_count, $items)
	{
		$manifest = json_encode(array('version'=>1, 'root'=>$this->root_hash, 'actor'=>$actor, 'owner'=>$owner, 'category'=>$category, 'picture'=>$picture, 'picture_count'=>$picture_count, 'items'=>$items));
		if (!is_string($manifest)) { throw new AlbumFileCoordinatorFailure('Album cleanup manifest invalid'); } return hash('sha256', $manifest);
	}

	function prepare($actor, $owner, $category, $picture_ids)
	{
		$actor = self::integer($actor, -1, 8388607); $owner = self::integer($owner, 0, 8388607); $category = self::integer($category, 1, 16777215);
		if ($actor === 0 || !is_array($picture_ids) || !$picture_ids || !$this->storage->can_publish()) { throw new AlbumFileCoordinatorFailure('Invalid Album cleanup preparation'); }
		$category_rows = $this->rows('SELECT cat_id FROM '.$this->prefix.'album_cat WHERE cat_id='.$category.' AND cat_user_id='.$owner.' LOCK IN SHARE MODE');
		if (count($category_rows) !== 1) { throw new AlbumFileCoordinatorFailure('Album cleanup category changed'); }
		$ids = array(); foreach ($picture_ids as $id) { $id = self::integer($id, 1, 4294967295); $ids[$id] = $id; } sort($ids, SORT_NUMERIC);
		$files = $this->files(); $jobs = array();
		// This is a journal chunk size, never a limit on category/bulk deletion.
		// Five possible paths per photo stay below the uint16 item count.
		foreach (array_chunk($ids, 10000) as $chunk)
		{
			$photos = $this->rows('SELECT pic_id,pic_filename,pic_thumbnail FROM '.$this->prefix.'album WHERE pic_cat_id='.$category.' AND pic_id IN('.implode(',', $chunk).') ORDER BY pic_id FOR UPDATE');
			if (count($photos) !== count($chunk)) { throw new AlbumFileCoordinatorFailure('Album cleanup pictures changed'); }
			$items = array(); $seen = array();
			foreach ($photos as $index => $photo)
			{
				$thumbnail = $photo['pic_thumbnail'] === null ? '' : $photo['pic_thumbnail'];
				if (self::integer($photo['pic_id'], 1, 4294967295) !== $chunk[$index] || !AlbumFileStorage::filename($photo['pic_filename']) || ($thumbnail !== '' && !AlbumFileStorage::filename($thumbnail))) { throw new AlbumFileCoordinatorFailure('Invalid Album cleanup picture identity'); }
				$paths = array(array('original', $photo['pic_filename']));
				foreach (array('small','medium') as $scope) { $paths[] = array($scope, $photo['pic_filename']); if ($thumbnail !== '') { $paths[] = array($scope, $thumbnail); } }
				foreach ($paths as $path)
				{
					$key = hash('sha256', $path[0]."\0".$path[1]); if (isset($seen[$key])) { continue; } $seen[$key] = true;
					$items[] = array('scope'=>$path[0], 'name'=>$path[1], 'metadata'=>$files->snapshot($path[0], $path[1]));
				}
			}
			$operation = bin2hex(phpbb_random_bytes(16)); $capability = bin2hex(phpbb_random_bytes(32)); $picture = count($chunk) === 1 ? $chunk[0] : null;
			$binding = $this->binding($actor, $owner, $category, $picture, count($chunk), $items);
			$this->storage->sql_query('INSERT INTO '.$this->prefix.'album_file_jobs(operation_id,schema_version,operation_kind,operation_state,actor_id,owner_id,category_id,picture_id,binding_hash,capability_hash,item_count,picture_count,created_at,completed_at) VALUES('.self::literal($operation).",1,'delete','cleanup',".$actor.','.$owner.','.$category.','.($picture === null ? 'NULL' : $picture).','.self::literal($binding).','.self::literal($capability).','.count($items).','.count($chunk).','.time().',NULL)');
			foreach ($items as $index => $item)
			{
				$metadata = $item['metadata'];
				$this->storage->sql_query('INSERT INTO '.$this->prefix.'album_file_items(operation_id,item_index,item_hash,file_scope,filename,file_present,file_bytes,file_sha256,item_state) VALUES('.self::literal($operation).','.$index.','.self::literal(hash('sha256',$item['scope']."\0".$item['name'])).','.self::literal($item['scope']).','.self::literal($item['name']).','.($metadata['present'] ? '1' : '0').','.$metadata['bytes'].','.($metadata['sha256'] === null ? 'NULL' : self::literal($metadata['sha256'])).",'pending')");
			}
			$descriptor = array('operation'=>$operation, 'binding'=>$binding, 'capability'=>$capability); $where = $this->where($operation);
			$saved = $this->parse($this->rows('SELECT * FROM '.$this->prefix.'album_file_jobs WHERE '.$where.' FOR UPDATE'),$this->rows('SELECT * FROM '.$this->prefix.'album_file_items WHERE '.$where.' ORDER BY item_index FOR UPDATE'),$descriptor);
			if ($saved['state'] !== 'cleanup' || array_unique($saved['states']) !== array('pending')) { throw new AlbumFileCoordinatorFailure('Album cleanup preparation readback differs'); }
			$jobs[] = $descriptor;
		}
		return $jobs;
	}

	private function parse($headers, $rows, $descriptor)
	{
		if (count($headers) !== 1) { throw new AlbumFileCoordinatorFailure('Committed Album cleanup receipt missing'); } $header = self::named($headers[0]);
		if (count($header) !== 14 || $header['operation_id'] !== $descriptor['operation'] || $header['binding_hash'] !== $descriptor['binding'] || $header['capability_hash'] !== $descriptor['capability'] || $header['operation_kind'] !== 'delete' || self::integer($header['schema_version'],1,1) !== 1 || !in_array($header['operation_state'],array('cleanup','complete'),true)) { throw new AlbumFileCoordinatorFailure('Album cleanup receipt differs'); }
		$actor = self::integer($header['actor_id'],-1,8388607); $owner = self::integer($header['owner_id'],0,8388607); $category = self::integer($header['category_id'],1,16777215); $picture = $header['picture_id'] === null ? null : self::integer($header['picture_id'],1,4294967295);
		$count = self::integer($header['picture_count'],1,10000); self::integer($header['created_at'],1,4294967295);
		if ($actor === 0 || ($count === 1) !== ($picture !== null) || self::integer($header['item_count'],1,65535) !== count($rows) || ($header['operation_state'] === 'cleanup' ? $header['completed_at'] !== null : self::integer($header['completed_at'],1,4294967295) < 1)) { throw new AlbumFileCoordinatorFailure('Invalid Album cleanup header'); }
		$items = array(); $states = array(); $seen = array();
		foreach ($rows as $index => $row)
		{
			$row = self::named($row);
			if (count($row) !== 9 || $row['operation_id'] !== $descriptor['operation'] || self::integer($row['item_index'],0,65534) !== $index || !in_array($row['file_scope'],array('original','small','medium'),true) || !AlbumFileStorage::filename($row['filename']) || $row['item_hash'] !== hash('sha256',$row['file_scope']."\0".$row['filename']) || isset($seen[$row['item_hash']]) || !in_array($row['item_state'],array('pending','removed','missing','changed','shared'),true)) { throw new AlbumFileCoordinatorFailure('Invalid Album cleanup item'); }
			$present = self::integer($row['file_present'],0,1) === 1; $bytes = self::integer($row['file_bytes'],0,AlbumFileStorage::MAX_BYTES); $metadata = array('present'=>$present,'bytes'=>$bytes,'sha256'=>$row['file_sha256']);
			if (!AlbumFileStorage::metadata($metadata) || ($header['operation_state'] === 'complete' && $row['item_state'] === 'pending')) { throw new AlbumFileCoordinatorFailure('Invalid Album cleanup item metadata'); }
			$seen[$row['item_hash']] = true; $items[] = array('scope'=>$row['file_scope'],'name'=>$row['filename'],'metadata'=>$metadata); $states[] = $row['item_state'];
		}
		if ($this->binding($actor,$owner,$category,$picture,$count,$items) !== $descriptor['binding']) { throw new AlbumFileCoordinatorFailure('Album cleanup manifest changed'); }
		return array('state'=>$header['operation_state'],'items'=>$items,'states'=>$states);
	}
	private function referenced($item)
	{
		$hex = strtoupper(bin2hex($item['name'])); $where = "HEX(pic_filename)='".$hex."'";
		if ($item['scope'] !== 'original') { $where .= " OR HEX(pic_thumbnail)='".$hex."'"; }
		// Windows filesystem aliases can differ in case even when their DB
		// bytes differ. Conservative collation matches preserve those survivors.
		if (DIRECTORY_SEPARATOR === chr(92))
		{
			$where = 'pic_filename='.self::literal($item['name']);
			if ($item['scope'] !== 'original') { $where .= ' OR pic_thumbnail='.self::literal($item['name']); }
		}
		return count($this->rows('SELECT pic_id FROM '.$this->prefix.'album WHERE '.$where.' LIMIT 1')) > 0;
	}

	function resolve($descriptor)
	{
		if (!is_array($descriptor) || count($descriptor) !== 3 || !isset($descriptor['operation'],$descriptor['binding'],$descriptor['capability']) || !self::hex($descriptor['operation'],32) || !self::hex($descriptor['binding'],64) || !self::hex($descriptor['capability'],64)) { throw new AlbumFileCoordinatorFailure('Invalid Album cleanup capability'); }
		$where = $this->where($descriptor['operation']); $header_sql = 'SELECT * FROM '.$this->prefix.'album_file_jobs WHERE '.$where; $items_sql = 'SELECT * FROM '.$this->prefix.'album_file_items WHERE '.$where.' ORDER BY item_index';
		// A receipt prepared in this transaction MUST NOT authorize unlink.
		$this->parse($this->storage->durable_rows($header_sql),$this->storage->durable_rows($items_sql),$descriptor);
		$job = $this->parse($this->rows($header_sql.' FOR UPDATE'),$this->rows($items_sql.' FOR UPDATE'),$descriptor);
		if ($job['state'] === 'complete') { return $job['states']; }
		foreach ($job['items'] as $index => $item)
		{
			if ($job['states'][$index] !== 'pending') { continue; }
			if ($this->referenced($item)) { $state = 'shared'; }
			else
			{
				$files = $this->files(function($context) use ($item) {
					return $context['action'] === 'remove' && $context['scope'] === $item['scope'] && $context['name'] === $item['name'] && $context['operation'] === null && $context['metadata'] === $item['metadata'] && !$this->referenced($item);
				});
				$state = $files->remove_exact($item['scope'],$item['name'],$item['metadata']);
			}
			if ($state === 'busy') { continue; }
			if (!in_array($state,array('removed','missing','changed','shared'),true)) { throw new AlbumFileCoordinatorFailure('Unexpected Album cleanup result'); }
			$this->storage->sql_query('UPDATE '.$this->prefix.'album_file_items SET item_state='.self::literal($state).' WHERE '.$where.' AND item_index='.$index." AND item_state='pending'");
			if ((int)$this->storage->sql_affectedrows() !== 1) { throw new AlbumFileCoordinatorFailure('Album cleanup item changed'); } $job['states'][$index] = $state;
		}
		$readback = $this->parse($this->rows($header_sql.' FOR UPDATE'),$this->rows($items_sql.' FOR UPDATE'),$descriptor);
		if ($readback['states'] !== $job['states']) { throw new AlbumFileCoordinatorFailure('Album cleanup state readback differs'); }
		if (!in_array('pending',$job['states'],true))
		{
			$this->storage->sql_query('UPDATE '.$this->prefix.'album_file_jobs SET operation_state=\'complete\',completed_at='.time().' WHERE '.$where." AND operation_state='cleanup'");
			if ((int)$this->storage->sql_affectedrows() !== 1) { throw new AlbumFileCoordinatorFailure('Album cleanup completion changed'); }
			$readback = $this->parse($this->rows($header_sql.' FOR UPDATE'),$this->rows($items_sql.' FOR UPDATE'),$descriptor);
			if ($readback['state'] !== 'complete' || $readback['states'] !== $job['states']) { throw new AlbumFileCoordinatorFailure('Album cleanup completion readback differs'); }
		}
		return $job['states'];
	}
}
