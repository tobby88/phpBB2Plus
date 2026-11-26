<?php
namespace phpbb2plus\arcade\service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class artwork
{
	private $db;
	private $config;
	private $prefix;
	private $root;
	private $filesystem;
	private $directories;
	private $settings;
	private $copies = array();

	public function __construct($db, $config, $prefix, $root, $filesystem)
	{
		$this->db = $db;
		$this->config = $config;
		$this->prefix = $prefix;
		$this->root = $root;
		$this->filesystem = $filesystem;
	}

	private function relative($path)
	{
		$path = rtrim(str_replace('\\', '/', trim((string) $path)), '/');
		while (substr($path, 0, 2) === './') { $path = substr($path, 2); }
		return $path !== '' && $path[0] !== '/' && !preg_match('~[:?#%\x00-\x1f\x7f]|(?:^|/)\.|//~', $path) ? $path : '';
	}

	private function settings()
	{
		if ($this->settings === null)
		{
			$this->settings = array('games_path' => 'games', 'games_default_img' => '');
			$result = $this->db->sql_query('SELECT config_name,config_value FROM ' . $this->prefix . 'settings WHERE ' .
				$this->db->sql_in_set('config_name', array_keys($this->settings)));
			while ($row = $this->db->sql_fetchrow($result)) { $this->settings[$row['config_name']] = $this->relative($row['config_value']); }
			$this->db->sql_freeresult($result);
		}
		return $this->settings;
	}

	private function candidates($game)
	{
		$settings = $this->settings();
		$name = $this->relative($game['game_name']);
		if ($name === '' || strpos($name, '/') !== false) { return array(); }
		$folder = $this->relative($game['game_path']);
		$image = trim((string) $game['image_path']);
		if ($image === '')
		{
			$paths = array($settings['games_path'] . '/' . $name . '.gif', $folder . '/' . $name . '.gif');
		}
		else
		{
			$paths = array();
			if (strlen($image) < 5) { $paths[] = $folder . '/' . $name . $image; }
			$paths[] = $image;
			$paths[] = $folder . '/' . $name . $image;
		}
		$paths[] = $settings['games_default_img'];
		return array_filter(array_unique(array_map(array($this, 'relative'), $paths)));
	}

	private function belongs_to_game($relative, $game_folder)
	{
		if (preg_match('~^(?:templates/[^/]+/images/|images/(?:games/|arcade/|[^/]+$))~i', $relative)) { return true; }
		$root = $this->settings()['games_path'];
		if ($root === '' || strpos($relative, $root . '/') !== 0) { return false; }
		if ($this->directories === null)
		{
			$this->directories = array();
			$result = $this->db->sql_query('SELECT DISTINCT game_path FROM ' . $this->prefix . 'games');
			while ($row = $this->db->sql_fetchrow($result)) { $this->directories[$this->relative($row['game_path'])] = true; }
			$this->db->sql_freeresult($result);
		}
		for ($parent = dirname($relative); $parent !== $root; $parent = dirname($parent))
		{
			if (isset($this->directories[$parent])) { return $parent === $game_folder; }
		}
		return true;
	}

	private function upload($name)
	{
		return rtrim($this->root, '/\\') . '/' . trim($this->config['upload_path'], '/\\') . '/' . $name;
	}

	private function assign($id, $storage, $mime, $bytes)
	{
		$values = array('icon_storage' => $storage, 'icon_mime' => $mime, 'icon_bytes' => $bytes);
		$this->db->sql_query('UPDATE ' . $this->prefix . 'games SET ' . $this->db->sql_build_array('UPDATE', $values) . ' WHERE game_id=' . (int) $id);
	}

	public function source_root($folder)
	{
		$source = realpath($folder);
		if (!$source || !is_dir($source) || $source === realpath($this->root)) { throw new \InvalidArgumentException('Specify the offline phpBB2 board directory.'); }
		return $source;
	}

