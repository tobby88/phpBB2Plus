<?php
namespace phpbb2plus\arcade\service;

class media_format
{
	public static function entry_name($game, $flash)
	{
		return $flash && !pathinfo((string) $game,PATHINFO_EXTENSION) ? $game . '.swf' : (string) $game;
	}

	public static function type($path, $extension, $auxiliary)
	{
		$types = array('swf'=>'application/x-shockwave-flash','gif'=>'image/gif','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp',
			'mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav','m4a'=>'audio/mp4','mp4'=>'video/mp4','webm'=>'video/webm','ogv'=>'video/ogg',
			'mov'=>'video/quicktime','flv'=>'video/x-flv','mpeg'=>'video/mpeg','mpg'=>'video/mpeg');
		if ($auxiliary) { $types += array('xml'=>'application/octet-stream','txt'=>'application/octet-stream','json'=>'application/octet-stream','dat'=>'application/octet-stream'); }
		if (!isset($types[$extension])) { return ''; }
		$type = $types[$extension];
		if (strpos($type,'image/')===0)
		{
			$info = @getimagesize($path);
			return $info && $info['mime']===$type ? $type : '';
		}
		if ($extension==='swf')
		{
			$header = file_get_contents($path,false,null,0,8);
			if ($header===false || strlen($header)!==8 || !in_array(substr($header,0,3),array('FWS','CWS','ZWS'),true)) { return ''; }
			$size = unpack('Vsize',substr($header,4))['size'];
			if ($size<8) { return ''; }
			if (substr($header,0,3)==='FWS' && $size!==filesize($path) && !self::has_trailing_empty_frames($path,$size)) { return ''; }
		}
		return $type;
	}

	/** Some archived games repeat empty ShowFrame tags and End after a complete uncompressed movie. */
	private static function has_trailing_empty_frames($path,$declared)
	{
		$extra = filesize($path) - $declared;
		if ($extra < 4 || $extra % 2 !== 0) { return false; }
		$file = fopen($path, 'rb');
		if (!$file) { return false; }
		try
		{
			// Skip the SWF RECT, frame rate and frame count to reach the first top-level tag.
			if (fseek($file, 8) !== 0) { return false; }
			$rect = fread($file, 1);
			if ($rect === false || $rect === '') { return false; }
			$offset = 12 + intdiv(5 + 4 * (ord($rect) >> 3) + 7, 8);
			while ($offset + 2 <= $declared)
			{
				if (fseek($file, $offset) !== 0) { return false; }
				$header = fread($file, 2);
				if (strlen($header) !== 2) { return false; }
				$tag = unpack('vvalue', $header)['value'];
				$offset += 2;
				if (($tag >> 6) === 0)
				{
					if ($tag !== 0 || $offset !== $declared) { return false; }
					// Keep the original bytes. Only repeated ShowFrame records and one End may follow.
					$left = $extra - 2;
					while ($left > 0)
					{
						$chunk = min(8192, $left);
						if (fread($file, $chunk) !== str_repeat("\x40\0", intdiv($chunk, 2))) { return false; }
						$left -= $chunk;
					}
					return fread($file, 2) === "\0\0";
				}
				$length = $tag & 63;
				if ($length === 63)
				{
					if ($offset + 4 > $declared) { return false; }
					$header = fread($file, 4);
					if (strlen($header) !== 4) { return false; }
					$length = unpack('Vvalue', $header)['value'];
					$offset += 4;
				}
				if ($length > $declared - $offset) { return false; }
				$offset += $length;
			}
			return false;
		}
		finally { fclose($file); }
	}
}
