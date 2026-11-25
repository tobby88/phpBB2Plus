<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Publish a multipart submission as one album operation, including quota accounting. */
class uploads
{
	private $access;
	private $db;
	private $user;
	private $config;
	private $ini;
	private $root;
	private $prefix;

	public function __construct($access, $db, $user, $config, $ini, $root, $prefix)
	{
		$this->access = $access;
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
		$this->ini = $ini;
		$this->root = $root;
		$this->prefix = $prefix;
	}

	public function limit()
	{
		$maximum = (int) $this->access->setting('multiple_uploads', 1) ? (int) $this->access->setting('max_uploads', 10) : 1;
		return min(max(1, min(100, $maximum)), intdiv((int) ini_get('max_file_uploads'), $this->manual() ? 2 : 1));
	}

	public function manual() { return (string) $this->access->setting('gd_version', 2) === '0'; }

	public function preview_bound() { return max(1, min(10000, (int) $this->access->setting('thumbnail_size', 125))); }

	public function destination($id)
	{
		$cat = $this->access->category($id);
		if (!empty($this->user->data['is_bot']) || !$this->access->allows($cat, 'upload')) { throw new NotFoundHttpException(); }
		return $cat;
	}

	public function text_limit()
	{
		return max(0, min(65535, (int) $this->access->setting('desc_length', 512)));
	}

	private function inspect($path, $client_name, $preview = false)
	{
		$types = array(IMAGETYPE_JPEG => array('jpg_allowed','image/jpeg','imagecreatefromjpeg'),
			IMAGETYPE_PNG => array('png_allowed','image/png','imagecreatefrompng'), IMAGETYPE_GIF => array('gif_allowed','image/gif','imagecreatefromgif'));
		if (!is_string($path) || !is_uploaded_file($path)) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		$bytes = filesize($path);
		if (!$bytes || $bytes > (int) $this->access->setting('max_file_size', 128000)) { throw new \DomainException('PLUS_ALBUM_BAD_SIZE'); }
		$info = @getimagesize($path);
		if (!$info || !isset($types[$info[2]])) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		list($setting, $mime, $decoder) = $types[$info[2]];
		if (!(int) $this->access->setting($setting, $setting === 'gif_allowed' ? 0 : 1) || !function_exists($decoder)) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		$width = $preview ? $this->preview_bound() : min(10000, (int) $this->access->setting('max_width', 800));
		$height = $preview ? $width : min(10000, (int) $this->access->setting('max_height', 600));
		if ($info[0] < 1 || $info[1] < 1 || $info[0] > $width || $info[1] > $height || $info[0] * $info[1] > 20000000)
		{
			throw new \DomainException('PLUS_ALBUM_BAD_SIZE');
		}
		$needed = 12.0 * $info[0] * $info[1] + 4194304;
		if (ini_get('memory_limit') !== '-1' && $needed > $this->ini->getBytes('memory_limit') - memory_get_usage(true)) { throw new \DomainException('PLUS_ALBUM_BAD_SIZE'); }
		$decoded = @$decoder($path);
		if (!$decoded) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		unset($decoded);
		$name = is_string($client_name) ? basename(str_replace('\\', '/', $client_name)) : '';
		if ($name === '' || !preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f]/', $name) || utf8_strlen($name) > 255) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		$hash = hash_file('sha256', $path);
		if ($hash === false) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		return array('path' => $path, 'hash' => $hash, 'source_filename' => $name, 'width' => $info[0], 'height' => $info[1],
			'media_type' => $mime, 'bytes' => $bytes);
	}

