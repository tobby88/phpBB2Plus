<?php
namespace phpbb2plus\downloads\service;

class file_display
{
	const ICON_PATH = 'ext/phpbb2plus/downloads/styles/all/theme/images/file-icons/';

	public static function imported($source)
	{
		$name = $source['plus_posticon'] ?? $source['file_posticon'] ?? '';
		return array('icon_name'=>is_string($name) && $name !== 'none.gif' && preg_match('/^[a-z0-9_-][a-z0-9_.-]{0,90}\.(?:gif|bmp|png|jpe?g)$/iD',$name) ? $name : '',
			'preview_link'=>(int) !empty($source['plus_preview_link'] ?? $source['file_sshot_link'] ?? 0));
	}

	public static function icons()
	{
		static $icons;
		if ($icons === null)
		{
			$icons = array();
			$dir = __DIR__ . '/../styles/all/theme/images/file-icons';
			foreach (is_readable($dir) && is_dir($dir) ? (scandir($dir) ?: array()) : array() as $name)
			{
				if (self::imported(array('file_posticon'=>$name))['icon_name'] !== '' && is_file($dir . '/' . $name))
				{ $icons[$name] = $name; }
			}
		}
		return $icons;
	}

	public static function icon_url($name, $root)
	{
		return isset(self::icons()[$name]) ? $root . self::ICON_PATH . rawurlencode($name) : '';
	}
}
