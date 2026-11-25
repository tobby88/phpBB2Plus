<?php
namespace phpbb2plus\gallery\service;

/** Supply ordinary image inputs from an uploaded ZIP. Member paths never become disk paths. */
class upload_archive
{
	const MAX_BYTES = 104857600;
	const MAX_IMAGES = 50;

	public static function available() { return class_exists('\ZipArchive'); }

	public static function filename($name)
	{
		$parts = explode('/', str_replace('\\', '/', $name));
		return end($parts);
	}

	public static function images($path, $count_limit, $byte_limit, &$remaining_bytes)
	{
		if (!self::available()) { throw new \DomainException('PLUS_ALBUM_ZIP_UNAVAILABLE'); }
		if (filesize($path) > self::MAX_BYTES) { throw new \DomainException('PLUS_ALBUM_ZIP_LIMIT'); }
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) { throw new \DomainException('PLUS_ALBUM_ZIP_INVALID'); }
		try
		{
			if ($zip->numFiles > 1024) { throw new \DomainException('PLUS_ALBUM_ZIP_LIMIT'); }
			$entries = $names = array();
			$bytes = 0;
			for ($i = 0; $i < $zip->numFiles; $i++)
			{
				$entry = $zip->statIndex($i);
				if (!$entry || !isset($entry['name'], $entry['size'], $entry['crc'])) { throw new \DomainException('PLUS_ALBUM_ZIP_INVALID'); }
				$name = self::filename($entry['name']);
				if ($name === '' && $entry['size'] === 0) { continue; } // Directory record.
				$type = 0;
				if ($zip->getExternalAttributesIndex($i, $system, $attributes) && $system === \ZipArchive::OPSYS_UNIX)
				{
					$type = ($attributes >> 16) & 0170000;
				}
				$key = strtolower($name);
				if (($type !== 0 && $type !== 0100000) || !preg_match('/\.(?:jpe?g|png|gif)$/iD', $name) ||
					!preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f]/', $name) || utf8_strlen($name) > 255 ||
					isset($names[$key]) || !empty($entry['encryption_method']))
				{ throw new \DomainException('PLUS_ALBUM_ZIP_CONTENTS'); }
				if ($entry['size'] < 1 || $entry['size'] > 67108864 || count($entries) >= $count_limit ||
					$entry['size'] > min($byte_limit, $remaining_bytes) - $bytes)
				{ throw new \DomainException('PLUS_ALBUM_ZIP_LIMIT'); }
				$bytes += $entry['size'];
				$names[$key] = true;
				$entries[] = $entry;
			}
			if (!$entries) { throw new \DomainException('PLUS_ALBUM_ZIP_CONTENTS'); }
			$images = array();
			foreach ($entries as $entry) { $images[] = self::read($zip, $entry); }
			$remaining_bytes -= $bytes;
			return $images;
		}
		finally { $zip->close(); }
	}

	private static function read($zip, $entry)
	{
		$input = $zip->getStream($entry['name']);
		if (!$input) { throw new \DomainException('PLUS_ALBUM_ZIP_INVALID'); }
		try
		{
			$output = tmpfile();
			if (!$output || stream_copy_to_stream($input, $output, $entry['size'] + 1) !== $entry['size'] || !fflush($output))
			{ throw new \DomainException('PLUS_ALBUM_ZIP_INVALID'); }
			$path = stream_get_meta_data($output)['uri'];
			if (hash_file('crc32b', $path) !== sprintf('%08x', $entry['crc'])) { throw new \DomainException('PLUS_ALBUM_ZIP_INVALID'); }
			// tmpfile owns the member until publication, validation failure or request termination.
			return array('name' => self::filename($entry['name']), 'path' => $path, 'stream' => $output);
		}
		finally { fclose($input); }
	}
}
