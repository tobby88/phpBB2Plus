<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Publish a multipart submission as one album operation, including quota accounting. */
class uploads
{
	const TYPES = array(IMAGETYPE_JPEG => array('jpg_allowed','image/jpeg','imagecreatefromjpeg'),
		IMAGETYPE_PNG => array('png_allowed','image/png','imagecreatefrompng'), IMAGETYPE_GIF => array('gif_allowed','image/gif','imagecreatefromgif'));
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

	public function resizing() { return (string) $this->access->setting('resize_pic', 0) === '1'; }

	public function zip_enabled() { return (string) $this->access->setting('zip_uploads', 1) === '1'; }

	public function allowed_types()
	{
		$types = array();
		foreach (self::TYPES as $type => $details)
		{
			list($name, $mime, $decoder) = $details;
			if ((int) $this->access->setting($name, $type === IMAGETYPE_GIF ? 0 : 1) && function_exists($decoder)) { $types[$type] = $mime; }
		}
		return $types;
	}

	public function picture_limit() { return max(1, min(100, (int) $this->access->setting('max_uploads', 10))); }

	public function archive_limit() { return min(upload_archive::MAX_IMAGES, $this->picture_limit()); }

	public function image_limits()
	{
		return array('width' => max(0, min(10000, (int) $this->access->setting('max_width', 800))),
			'height' => max(0, min(10000, (int) $this->access->setting('max_height', 600))),
			'bytes' => max(0, min(67108864, (int) $this->access->setting('max_file_size', 128000))));
	}

	private function image_policy()
	{
		$current = array();
		foreach (array('gd_version','thumbnail_size','max_width','max_height','max_file_size','resize_pic','resize_width','resize_height',
			'resize_quality','jpg_allowed','gif_allowed','png_allowed','desc_length','zip_uploads','max_uploads','multiple_uploads') as $name) { $current[$name] = $this->access->setting($name, null); }
		return $current;
	}

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

