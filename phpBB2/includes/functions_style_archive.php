<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Pure preflight: do not create directories, temporary files, or FTP actions
// until every entry has been checked. Historical XS exports use checksum 0.
function phpbb_style_archive_entries($archive)
{
	if (!is_string($archive) || strlen($archive) > XS_MAX_STYLE_UNPACKED_BYTES || strlen($archive) % 512) { throw new RuntimeException('Invalid style archive'); }
	$entries = array(); $seen = array(); $parents = array(); $length = strlen($archive); $pos = 0; $ended = false;
	while ($pos < $length)
	{
		$block = substr($archive, $pos, 512);
		if (trim($block, "\0") === '')
		{
			if ($length - $pos < 1024 || strspn($archive, "\0", $pos) !== $length - $pos) { throw new RuntimeException('Invalid archive trailer'); }
			$ended = true; break;
		}
		$data = unpack(TAR_HEADER_UNPACK, $block); $pos += 512;
		$size = trim($data['size'], " \0"); $mtime = trim($data['mtime'], " \0"); $type = trim($data['typeflag'], " \0");
		if (($size !== '' && !preg_match('/^[0-7]+$/D', $size)) || ($mtime !== '' && !preg_match('/^[0-7]+$/D', $mtime)) || !in_array($type, array('', '0', '5'), true)) { throw new RuntimeException('Invalid archive metadata'); }
		$data['size'] = $size === '' ? 0 : octdec($size); $data['mtime'] = $mtime === '' ? 0 : octdec($mtime); $data['typeflag'] = $type === '5' ? 5 : 0;
		$padded = ceil($data['size'] / 512) * 512;
		if ($data['size'] > XS_MAX_STYLE_UPLOAD_BYTES || $padded > $length - $pos || ($data['typeflag'] === 5 && $data['size'] !== 0)
			|| trim($data['link'], "\0 ") !== '') { throw new RuntimeException('Invalid archive entry'); }
		// Reject hidden bytes after a NUL, rather than interpreting two names.
		foreach (array('filename','prefix') as $field)
		{
			$value = rtrim($data[$field], "\0");
			if (strpos($value, "\0") !== false) { throw new RuntimeException('Invalid archive name'); }
			$data[$field] = $value;
		}
		$name = ($data['prefix'] === '' ? '' : $data['prefix'] . '/') . $data['filename'];
		if ($name === '' || substr($name, 0, 1) === '/') { throw new RuntimeException('Empty or absolute archive name'); }
		if (substr($name, 0, 2) === './') { $name = (string)substr($name, 2); }
		if ($data['typeflag'] === 5 && substr($name, -1) === '/') { $name = substr($name, 0, -1); }
		if (($name === '' && $data['typeflag'] !== 5) || strlen($name) > 255 || xs_fix_dir($name) !== $name
			|| preg_match('//u', $name) !== 1 || preg_match('#(?:^|/)(?:\.htaccess|\.user\.ini|xs_config\.cfg)$#i', $name)
			|| preg_match('#\.(?:php[0-9]*|phtml|phar|cgi|pl|sh)$#i', $name)) { throw new RuntimeException('Unsafe archive name'); }
		foreach ($name === '' ? array() : explode('/', $name) as $part)
		{
			if (trim($part, ' .') !== $part || preg_match('/[\x00-\x1f\x7f]/', $part) || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part)) { throw new RuntimeException('Ambiguous archive name'); }
		}
		$key = strtolower($name);
		if (isset($seen[$key]) || ($data['typeflag'] === 0 && isset($parents[$key]))) { throw new RuntimeException('Conflicting archive entries'); }
		$seen[$key] = $data['typeflag']; $parent = $key;
		while (($slash = strrpos($parent, '/')) !== false)
		{
			$parent = substr($parent, 0, $slash);
			if (isset($seen[$parent]) && $seen[$parent] !== 5) { throw new RuntimeException('File used as directory'); }
			$parents[$parent] = true;
		}
		$data['filename'] = $name === '' ? '' : $name . ($data['typeflag'] === 5 ? '/' : '');
		$data['offset'] = $pos; $data['tmp'] = '';
		$slash = strrpos($name, '/'); $data['dir'] = $slash === false ? '' : substr($name, 0, $slash); $data['file'] = $slash === false ? $name : substr($name, $slash + 1);
		$entries[] = $data;
		if (count($entries) > XS_MAX_STYLE_FILES) { throw new RuntimeException('Too many archive entries'); }
		$pos += (int)$padded;
	}
	if (!$ended || !$entries) { throw new RuntimeException('Incomplete style archive'); }
	return $entries;
}
