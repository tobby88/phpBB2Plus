<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once __DIR__ . '/album_file_storage.php';

class AlbumUploadFailure extends RuntimeException {}

// Own read handles and disposable preprocessing streams, never final images.
// The trusted application root/configured staging path cannot come from POST.
class AlbumUploadStage
{
	private $root;
	private $directory;
	private $owner_file;
	private $owner_token;
	private $psid;
	private $operation;
	private $handles = array();
	private $derived = array();
	private $original;
	private $small = null;
	private $provided = null;
	private $policy;
	private $open = false;

	function __construct($snapshot, $configured_path, $psid, $original, $thumbnail, $config, $root = null)
	{
		try
		{
			if (!is_string($psid) || !preg_match('/^[a-f0-9]{32}$/iD', $psid) || !is_string($configured_path) || self::session_token($snapshot) === false || !is_array($config)) { throw new AlbumUploadFailure('Not_Authorised'); }
			$this->root = $root === null ? dirname(__DIR__) : $root;
			if (!is_string($this->root) || is_link($this->root) || ($this->root = realpath($this->root)) === false || dirname($this->root) === $this->root) { throw new AlbumUploadFailure('Not_Authorised'); }
			$relative = album_nuffload_base_path($configured_path);
			if ($relative === false) { throw new AlbumUploadFailure('Not_Authorised'); }
			$path = $this->root;
			foreach (explode('/', trim($relative . 'tmp', '/')) as $component)
			{
				$path .= DIRECTORY_SEPARATOR . $component; clearstatcache(true, $path);
				if (is_link($path) || !is_dir($path) || realpath($path) !== $path || !is_readable($path)) { throw new AlbumUploadFailure('Not_Authorised'); }
			}
			$this->directory = $path; $this->psid = $psid; $this->owner_file = $path . DIRECTORY_SEPARATOR . $psid . '_owner';
			$this->owner_token = self::session_token($snapshot);
			if ($this->owner_token === false || !$this->verify_owner($snapshot)) { throw new AlbumUploadFailure('Not_Authorised'); }
			$this->policy = self::policy($config);
			$source = $this->open_source($original, $psid); $read = self::inspect($source);
			$this->original = $read; unset($this->original['data']);
			$this->operation = substr(hash('sha256', "album-upload-v1\0" . strtolower($psid) . "\0" . $this->owner_token . "\0" . strtolower($read['basename'])), 0, 32);
			if ($this->policy['gd_version'] === 0)
			{
				$provided = self::inspect($this->open_source($thumbnail, $psid));
				if ($provided['extension'] !== $read['extension']) { throw new AlbumUploadFailure('Filetype_and_thumbtype_do_not_match'); }
				unset($provided['data']); $this->small = $provided; $this->provided = $provided['metadata'];
			}
			elseif ($this->policy['thumbnail_cache'] === 1 && $read['extension'] !== '.gif') { $this->small = $this->thumbnail($read); }
			$this->open = true; register_shutdown_function(array($this, 'release'));
		}
		catch (Exception $error) { $this->release(); throw $error; }
		catch (Error $error) { $this->release(); throw $error; }
	}

	static function policy($config)
	{
		$result = array();
		foreach (array('gd_version'=>array(0,2),'thumbnail_cache'=>array(0,1),'thumbnail_size'=>array(1,10000),'thumbnail_quality'=>array(0,100)) as $field => $bounds)
		{
			if (!isset($config[$field]) || (!is_string($config[$field]) && !is_int($config[$field])) || !preg_match('/^[0-9]{1,5}$/D', (string) $config[$field]) || (int) $config[$field] < $bounds[0] || (int) $config[$field] > $bounds[1]) { throw new AlbumUploadFailure('Upload_resize_failed'); }
			$result[$field] = (int) $config[$field];
		}
		return $result;
	}

	function owner_matches($snapshot)
	{
		return $this->open && $this->verify_owner($snapshot);
	}