	private function inputs($files, $expected, $preview, &$packed, &$remaining_bytes)
	{
		$count_error = $preview ? 'PLUS_ALBUM_BAD_PREVIEW' : 'PLUS_ALBUM_BAD_COUNT';
		if (!is_array($files) || !isset($files['name'], $files['tmp_name'], $files['error']) || !is_array($files['name']) ||
			!is_array($files['tmp_name']) || !is_array($files['error']) || !count($files['error']) || count($files['error']) > $this->limit() ||
			($expected && count($files['error']) !== $expected))
		{ throw new \DomainException($count_error); }
		$items = array();
		foreach ($files['error'] as $key => $error)
		{
			$path = $files['tmp_name'][$key] ?? null;
			$name = $files['name'][$key] ?? null;
			if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path) || !is_string($name))
			{ throw new \DomainException($preview ? 'PLUS_ALBUM_BAD_PREVIEW' : 'PLUS_ALBUM_BAD_FILE'); }
			$name = upload_archive::filename($name);
			if (preg_match('/\.zip$/iD', $name))
			{
				if (!$this->zip_enabled()) { throw new \DomainException('PLUS_ALBUM_ZIP_DISABLED'); }
				$limit = min($this->archive_limit(), $this->picture_limit() - count($items));
				$bytes = max(1, (int) $this->access->setting('max_file_size', 128000)) * $this->archive_limit();
				$items = array_merge($items, upload_archive::images($path, $limit, $bytes, $remaining_bytes));
				$packed = true;
			}
			else
			{
				if (count($items) >= $this->picture_limit()) { throw new \DomainException($count_error); }
				$items[] = array('name' => $name, 'path' => $path);
			}
		}
		return $items;
	}

	private function inspect($file, $preview = false)
	{
		// inputs() supplies either a verified multipart upload or a private archive-member stream.
		$path = $file['path'];
		$bytes = filesize($path);
		if (!$bytes || $bytes > 67108864) { throw new \DomainException('PLUS_ALBUM_BAD_SIZE'); }
		$info = @getimagesize($path);
		$types = $this->allowed_types();
		if (!$info || !isset($types[$info[2]])) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		$mime = $types[$info[2]];
		$decoder = self::TYPES[$info[2]][2];
		$limits = $this->image_limits();
		$width = $preview ? $this->preview_bound() : $limits['width'];
		$height = $preview ? $width : $limits['height'];
		$max_bytes = $limits['bytes'];
		$oversize = $info[0] > $width || $info[1] > $height;
		$resize = !$preview && $oversize && $this->resizing();
		if ($width < 1 || $height < 1 || min($info[0], $info[1]) < 1 || max($info[0], $info[1]) > 10000 ||
			$info[0] * $info[1] > 20000000 || (!$resize && ($oversize || $bytes > $max_bytes)))
		{
			throw new \DomainException('PLUS_ALBUM_BAD_SIZE');
		}
		$needed = 12.0 * $info[0] * $info[1] + 4194304;
		if (ini_get('memory_limit') !== '-1' && $needed > $this->ini->getBytes('memory_limit') - memory_get_usage(true)) { throw new \DomainException('PLUS_ALBUM_BAD_SIZE'); }
		$decoded = @$decoder($path);
		if (!$decoded) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		$name = $file['name'];
		if ($name === '' || !preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f]/', $name) || utf8_strlen($name) > 255) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		$hash = hash_file('sha256', $path);
		if ($hash === false) { throw new \DomainException('PLUS_ALBUM_BAD_FILE'); }
		$image = array('path' => $path, 'hash' => $hash, 'source_filename' => $name, 'width' => $info[0], 'height' => $info[1],
			'media_type' => $mime, 'bytes' => $bytes);
		if (!$resize)
		{
			if (isset($file['stream'])) { $image['stream'] = $file['stream']; }
			return $image;
		}
		if ($mime === 'image/jpeg') { $decoded = raster::orient($decoded, raster::orientation($path)); }
		$width = min($width, max(1, (int) $this->access->setting('resize_width', 600)));
		$height = min($height, max(1, (int) $this->access->setting('resize_height', 600)));
		$scaled = $decoded ? raster::fit($decoded, $width, $height, $mime) : false;
		if (!$scaled) { throw new \DomainException('PLUS_ALBUM_RESIZE_FAILED'); }
		// The resource owns this private temporary file until publication or request termination.
		// GD opens its path separately, leaving both the submitted original and the owner handle intact.
		$temp = tmpfile();
		if (!$temp) { throw new \DomainException('PLUS_ALBUM_RESIZE_FAILED'); }
		$output = stream_get_meta_data($temp)['uri'];
		$quality = max(0, min(100, (int) $this->access->setting('resize_quality', 70)));
		if ($mime === 'image/gif' && !function_exists('imagegif') && (int) $this->access->setting('jpg_allowed', 1)) { $mime = 'image/jpeg'; }
		if (!raster::encode($scaled, $output, $mime, $quality)) { throw new \DomainException('PLUS_ALBUM_RESIZE_FAILED'); }
		clearstatcache(true, $output);
		$size = @getimagesize($output);
		$bytes = filesize($output);
		$digest = hash_file('sha256', $output);
		if (!$size || $size['mime'] !== $mime || $size[0] !== imagesx($scaled) || $size[1] !== imagesy($scaled) || !$bytes || !$digest)
		{ throw new \DomainException('PLUS_ALBUM_RESIZE_FAILED'); }
		if ($bytes > $max_bytes) { throw new \DomainException('PLUS_ALBUM_BAD_SIZE'); }
		return array('path' => $output, 'stream' => $temp, 'source_hash' => $hash, 'hash' => $digest, 'source_filename' => $name,
			'media_type' => $mime, 'width' => $size[0], 'height' => $size[1], 'bytes' => $bytes);
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
		// The durable gallery receipt also identifies the staging file if PHP exits
		// before it can run finally. Only the gallery writer or its collector owns it.
		$temp = $target . '.part';
		if (is_link($temp) || (file_exists($temp) && (!is_file($temp) || !unlink($temp))))
		{ throw new \RuntimeException('Invalid gallery staging destination.'); }
		$output = fopen($temp, 'xb');
		if (!$output) { throw new \RuntimeException('Could not stage an album file.'); }
		$input = null;
		try
		{
			$input = fopen($image['path'], 'rb');
			if (!$input || stream_copy_to_stream($input, $output) !== $image['bytes'] || !fflush($output))
			{ throw new \RuntimeException('Could not copy an album file.'); }
			fclose($output);
			if (hash_file('sha256', $temp) !== $image['hash'] || !rename($temp, $target))
			{ throw new \RuntimeException('Could not store a verified album original.'); }
		}
		finally
		{
			if (is_resource($input)) { fclose($input); }
			if (is_resource($output)) { fclose($output); }
			if (is_file($temp) && !is_link($temp)) { unlink($temp); }
		}
	}

	public function publish($id, $nonce, $files, $expected, $title, $description, $guest_name, $previews = array())
	{
		$this->destination($id);
		if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { throw new \DomainException('FORM_INVALID'); }
		if (utf8_strlen($title) > 255 || utf8_strlen($description) > $this->text_limit()) { throw new \DomainException('PLUS_ALBUM_BAD_TEXT'); }
		$registered = !empty($this->user->data['is_registered']);
		if (!$registered && $guest_name !== '')
		{
			if (!function_exists('validate_username')) { include_once $this->root . 'includes/functions_user.php'; }
			if (utf8_strlen($guest_name) > 32 || validate_username(utf8_htmlspecialchars($guest_name)) !== false) { throw new \DomainException('PLUS_ALBUM_BAD_NAME'); }
		}
		$manual = $this->manual();
		$policy = $this->image_policy();
		if (!$manual && $previews) { throw new \DomainException('PLUS_ALBUM_BAD_PREVIEW'); }
		$remaining_bytes = upload_archive::MAX_BYTES;
		$packed = false;
		$files = $this->inputs($files, $expected, false, $packed, $remaining_bytes);
		if ($manual)
		{
			$previews = $this->inputs($previews, 0, true, $packed, $remaining_bytes);
			if (count($files) !== count($previews)) { throw new \DomainException('PLUS_ALBUM_BAD_PREVIEW'); }
		}
		$number_titles = $packed && count($files) > 1 && $title !== '';
		if ($number_titles && utf8_strlen($title) > 249) { throw new \DomainException('PLUS_ALBUM_ZIP_TITLE'); }
		$images = $small = $identity = array();
		foreach ($files as $key => $file)
		{
			$image = $this->inspect($file);
			$images[] = $image;
			$entry = array($image['source_filename'], $image['source_hash'] ?? $image['hash']);
			if ($manual)
			{
				$thumb = $this->inspect($previews[$key], true);
				if ($thumb['media_type'] !== $image['media_type']) { throw new \DomainException('PLUS_ALBUM_BAD_PREVIEW'); }
				$small[] = $thumb;
				$entry[] = $thumb['hash'];
			}
			$identity[] = $entry;
		}
		if ($number_titles) { $identity['numbered'] = true; }
		$actor = $registered ? (string) $this->user->data['user_id'] : 'guest-' . $this->user->session_id;
		$batch = hash_hmac('sha256', $actor . ':' . (int) $id . ':' . $nonce, $this->config['plus_gallery_media_key']);
		$payload = hash('sha256', serialize(array($title, $description, $registered ? '' : $guest_name, $identity)));
		$dir = realpath($this->root . $this->config['upload_path']);
		if (!$dir || !is_dir($dir)) { throw new \RuntimeException('Album storage unavailable.'); }
		$names = array();
		foreach ($images as $i => $image)
		{
			$output = isset($image['source_hash']) ? ':' . $image['hash'] : '';
			$names[] = 'gallery_' . hash_hmac('sha256', 'upload:' . $batch . ':' . $payload . ':' . $i . $output, $this->config['plus_gallery_media_key']);
		}
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
		return $this->access->write(function () use ($id, $batch, $payload, $dir, $images, $small, $manual, $policy, $names, $number_titles, $title, $description, $registered, $guest_name)
		{
			$cat = $this->destination($id);
			$result = $this->db->sql_query('SELECT payload_hash FROM ' . $this->prefix . "batches WHERE batch_key='$batch'");
			$saved = $this->db->sql_fetchfield('payload_hash');
			$this->db->sql_freeresult($result);
			if ($saved !== false && !hash_equals($saved, $payload)) { throw new \DomainException('FORM_INVALID'); }
			if ($saved === false)
			{
				if ($policy !== $this->image_policy()) { throw new \DomainException('FORM_INVALID'); }
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
					unset($image['hash'], $image['path'], $image['stream'], $image['source_hash']);
					$default_title = trim(pathinfo($image['source_filename'], PATHINFO_FILENAME));
					$label = $title === '' ? ($default_title === '' ? $image['source_filename'] : $default_title) : $title;
					if ($number_titles) { $label .= sprintf(' - %03d', $i + 1); }
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
