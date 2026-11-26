<?php
namespace phpbb2plus\arcade\service;

/** Describes the four file selections of the original local catalogue import. */
class import_directory
{
	public const EXTENSIONS = array('package'=>'swf','flash'=>'swf','gif'=>'gif','jpg'=>'jpg');

	private static function local_files($root, $subdir)
	{
		$dir = $subdir ? $root . DIRECTORY_SEPARATOR . $subdir : $root;
		$entries = scandir($dir);
		if ($entries===false) { throw new \RuntimeException('Cannot list the local import directory.'); }
		$files = $directories = array();
		foreach ($entries as $name)
		{
			if ($name[0]==='.') { continue; }
			$path = $dir . DIRECTORY_SEPARATOR . $name;
			if (is_link($path)) { continue; }
			$real = realpath($path);
			if ($real===false || dirname($real)!==$dir) { continue; }
			if (is_file($real)) { $files[$name] = $real; }
			elseif ($subdir==='' && is_dir($real)) { $directories[] = $name; }
		}
		return array($files,$directories);
	}

	public static function scan($directory, $kind, $media)
	{
		if (PHP_SAPI!=='cli' || strpos($directory,'://')!==false || substr(str_replace('\\','/',$directory),0,2)==='//' ||
			is_link($directory) || !isset(self::EXTENSIONS[$kind]))
		{ throw new \InvalidArgumentException('Choose a local directory and a supported import type.'); }
		$root = realpath($directory);
		if (!$root || !is_dir($root)) { throw new \InvalidArgumentException('The local import directory does not exist.'); }
		list($files,$directories) = self::local_files($root,'');
		$items = $skipped = array();
		foreach (array_merge(array(''),$directories) as $subdir)
		{
			if ($subdir!=='') { list($files) = self::local_files($root,$subdir); }
			foreach ($files as $name=>$path)
			{
				if (strtolower(pathinfo($name,PATHINFO_EXTENSION))!==self::EXTENSIONS[$kind]) { continue; }
				$relative = $subdir==='' ? $name : $subdir . '/' . $name;
				if (!preg_match('//u',$relative) || preg_match('/[\x00-\x1f\x7f]/',$relative)) { $skipped[] = $relative; continue; }
				$icon = $kind==='package' ? pathinfo($name,PATHINFO_FILENAME) . '.gif' : '';
				if ($icon!=='' && !isset($files[$icon])) { $skipped[] = $relative; continue; }
				try { list($key) = $media->prepare($path,array(),$icon); }
				catch (\InvalidArgumentException $e) { $skipped[] = $relative; continue; }
				list($width,$height) = self::dimensions($path,self::EXTENSIONS[$kind]);
				$items[] = array('key'=>$key,'source'=>$relative,'width'=>$width,'height'=>$height);
			}
		}
		return array($items,$skipped);
	}

	private static function dimensions($path, $extension)
	{
		if ($extension!=='swf')
		{
			$size = @getimagesize($path);
			return $size && $size[0]>0 && $size[1]>0 && $size[0]<=4096 && $size[1]<=4096 ? array($size[0],$size[1]) : array(0,0);
		}
		// Only the RECT at the front is needed. Avoid expanding the movie body
		// or trusting the uncompressed length declared in its header.
		$handle = fopen($path,'rb');
		if (!$handle) { throw new \RuntimeException('The prepared movie is no longer readable.'); }
		try
		{
			$header = fread($handle,8);
			$signature = substr($header,0,3);
			if ($signature==='FWS') { $rect = fread($handle,17); }
			elseif ($signature==='CWS' && function_exists('inflate_init'))
			{
				$stream = inflate_init(ZLIB_ENCODING_DEFLATE);
				$rect = '';
				for ($i=0; $i<64 && !feof($handle) && strlen($rect)<17; $i++)
				{
					$part = @inflate_add($stream,fread($handle,32),ZLIB_SYNC_FLUSH);
					if ($part===false) { return array(0,0); }
					$rect .= substr($part,0,17-strlen($rect));
				}
			}
			else { return array(0,0); }
		}
		finally { fclose($handle); }
		if (!$rect) { return array(0,0); }
		$bits = '';
		foreach (unpack('C*',$rect) as $byte) { $bits .= str_pad(decbin($byte),8,'0',STR_PAD_LEFT); }
		$n = bindec(substr($bits,0,5));
		if (!$n || strlen($bits)<5+4*$n) { return array(0,0); }
		$coords = array();
		for ($i=0; $i<4; $i++)
		{
			$value = substr($bits,5+$i*$n,$n);
			$coords[] = bindec($value) - ($value[0]==='1' ? 2 ** $n : 0);
		}
		$w = (int) round(($coords[1]-$coords[0])/20);
		$h = (int) round(($coords[3]-$coords[2])/20);
		return $w>0 && $h>0 && $w<=4096 && $h<=4096 ? array($w,$h) : array(0,0);
	}
}
