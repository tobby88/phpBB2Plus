<?php
namespace phpbb2plus\banners\service;

class artwork
{
	public static function inspect($uploads, $name)
	{
		if (strlen($name) > 64 || !preg_match('/^[\pL\pN][\pL\pN ._-]*\.(gif|jpe?g|png|webp|swf)$/iuD', $name, $ext)) { return false; }
		$directory = $uploads . '/plus_banner_artwork';
		$file = $directory . '/' . $name;
		if (is_link($directory) || !is_dir($directory) || is_link($file) || !is_file($file) || !is_readable($file)) { return false; }
		$bytes = filesize($file);
		$type = strtolower($ext[1]);
		if ($type === 'swf')
		{
			$head = file_get_contents($file, false, null, 0, 8);
			if (!$head || strlen($head) < 8 || !in_array(substr($head,0,3),array('FWS','CWS','ZWS'),true)) { return false; }
			$declared = unpack('Vlength',substr($head,4))['length'];
			if ($declared < 8 || (substr($head,0,3) === 'FWS' && $bytes !== $declared)) { return false; }
			$mime = 'application/x-shockwave-flash';
		}
		else
		{
			$mime = 'image/' . (in_array($type,array('jpg','jpeg'),true) ? 'jpeg' : $type);
			$info = @getimagesize($file);
			if (!$info || $info['mime'] !== $mime) { return false; }
		}
		return array('asset_name'=>'library/' . $name,'asset_mime'=>$mime,'asset_bytes'=>$bytes,'remote_asset'=>'');
	}

	public static function available($uploads)
	{
		$directory = $uploads . '/plus_banner_artwork';
		$assets = array();
		if (is_link($directory) || !is_dir($directory) || !is_readable($directory)) { return $assets; }
		foreach (new \DirectoryIterator($directory) as $file)
		{
			$name = $file->getFilename();
			if (!$file->isDot() && ($asset = self::inspect($uploads,$name))) { $assets[$name] = $asset; }
		}
		uksort($assets,'strnatcasecmp');
		return $assets;
	}
}
