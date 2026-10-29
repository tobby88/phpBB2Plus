<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_upload_receipt.php';

// Privileged maintenance, never a public upload permission. Revocation must
// commit in one lease before a later lease may remove any abandoned bytes.
class AlbumUploadRecovery
{
	private $storage;
	private $prefix;
	private $root;
	private $receipt;

	function __construct($storage, $prefix, $root = null)
	{
		$this->receipt = new AlbumUploadReceipt($storage, $prefix, $root);
		$this->storage = $storage; $this->prefix = $prefix; $this->root = $root;
	}
	private static function literal($value) { return 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci'; }
	private function where($descriptor)
	{
		// The receipt parser has already validated every descriptor byte.
		return "operation_id='" . $descriptor['operation'] . "' AND HEX(operation_id)='" . strtoupper(bin2hex($descriptor['operation'])) . "'";
	}
	private function rows($sql)
	{
		$result = $this->storage->sql_query($sql);
		try { $rows = $this->storage->sql_fetchrowset($result); } finally { $this->storage->sql_freeresult($result); }
		if (!is_array($rows)) { throw new AlbumUploadReceiptFailure('Album recovery rows unavailable'); } return $rows;
	}
	private function manifests($descriptor)
	{
		$committed = $this->receipt->recovery_manifest($descriptor);
		$current = $this->receipt->recovery_manifest($descriptor, false);
		if ($committed === null || $current !== $committed) { throw new AlbumUploadReceiptFailure('Album recovery requires exact committed manifest'); }
		return $current;
	}

	function mark_abandoning($descriptor, $created_before)
	{
		if (!is_int($created_before) || $created_before < 1 || $created_before > time() - 86400) { throw new AlbumUploadReceiptFailure('Only uploads older than one day may be revoked'); }
		$job = $this->manifests($descriptor);
		if ($job['state'] !== 'prepared' || $job['created'] > $created_before) { return $job['state']; }
		$this->storage->sql_query('UPDATE ' . $this->prefix . "album_file_jobs SET operation_state='abandoning' WHERE " . $this->where($descriptor) . " AND operation_kind='upload' AND operation_state='prepared'");
		if ((int) $this->storage->sql_affectedrows() !== 1) { throw new AlbumUploadReceiptFailure('Album upload revocation changed'); }
		$job['state'] = 'abandoning';
		if ($this->receipt->recovery_manifest($descriptor, false) !== $job) { throw new AlbumUploadReceiptFailure('Album revocation readback differs'); }
		return 'abandoning';
	}

	private function referenced($scope, $name)
	{
		$where = "HEX(pic_filename)='" . strtoupper(bin2hex($name)) . "'";
		if ($scope !== 'original') { $where .= " OR HEX(pic_thumbnail)='" . strtoupper(bin2hex($name)) . "'"; }
		if (DIRECTORY_SEPARATOR === chr(92)) {
			$where = 'pic_filename=' . self::literal($name);
			if ($scope !== 'original') { $where .= ' OR pic_thumbnail=' . self::literal($name); }
		}
		return count($this->rows('SELECT pic_id FROM ' . $this->prefix . 'album WHERE ' . $where . ' LIMIT 1')) > 0;
	}
	private function capability($descriptor)
	{
		return function($context) use ($descriptor) {
			if (!$this->storage->publication_ready() || !is_array($context) || count($context) !== 5 || !isset($context['action'])) { return false; }
			foreach (array('scope','name','operation','metadata') as $key) { if (!array_key_exists($key,$context)) { return false; } }
			$job = $this->receipt->recovery_manifest($descriptor);
			if ($job === null || $job['state'] !== 'abandoning') { return false; }
			if ($context['action'] === 'roots') { return $context['scope']===null && $context['name']===null && $context['operation']===null && $context['metadata']===null; }
			foreach ($job['items'] as $index => $item) {
				if ($job['states'][$index] !== 'pending' || $context['scope'] !== $item['scope'] || $context['name'] !== $item['name']) { continue; }
				if ($context['action'] === 'snapshot' && $context['operation'] === null && $context['metadata'] === null) { return true; }
				if ($context['action'] === 'remove' && $context['operation'] === null && $context['metadata'] === $item['metadata'] && !$this->referenced($item['scope'], $item['name'])) { return true; }
				if ($context['action'] === 'remove-stage' && $context['operation'] === $descriptor['operation'] && $context['metadata'] === null) {
					$files = new AlbumFileStorage(function($probe) { return $probe['action']==='roots' && $this->storage->publication_ready(); }, $this->root);
					return !$this->referenced($item['scope'], $files->stage_name($descriptor['operation'], $item['scope'], $item['name']));
				}
			}
			return false;
		};
	}

	function resolve_batch($descriptor, $maximum_items)
	{
		if (!is_int($maximum_items) || $maximum_items < 1 || $maximum_items > 3) { throw new AlbumUploadReceiptFailure('Invalid abandoned upload item budget'); }
		$job = $this->manifests($descriptor);
		if ($job['state'] === 'abandoned') { return array('states'=>$job['states'],'attempted'=>0,'complete'=>true); }
		if ($job['state'] !== 'abandoning') { throw new AlbumUploadReceiptFailure('Committed revocation required before abandoned file cleanup'); }
		$files = new AlbumFileStorage($this->capability($descriptor), $this->root); $attempted = 0;
		foreach ($job['items'] as $index => $item) {
			if ($job['states'][$index] !== 'pending') { continue; }
			if ($attempted >= $maximum_items) { break; } $attempted++;
			$state = $this->referenced($item['scope'], $item['name']) ? 'shared' : $files->remove_exact($item['scope'], $item['name'], $item['metadata']);
			if ($state === 'busy') { continue; }
			$stage = $files->stage_name($descriptor['operation'], $item['scope'], $item['name']);
			if ($this->referenced($item['scope'], $stage)) { $state = 'shared'; }
			elseif ($files->remove_stage($descriptor['operation'], $item['scope'], $item['name']) === 'busy') { continue; }
			if (!in_array($state, array('removed','missing','changed','shared'), true)) { throw new AlbumUploadReceiptFailure('Unknown abandoned upload cleanup result'); }
			$this->storage->sql_query('UPDATE ' . $this->prefix . 'album_file_items SET item_state=' . self::literal($state) . ' WHERE ' . $this->where($descriptor) . ' AND item_index=' . $index . " AND item_state='pending'");
			if ((int) $this->storage->sql_affectedrows() !== 1) { throw new AlbumUploadReceiptFailure('Abandoned upload item changed'); } $job['states'][$index] = $state;
		}
		if ($this->receipt->recovery_manifest($descriptor, false) !== $job) { throw new AlbumUploadReceiptFailure('Abandoned upload readback differs'); }
		$complete = !in_array('pending', $job['states'], true);
		if ($complete) {
			$completed = time();
			$this->storage->sql_query('UPDATE ' . $this->prefix . "album_file_jobs SET operation_state='abandoned',completed_at=" . $completed . ' WHERE ' . $this->where($descriptor) . " AND operation_state='abandoning'");
			if ((int) $this->storage->sql_affectedrows() !== 1) { throw new AlbumUploadReceiptFailure('Abandoned upload completion changed'); }
			$job['state'] = 'abandoned'; $job['completed'] = $completed;
			if ($this->receipt->recovery_manifest($descriptor, false) !== $job) { throw new AlbumUploadReceiptFailure('Abandoned upload completion readback differs'); }
		}
		return array('states'=>$job['states'],'attempted'=>$attempted,'complete'=>$complete);
	}
}