	private function count($from, $where)
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $from . ' WHERE ' . $where);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		return $total;
	}

	private function check_quota($cat, $amount)
	{
		$pics = $this->prefix . 'pics';
		$where = 'cat_id=' . (int) $cat['cat_id'];
		if ($cat['owner_id'])
		{
			$limit = (int) $this->access->setting('personal_gallery_limit', 10);
			if ($limit >= 0 && $this->count($pics . ' p JOIN ' . $this->prefix . 'cats c ON c.cat_id=p.cat_id',
				'c.source_owner=' . (int) $cat['source_owner'] . ' AND c.owner_id=' . (int) $cat['owner_id']) + $amount > $limit)
			{ throw new \DomainException('PLUS_ALBUM_QUOTA'); }
			return;
		}
		$limit = (int) $this->access->setting('max_pics', 1024);
		if ($limit >= 0 && $this->count($pics, $where) + $amount > $limit) { throw new \DomainException('PLUS_ALBUM_QUOTA'); }
		if (!empty($this->user->data['is_registered']) && !$this->access->administrator())
		{
			$limit = $this->access->moderates($cat) ? (int) $this->access->setting('mod_pics_limit', 250) : (int) $this->access->setting('user_pics_limit', 50);
			if ($limit >= 0 && $this->count($pics, $where . ' AND author_id=' . (int) $this->user->data['user_id']) + $amount > $limit) { throw new \DomainException('PLUS_ALBUM_QUOTA'); }
		}
	}

	private function publish_file($dir, $name, $image)
	{
		$target = $dir . DIRECTORY_SEPARATOR . $name;
		if (is_link($target)) { throw new \RuntimeException('Invalid album storage destination.'); }
		if (is_file($target))
		{
			if (hash_file('sha256', $target) !== $image['hash']) { throw new \RuntimeException('An existing album original differs from its receipt.'); }
			return;
		}
		$temp = tempnam($dir, 'album-');
		try
		{
			if (!$temp || dirname($temp) !== $dir || !copy($image['path'], $temp) || hash_file('sha256', $temp) !== $image['hash'] || !rename($temp, $target))
			{ throw new \RuntimeException('Could not store a verified album original.'); }
		}
		finally
		{
			if ($temp && is_file($temp)) { unlink($temp); }
		}
	}

	public function publish($id, $nonce, $files, $expected, $title, $description, $guest_name, $previews = array())
	{
		$this->destination($id);
		if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { throw new \DomainException('FORM_INVALID'); }
		if (!is_array($files) || !isset($files['name'], $files['tmp_name'], $files['error']) || !is_array($files['error']) ||
			!count($files['error']) || count($files['error']) > $this->limit() || ($expected && count($files['error']) !== $expected))
		{ throw new \DomainException('PLUS_ALBUM_BAD_COUNT'); }
		if (utf8_strlen($title) > 255 || utf8_strlen($description) > $this->text_limit()) { throw new \DomainException('PLUS_ALBUM_BAD_TEXT'); }
		$registered = !empty($this->user->data['is_registered']);
		if (!$registered && $guest_name !== '')
		{
			if (!function_exists('validate_username')) { include_once $this->root . 'includes/functions_user.php'; }
			if (utf8_strlen($guest_name) > 32 || validate_username(utf8_htmlspecialchars($guest_name)) !== false) { throw new \DomainException('PLUS_ALBUM_BAD_NAME'); }
		}
		$manual = $this->manual();
		if (($manual && (!isset($previews['error']) || !is_array($previews['error']) || array_keys($files['error']) !== array_keys($previews['error']))) ||
			(!$manual && $previews)) { throw new \DomainException('PLUS_ALBUM_BAD_PREVIEW'); }
		$images = $small = $identity = array();
		foreach ($files['error'] as $key => $error)
		{
			if ($error !== UPLOAD_ERR_OK || !isset($files['tmp_name'][$key], $files['name'][$key])) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
			$image = $this->inspect($files['tmp_name'][$key], $files['name'][$key]);
			$images[] = $image;
			$entry = array($image['source_filename'], $image['hash']);
			if ($manual)
			{
				if ($previews['error'][$key] !== UPLOAD_ERR_OK || !isset($previews['tmp_name'][$key], $previews['name'][$key])) { throw new \DomainException('PLUS_ALBUM_BAD_PREVIEW'); }
				$thumb = $this->inspect($previews['tmp_name'][$key], $previews['name'][$key], true);
				if ($thumb['media_type'] !== $image['media_type']) { throw new \DomainException('PLUS_ALBUM_BAD_PREVIEW'); }
				$small[] = $thumb;
				$entry[] = $thumb['hash'];
			}
			$identity[] = $entry;
		}
		$actor = $registered ? (string) $this->user->data['user_id'] : 'guest-' . $this->user->session_id;
		$batch = hash_hmac('sha256', $actor . ':' . (int) $id . ':' . $nonce, $this->config['plus_gallery_media_key']);
		$payload = hash('sha256', serialize(array($title, $description, $registered ? '' : $guest_name, $identity)));
		$dir = realpath($this->root . $this->config['upload_path']);
		if (!$dir || !is_dir($dir)) { throw new \RuntimeException('Album storage unavailable.'); }
		$names = array();
		foreach (array_keys($images) as $i) { $names[] = 'gallery_' . hash_hmac('sha256', 'upload:' . $batch . ':' . $payload . ':' . $i, $this->config['plus_gallery_media_key']); }
		// Record the intended paths before any file is published. A failed DB commit
		// or interrupted worker leaves a durable receipt for eventual cleanup.
		$this->access->write(function () use ($id, $batch, $payload, $names) {
			$cat = $this->destination($id);
			$result = $this->db->sql_query('SELECT payload_hash FROM ' . $this->prefix . "batches WHERE batch_key='$batch'");
			$saved = $this->db->sql_fetchfield('payload_hash');
			$this->db->sql_freeresult($result);
			if ($saved !== false)
			{
				if (!hash_equals($saved, $payload)) { throw new \DomainException('FORM_INVALID'); }
				return;
			}
			$this->check_quota($cat, count($names));
			$table = $this->prefix . 'trash';
			$this->db->sql_query("DELETE FROM $table WHERE " . $this->db->sql_in_set('storage_name', $names));
			$rows = array();
			foreach ($names as $name) { $rows[] = array('storage_name' => $name, 'removed_at' => time()); }
			$this->db->sql_multi_insert($table, $rows);
		});
		return $this->access->write(function () use ($id, $batch, $payload, $dir, $images, $small, $manual, $names, $title, $description, $registered, $guest_name)
		{
			$cat = $this->destination($id);
			$result = $this->db->sql_query('SELECT payload_hash FROM ' . $this->prefix . "batches WHERE batch_key='$batch'");
			$saved = $this->db->sql_fetchfield('payload_hash');
			$this->db->sql_freeresult($result);
			if ($saved !== false && !hash_equals($saved, $payload)) { throw new \DomainException('FORM_INVALID'); }
			if ($saved === false)
			{
				if ($manual !== $this->manual() || count($names) > $this->limit()) { throw new \DomainException('FORM_INVALID'); }
				$reserved = $this->db->sql_in_set('storage_name', $names);
				if ($this->count($this->prefix . 'trash', $reserved) !== count($names)) { throw new \DomainException('FORM_INVALID'); }
				$this->check_quota($cat, count($images));
				foreach ($images as $i => $image)
				{
					$name = $names[$i];
					if ($manual)
					{
						if (max($small[$i]['width'], $small[$i]['height']) > $this->preview_bound()) { throw new \DomainException('PLUS_ALBUM_BAD_PREVIEW'); }
						$this->publish_file($dir, 'gallery_small_' . substr($name, 8), $small[$i]);
						$image['preview_hash'] = $small[$i]['hash'];
					}
					$this->publish_file($dir, $name, $image);
					unset($image['hash'], $image['path']);
					$default_title = trim(pathinfo($image['source_filename'], PATHINFO_FILENAME));
					$label = $title === '' ? ($default_title === '' ? $image['source_filename'] : $default_title) : $title;
					$row = $image + array('cat_id' => (int) $id, 'storage_name' => $name, 'title' => $label, 'description' => $description,
						'author_id' => (int) $this->user->data['user_id'], 'author_name' => $registered ? '' : $guest_name,
						'author_ip' => $this->user->ip, 'created_at' => time(), 'approved' => (int) $cat['approval'] === 0 ? 1 : 0);
					$this->db->sql_query('INSERT INTO ' . $this->prefix . 'pics ' . $this->db->sql_build_array('INSERT', $row));
				}
				$this->db->sql_query('INSERT INTO ' . $this->prefix . 'batches ' . $this->db->sql_build_array('INSERT',
					array('batch_key' => $batch, 'payload_hash' => $payload, 'created_at' => time())));
				$this->db->sql_query('DELETE FROM ' . $this->prefix . "trash WHERE $reserved");
			}
			return (int) $cat['approval'] !== 0;
		});
	}
}