	public function import_game($game, $source)
	{
		$types = array('gif' => 'image/gif', 'png' => 'image/png', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'webp' => 'image/webp');
		foreach ($this->candidates($game) as $relative)
		{
			$ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
			if (!isset($types[$ext]) || !$this->belongs_to_game($relative, $this->relative($game['game_path']))) { continue; }
			$file = $source . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
			// Each component must resolve to this exact location inside the offline source.
			if (realpath($file) !== $file || !is_file($file)) { continue; }
			if (!isset($this->copies[$file]))
			{
				$info = @getimagesize($file);
				if (!$info || $info['mime'] !== $types[$ext]) { continue; }
				$digest = hash_file('sha256', $file);
				if ($digest === false) { throw new \RuntimeException('Cannot read the game artwork.'); }
				$name = 'arcade_icon_' . $digest;
				$target = $this->upload($name);
				if (is_link($target)) { throw new \RuntimeException('Unexpected link in Arcade artwork storage.'); }
				if (!is_file($target))
				{
					$stream = fopen($file, 'rb');
					if (!$stream) { throw new \RuntimeException('Cannot open the game artwork.'); }
					try { $this->filesystem->dump_file($target, $stream); }
					finally { fclose($stream); }
				}
				if (hash_file('sha256', $target) !== $digest) { throw new \RuntimeException('The copied Arcade artwork did not match the source.'); }
				$this->copies[$file] = array($name, $info['mime'], filesize($target));
			}
			list($name, $mime, $bytes) = $this->copies[$file];
			if ($relative === $this->settings()['games_default_img'] && !empty($game['icon_storage']) && $game['icon_storage'] !== $name)
			{
				// An incomplete offline board may contain only the shared default.
				$current = $this->stored_file($game);
				if ($current && hash_file('sha256', $current) !== substr($name, strlen('arcade_icon_'))) { return false; }
			}
			$this->assign($game['game_id'], $name, $mime, $bytes);
			return true;
		}
		return false;
	}

	public function batch($after, $source = null)
	{
		$where = 'game_id>' . (int) $after . ($source === null ? " AND icon_storage=''" : '');
		$result = $this->db->sql_query_limit('SELECT game_id,game_name,game_path,image_path,icon_storage,icon_mime,icon_bytes FROM ' . $this->prefix . "games WHERE $where ORDER BY game_id", 100);
		$games = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		$found = 0;
		foreach ($games as $game)
		{
			$found += (int) ($source === null ? $this->recover_game($game) : $this->import_game($game, $source));
			$after = (int) $game['game_id'];
		}
		return array('after' => count($games) === 100 ? $after : 0, 'found' => $found, 'checked' => count($games));
	}

	// Previous conversions already retain validated bitmap assets from each game's own directory.
	private function recover_game($game)
	{
		$files = array();
		$folder = $this->relative($game['game_path']);
		$result = $this->db->sql_query('SELECT relative_name,storage_name,mime_type,bytes FROM ' . $this->prefix . 'assets WHERE game_id=' . (int) $game['game_id'] .
			' AND ' . $this->db->sql_in_set('mime_type', array('image/gif','image/png','image/jpeg','image/webp')));
		while ($row = $this->db->sql_fetchrow($result)) { $files[$folder . '/' . $row['relative_name']] = $row; }
		$this->db->sql_freeresult($result);
		foreach ($this->candidates($game) as $key)
		{
			if (!isset($files[$key])) { continue; }
			$row = $files[$key];
			if (!preg_match('/^arcade_[a-f0-9]{64}$/D', $row['storage_name'])) { continue; }
			$path = $this->upload($row['storage_name']);
			if (is_link($path) || !is_file($path) || (string) filesize($path) !== (string) $row['bytes']) { continue; }
			$info = @getimagesize($path);
			if (!$info || $info['mime'] !== $row['mime_type']) { continue; }
			$this->assign($game['game_id'], $row['storage_name'], $row['mime_type'], $row['bytes']);
			return true;
		}
		return false;
	}

	private function stored_file($game)
	{
		if (!preg_match('/^arcade_(?:icon_)?[a-f0-9]{64}$/D', $game['icon_storage'])
			|| !in_array($game['icon_mime'], array('image/gif','image/jpeg','image/png','image/webp'), true)) { return false; }
		$file = $this->upload($game['icon_storage']);
		return !is_link($file) && is_file($file) && (string) filesize($file) === (string) $game['icon_bytes'] ? $file : false;
	}

	public function file($game)
	{
		$file = $this->stored_file($game);
		if (!$file) { throw new NotFoundHttpException(); }
		return $file;
	}

	// The caller has already checked the public game or its moderation category.
	public function response($game)
	{
		$response = new BinaryFileResponse($this->file($game), 200, array('Content-Type' => $game['icon_mime'],
			'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"), false);
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}
}
