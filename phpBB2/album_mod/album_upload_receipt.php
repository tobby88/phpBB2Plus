<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_file_coordinator.php';
require_once __DIR__ . '/album_file_storage.php';

class AlbumUploadReceiptFailure extends AlbumFileCoordinatorFailure {}

// Typed upload receipts and their publication capability. This does not grant
// user/category access. Final-image deletion needs a separate cleanup receipt;
// an explicit authenticated retry may clear only its own unfinished stage.
// Controllers must still lock/recheck current permissions and use the shared
// coordinator before preparing a receipt. Recovery is a separate operation.
class AlbumUploadReceipt
{
	private $storage;
	private $prefix;
	private $root;

	function __construct($storage, $prefix, $root = null)
	{
		if (!($storage instanceof AlbumFileCoordinator) || !is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D', $prefix)) { throw new AlbumUploadReceiptFailure('Invalid Album upload receipt namespace'); }
		$this->storage = $storage; $this->prefix = $prefix; $this->root = $root;
		// A caller cannot bind a different table prefix to this coordinator.
		if ($storage->namespace_matches($prefix) !== true) { throw new AlbumUploadReceiptFailure('Album upload receipt namespace differs'); }
	}

	private static function hash_value($value, $length)
	{
		return is_string($value) && strlen($value) === $length && preg_match('/^[a-f0-9]+$/D', $value) === 1;
	}

	private static function number($value, $minimum, $maximum)
	{
		if ((!is_int($value) && !is_string($value)) || preg_match('/^(?:0|[1-9][0-9]*|-1)$/D', (string) $value) !== 1 || (float) $value < $minimum || (float) $value > $maximum) { throw new AlbumUploadReceiptFailure('Invalid typed Album receipt integer'); }
		return (int) $value;
	}

	private static function descriptor($value)
	{
		if (!is_array($value) || count($value) !== 6) { throw new AlbumUploadReceiptFailure('Invalid Album upload binding'); }
		foreach (array('operation','actor','owner','category','binding','capability') as $field) { if (!array_key_exists($field, $value)) { throw new AlbumUploadReceiptFailure('Missing Album upload binding'); } }
		if (!self::hash_value($value['operation'], 32) || !self::hash_value($value['binding'], 64) || !self::hash_value($value['capability'], 64) ||
			!is_int($value['actor']) || ($value['actor'] !== -1 && ($value['actor'] < 1 || $value['actor'] > 8388607)) ||
			!is_int($value['owner']) || $value['owner'] < 0 || $value['owner'] > 8388607 || !is_int($value['category']) || $value['category'] < 1 || $value['category'] > 16777215) { throw new AlbumUploadReceiptFailure('Invalid Album upload identity'); }
		return array('operation' => $value['operation'], 'actor' => $value['actor'], 'owner' => $value['owner'], 'category' => $value['category'], 'binding' => $value['binding'], 'capability' => $value['capability']);
	}

	private static function items($items)
	{
		if (!is_array($items) || count($items) < 1 || count($items) > 3 || array_keys($items) !== range(0, count($items) - 1)) { throw new AlbumUploadReceiptFailure('Invalid Album upload item list'); }
		$result = array(); $scopes = array();
		foreach ($items as $item)
		{
			if (!is_array($item) || count($item) !== 3 || !isset($item['scope'], $item['name'], $item['metadata']) ||
				!is_string($item['scope']) || !in_array($item['scope'], array('original','small','medium'), true) || isset($scopes[$item['scope']]) || !AlbumFileStorage::filename($item['name']) ||
				!AlbumFileStorage::metadata($item['metadata']) || $item['metadata']['present'] !== true || $item['metadata']['bytes'] < 1) { throw new AlbumUploadReceiptFailure('Invalid Album upload item'); }
			$scopes[$item['scope']] = true;
			$result[] = array('scope' => $item['scope'], 'name' => $item['name'], 'metadata' => array('present' => true, 'bytes' => $item['metadata']['bytes'], 'sha256' => $item['metadata']['sha256']));
		}
		if (!isset($scopes['original'])) { throw new AlbumUploadReceiptFailure('Album upload original missing'); }
		return $result;
	}

	private static function literal($value) { return 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci'; }
	private function where($operation) { return "operation_id='" . $operation . "' AND HEX(operation_id)='" . strtoupper(bin2hex($operation)) . "'"; }
	private static function named_row($row)
	{
		if (!is_array($row)) { throw new AlbumUploadReceiptFailure('Invalid Album receipt row'); }
		$named = array(); foreach ($row as $key => $value) { if (!is_int($key)) { $named[$key] = $value; } }
		return $named;
	}

	private function parse($headers, $rows, $descriptor)
	{
		if (!$headers) { if ($rows) { throw new AlbumUploadReceiptFailure('Orphan Album upload items'); } return null; }
		if (count($headers) !== 1) { throw new AlbumUploadReceiptFailure('Ambiguous Album upload receipt'); }
		// The legacy driver returns MYSQLI_BOTH. Numeric aliases are not fields.
		$header = self::named_row($headers[0]);
		if (count($header) !== 14) { throw new AlbumUploadReceiptFailure('Invalid Album upload receipt shape'); }
		foreach (array('operation_id','schema_version','operation_kind','operation_state','actor_id','owner_id','category_id','picture_id','binding_hash','capability_hash','item_count','picture_count','created_at','completed_at') as $field) { if (!array_key_exists($field, $header)) { throw new AlbumUploadReceiptFailure('Missing Album upload receipt field'); } }
		if ($header['operation_id'] !== $descriptor['operation'] || self::number($header['schema_version'], 1, 1) !== 1 || $header['operation_kind'] !== 'upload' ||
			!in_array($header['operation_state'], array('prepared','published'), true) || self::number($header['actor_id'], -1, 8388607) !== $descriptor['actor'] || self::number($header['owner_id'], 0, 8388607) !== $descriptor['owner'] ||
			self::number($header['category_id'], 1, 16777215) !== $descriptor['category'] || $header['binding_hash'] !== $descriptor['binding'] || $header['capability_hash'] !== $descriptor['capability'] ||
			self::number($header['picture_count'], 1, 1) !== 1 || self::number($header['item_count'], 1, 3) !== count($rows)) { throw new AlbumUploadReceiptFailure('Album upload receipt binding differs'); }
		self::number($header['created_at'], 1, 4294967295);
		$published = $header['operation_state'] === 'published';
		if ($published) { $picture = self::number($header['picture_id'], 1, 4294967295); self::number($header['completed_at'], 1, 4294967295); }
		else { if ($header['picture_id'] !== null || $header['completed_at'] !== null) { throw new AlbumUploadReceiptFailure('Prepared upload has publication metadata'); } $picture = null; }
		$items = array();
		foreach ($rows as $index => $row)
		{
			$row = self::named_row($row);
			if (count($row) !== 9) { throw new AlbumUploadReceiptFailure('Invalid Album upload item shape'); }
			foreach (array('operation_id','item_index','item_hash','file_scope','filename','file_present','file_bytes','file_sha256','item_state') as $field) { if (!array_key_exists($field, $row)) { throw new AlbumUploadReceiptFailure('Missing Album upload item field'); } }
			if ($row['operation_id'] !== $descriptor['operation'] || self::number($row['item_index'], 0, 2) !== $index ||
				!is_string($row['file_scope']) || !is_string($row['filename']) || $row['item_hash'] !== hash('sha256', $row['file_scope'] . "\0" . $row['filename']) ||
				self::number($row['file_present'], 1, 1) !== 1 || $row['item_state'] !== ($published ? 'published' : 'pending')) { throw new AlbumUploadReceiptFailure('Album upload item identity/state differs'); }
			$items[] = array('scope' => $row['file_scope'], 'name' => $row['filename'], 'metadata' => array('present' => true, 'bytes' => self::number($row['file_bytes'], 1, AlbumFileStorage::MAX_BYTES), 'sha256' => $row['file_sha256']));
		}
		return array('descriptor' => $descriptor, 'state' => $header['operation_state'], 'picture' => $picture, 'items' => self::items($items));
	}

	function committed($descriptor)
	{
		$descriptor = self::descriptor($descriptor); $where = $this->where($descriptor['operation']);
		return $this->parse($this->storage->durable_rows('SELECT * FROM ' . $this->prefix . 'album_file_jobs WHERE ' . $where), $this->storage->durable_rows('SELECT * FROM ' . $this->prefix . 'album_file_items WHERE ' . $where . ' ORDER BY item_index'), $descriptor);
	}

	function prepare($descriptor, $items)
	{
		$descriptor = self::descriptor($descriptor); $items = self::items($items); $existing = $this->committed($descriptor);
		if ($existing !== null) { if ($existing['items'] !== $items) { throw new AlbumUploadReceiptFailure('Album upload retry content differs'); } return $existing; }
		$this->storage->durable_begin();
		$this->storage->durable_query('INSERT INTO ' . $this->prefix . 'album_file_jobs(operation_id,operation_kind,operation_state,actor_id,owner_id,category_id,binding_hash,capability_hash,item_count,picture_count,created_at) VALUES(' .
			self::literal($descriptor['operation']) . ",'upload','prepared'," . $descriptor['actor'] . ',' . $descriptor['owner'] . ',' . $descriptor['category'] . ',' . self::literal($descriptor['binding']) . ',' . self::literal($descriptor['capability']) . ',' . count($items) . ',1,' . time() . ')');
		foreach ($items as $index => $item)
		{
			$this->storage->durable_query('INSERT INTO ' . $this->prefix . 'album_file_items(operation_id,item_index,item_hash,file_scope,filename,file_present,file_bytes,file_sha256,item_state) VALUES(' .
				self::literal($descriptor['operation']) . ',' . $index . ',' . self::literal(hash('sha256', $item['scope'] . "\0" . $item['name'])) . ',' . self::literal($item['scope']) . ',' . self::literal($item['name']) . ',1,' . $item['metadata']['bytes'] . ',' . self::literal($item['metadata']['sha256']) . ",'pending')");
		}
		$this->storage->durable_commit(); $saved = $this->committed($descriptor);
		if ($saved === null || $saved['state'] !== 'prepared' || $saved['items'] !== $items) { throw new AlbumUploadReceiptFailure('Prepared Album upload readback differs'); }
		return $saved;
	}

	function mark_published($descriptor, $picture)
	{
		$descriptor = self::descriptor($descriptor);
		if (!is_int($picture) || $picture < 1 || $picture > 4294967295) { throw new AlbumUploadReceiptFailure('Invalid published Album picture'); }
		$current = $this->committed($descriptor);
		if ($current === null || $current['state'] !== 'prepared') { throw new AlbumUploadReceiptFailure('No prepared Album publication'); }
		$files = $this->files($descriptor);
		foreach ($current['items'] as $item) {
			if ($files->snapshot($item['scope'], $item['name']) !== $item['metadata']) { throw new AlbumUploadReceiptFailure('Prepared Album file is missing or differs'); }
		}
		$names = array(); foreach ($current['items'] as $item) { $names[$item['scope']] = $item['name']; }
		$result = $this->storage->sql_query('SELECT pic_filename,pic_thumbnail,pic_user_id,pic_cat_id FROM ' . $this->prefix . 'album WHERE pic_id=' . $picture . ' FOR UPDATE');
		try { $photos = $this->storage->sql_fetchrowset($result); } finally { $this->storage->sql_freeresult($result); }
		if (count($photos) !== 1 || $photos[0]['pic_filename'] !== $names['original'] || (string) $photos[0]['pic_thumbnail'] !== (isset($names['small']) ? $names['small'] : '') ||
			self::number($photos[0]['pic_user_id'], -1, 8388607) !== $descriptor['actor'] || self::number($photos[0]['pic_cat_id'], 1, 16777215) !== $descriptor['category']) { throw new AlbumUploadReceiptFailure('Album picture differs from prepared upload'); }
		$where = $this->where($descriptor['operation']);
		$this->storage->sql_query('UPDATE ' . $this->prefix . "album_file_items SET item_state='published' WHERE " . $where . " AND item_state='pending'");
		if ((int) $this->storage->sql_affectedrows() !== count($current['items'])) { throw new AlbumUploadReceiptFailure('Album publication item count differs'); }
		$this->storage->sql_query('UPDATE ' . $this->prefix . "album_file_jobs SET operation_state='published',picture_id=" . $picture . ',completed_at=' . time() . ' WHERE ' . $where . " AND operation_state='prepared'");
		if ((int) $this->storage->sql_affectedrows() !== 1) { throw new AlbumUploadReceiptFailure('Album publication receipt missing'); }
		$sets = array();
		foreach (array('album_file_jobs','album_file_items') as $suffix) {
			$result = $this->storage->sql_query('SELECT * FROM ' . $this->prefix . $suffix . ' WHERE ' . $where . ($suffix === 'album_file_items' ? ' ORDER BY item_index' : ''));
			try { $sets[] = $this->storage->sql_fetchrowset($result); } finally { $this->storage->sql_freeresult($result); }
		}
		$saved = $this->parse($sets[0], $sets[1], $descriptor);
		if ($saved === null || $saved['picture'] !== $picture || $saved['items'] !== $current['items'] || $saved['state'] !== 'published') { throw new AlbumUploadReceiptFailure('Album publication readback differs'); }
	}

	function guard($descriptor)
	{
		$descriptor = self::descriptor($descriptor); $receipt = $this;
		return function ($context) use ($receipt, $descriptor) { return $receipt->publication_allowed($descriptor, $context); };
	}

	function files($descriptor)
	{
		// The optional application root is trusted construction context only;
		// neither a receipt nor a posted filename can choose a filesystem root.
		return new AlbumFileStorage($this->guard($descriptor), $this->root);
	}

	// An explicit authenticated retry may clear only its own deterministic
	// unfinished stage. Final files and unrelated staging names stay protected.
	function retry_files($descriptor)
	{
		$descriptor = self::descriptor($descriptor); $receipt = $this;
		return new AlbumFileStorage(function($context) use($receipt,$descriptor)
		{
			if (!is_array($context) || !isset($context['action']) || $context['action'] !== 'remove-stage') { return $receipt->publication_allowed($descriptor,$context); }
			if (count($context)!==5 || !array_key_exists('metadata',$context) || $context['metadata']!==null || !isset($context['operation'],$context['scope'],$context['name']) || $context['operation']!==$descriptor['operation']) { return false; }
			$current=$receipt->committed($descriptor); if($current===null||$current['state']!=='prepared'){return false;}
			foreach($current['items']as$item){if($context['scope']===$item['scope']&&$context['name']===$item['name']){$context['action']='publish';$context['metadata']=$item['metadata'];return$receipt->publication_allowed($descriptor,$context);}}
			return false;
		},$this->root);
	}

	function publication_allowed($descriptor, $context)
	{
		$descriptor = self::descriptor($descriptor);
		if (!is_array($context) || count($context) !== 5) { return false; }
		foreach (array('action','scope','name','operation','metadata') as $key) { if (!array_key_exists($key, $context)) { return false; } }
		$current = $this->committed($descriptor); if ($current === null) { return false; }
		if ($context['action'] === 'roots') { return $context['scope'] === null && $context['name'] === null && $context['operation'] === null && $context['metadata'] === null; }
		if ($context['action'] !== 'snapshot' && $context['action'] !== 'publish') { return false; }
		foreach ($current['items'] as $item)
		{
			if ($item['scope'] !== $context['scope'] || $item['name'] !== $context['name']) { continue; }
			if ($context['action'] === 'snapshot') { return $context['operation'] === null && $context['metadata'] === null; }
			if ($current['state'] !== 'prepared' || !$this->storage->can_publish() || $context['operation'] !== $descriptor['operation'] || !AlbumFileStorage::metadata($context['metadata']) ||
				$context['metadata']['present'] !== true || $context['metadata']['bytes'] !== $item['metadata']['bytes'] || $context['metadata']['sha256'] !== $item['metadata']['sha256']) { return false; }
			// Exact bytes matter on case-sensitive filesystems despite Unicode CI
			// database columns. Do not publish into any surviving reference.
			$name = strtoupper(bin2hex($item['name']));
			$where = "HEX(pic_filename)='" . $name . "' OR HEX(pic_thumbnail)='" . $name . "'";
			// Windows commonly folds filesystem case. Its more conservative SQL
			// comparison also preserves an absent case-alias referenced by a photo.
			if (DIRECTORY_SEPARATOR === chr(92)) { $literal = self::literal($item['name']); $where = 'pic_filename=' . $literal . ' OR pic_thumbnail=' . $literal; }
			$references = $this->storage->durable_rows('SELECT pic_id FROM ' . $this->prefix . 'album WHERE ' . $where . ' LIMIT 1');
			return count($references) === 0;
		}
		return false;
	}
}
