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
			if ($size<8 || (substr($header,0,3)==='FWS' && $size!==filesize($path))) { return ''; }
		}
		return $type;
	}
}