	private static function session_token($snapshot)
	{
		if (!is_array($snapshot) || !isset($snapshot['user_id'],$snapshot['session_id']) || (!is_int($snapshot['user_id']) && !is_string($snapshot['user_id'])) || !preg_match('/^(?:-1|[1-9][0-9]{0,6})$/D',(string)$snapshot['user_id']) || (int)$snapshot['user_id'] > 8388607 || !is_string($snapshot['session_id']) || !preg_match('/^[a-f0-9]{32}$/iD',$snapshot['session_id'])) { return false; }
		return album_nuffload_owner_token($snapshot);
	}

	private function verify_owner($snapshot)
	{
		if (!$this->owner_file || $this->owner_token === false || self::session_token($snapshot) !== $this->owner_token) { return false; }
		clearstatcache(true, $this->owner_file); $size = @filesize($this->owner_file);
		if (is_link($this->owner_file) || !is_file($this->owner_file) || $size === false || $size < 64 || $size > 128 || dirname(realpath($this->owner_file)) !== $this->directory) { return false; }
		$value = @file_get_contents($this->owner_file); return is_string($value) && hash_equals($this->owner_token, trim($value));
	}

	private function open_source($source, $psid)
	{
		if (!is_string($source) || $source === '' || strpos($source, "\0") !== false || strpos($source, '://') !== false) { throw new AlbumUploadFailure('Not_Authorised'); }
		$absolute = substr($source, 0, 1) === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $source);
		$path = $absolute ? $source : $this->root . DIRECTORY_SEPARATOR . $source;
		clearstatcache(true, $path); $real = realpath($path);
		if ($real === false || is_link($path) || dirname($real) !== $this->directory || !preg_match('/^' . preg_quote($psid, '/') . '_actualdata[0-9]+$/iD', basename($real))) { throw new AlbumUploadFailure('Not_Authorised'); }
		$handle = @fopen($real, 'rb'); if (!$handle) { throw new AlbumUploadFailure('Not_Authorised'); }
		$this->handles[] = $handle; return array('stream' => $handle, 'basename' => basename($real));
	}

	private static function budget($extra)
	{
		$value = trim(ini_get('memory_limit')); if ($value === '-1') { return true; }
		if (!preg_match('/^([0-9]+)\s*([KMG]?)$/iD', $value, $match)) { return false; }
		$power = array(''=>0,'K'=>1,'M'=>2,'G'=>3); $limit = (float) $match[1] * pow(1024, $power[strtoupper($match[2])]);
		return memory_get_usage(true) + $extra + 8388608 < $limit;
	}

	private static function inspect($source)
	{
		$handle = $source['stream']; $before = fstat($handle); $position = ftell($handle);
		if (!$before || ($before['mode'] & 0170000) !== 0100000 || !is_int($before['size']) || $before['size'] < 1 || $before['size'] > AlbumFileStorage::MAX_BYTES || !self::budget($before['size'] * 3) || fseek($handle, 0) !== 0) { throw new AlbumUploadFailure('Bad_upload_file_size'); }
		try
		{
			$data = stream_get_contents($handle, $before['size'] + 1); $after = fstat($handle);
			if (!is_string($data) || strlen($data) !== $before['size']) { throw new AlbumUploadFailure('Bad_upload_file_size'); }
			foreach (array('dev','ino','mode','size','mtime','ctime') as $field) { if (!isset($after[$field]) || $before[$field] !== $after[$field]) { throw new AlbumUploadFailure('Bad_upload_file_size'); } }
			$shape = @getimagesizefromstring($data); $extensions = array(IMAGETYPE_JPEG=>'.jpg',IMAGETYPE_PNG=>'.png',IMAGETYPE_GIF=>'.gif');
			if (!$shape || !isset($shape[0],$shape[1],$shape[2],$extensions[$shape[2]]) || !phpbb_image_dimensions_safe($shape[0],$shape[1])) { throw new AlbumUploadFailure('Not_allowed_file_type'); }
			return array('stream'=>$handle,'basename'=>$source['basename'],'data'=>$data,'metadata'=>array('present'=>true,'bytes'=>strlen($data),'sha256'=>hash('sha256',$data)),'width'=>(int)$shape[0],'height'=>(int)$shape[1],'extension'=>$extensions[$shape[2]]);
		}
		finally { if (is_int($position)) { fseek($handle, $position); } }
	}

	private function thumbnail($source)
	{
		$shape = album_thumbnail_dimensions($source['width'],$source['height'],$this->policy['thumbnail_size'],$this->policy['thumbnail_size']);
		$allocator = $this->policy['gd_version'] === 1 ? 'imagecreate' : 'imagecreatetruecolor'; $copier = $this->policy['gd_version'] === 1 ? 'imagecopyresized' : 'imagecopyresampled'; $encoder = $source['extension'] === '.jpg' ? 'imagejpeg' : 'imagepng';
		if (!$shape || !function_exists('imagecreatefromstring') || !function_exists($allocator) || !function_exists($copier) || !function_exists($encoder) || !self::budget($source['width']*$source['height']*8 + $shape[0]*$shape[1]*8 + $source['metadata']['bytes'])) { return null; }
		$image = null; $small = null; $level = ob_get_level();
		try
		{
			$image = @imagecreatefromstring($source['data']); if (!$image) { return null; }
			if ($shape[0] === $source['width'] && $shape[1] === $source['height']) { $small = $image; }
			else { $small = @$allocator($shape[0],$shape[1]); if (!$small || !@$copier($small,$image,0,0,0,0,$shape[0],$shape[1],$source['width'],$source['height'])) { return null; } }
			ob_start(); $ok = $source['extension'] === '.jpg' ? @$encoder($small,null,$this->policy['thumbnail_quality']) : @$encoder($small); $bytes = ob_get_clean();
			if (!$ok || !is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > AlbumFileStorage::MAX_BYTES) { return null; }
			// Unlike a generic system tmpfile, this private derived image remains
			// in the bounded PSID cleanup namespace even after a hard process kill.
			$path=$this->directory.DIRECTORY_SEPARATOR.$this->psid.'_derived_'.bin2hex(phpbb_random_bytes(16));
			$stream=@fopen($path,'x+b');if(!$stream){return null;}$this->handles[]=$stream;
			$this->derived[$path]=array('bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes));
			if(!@chmod($path,0600)){return null;}
			if (fwrite($stream,$bytes) !== strlen($bytes) || !fflush($stream) || fseek($stream,0) !== 0) { return null; }
			return array('stream'=>$stream,'metadata'=>array('present'=>true,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)),'width'=>$shape[0],'height'=>$shape[1],'extension'=>$source['extension']);
		}
		finally
		{
			while (ob_get_level() > $level) { ob_end_clean(); }
			if (PHP_VERSION_ID < 80000) { if (is_resource($small) && $small !== $image) { imagedestroy($small); } if (is_resource($image)) { imagedestroy($image); } }
		}
	}

	function operation() { if (!$this->open) { throw new AlbumUploadFailure('Not_Authorised'); } return $this->operation; }
	function matches_root($root) { return $this->open && is_string($root) && !is_link($root) && realpath($root) === $this->root; }
	function capability() { return hash('sha256', "album-upload-cap-v1\0" . $this->owner_token . "\0" . $this->operation()); }
	function original() { if (!$this->open) { throw new AlbumUploadFailure('Not_Authorised'); } return $this->original; }
	function small() { if (!$this->open) { throw new AlbumUploadFailure('Not_Authorised'); } return $this->small; }
	function provided() { return $this->provided; }
	function processing_policy() { return $this->policy; }

	function artifacts()
	{
		$filename = $this->operation() . $this->original['extension'];
		$result = array(array('scope'=>'original','name'=>$filename,'metadata'=>$this->original['metadata'],'stream'=>$this->original['stream']));
		if ($this->small !== null) { $result[] = array('scope'=>'small','name'=>$filename,'metadata'=>$this->small['metadata'],'stream'=>$this->small['stream']); }
		return $result;
	}

	function release()
	{
		foreach ($this->handles as $handle) { if (is_resource($handle)) { fclose($handle); } }
		foreach($this->derived as$path=>$expected)
		{
			clearstatcache(true,$path);
			// Remove only our complete private derivative. Changed/partial bytes
			// stay in the recognizable staging namespace for its age cleanup.
			if(!is_link($path)&&is_file($path)&&realpath(dirname($path))===$this->directory&&@filesize($path)===$expected['bytes']&&@hash_file('sha256',$path)===$expected['sha256']){@unlink($path);}
		}
		$this->derived=array();
		$this->handles = array(); $this->open = false;
	}
}
