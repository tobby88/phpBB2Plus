<?php

function serialized_state_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Serialized state safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
require_once $root . '/phpBB2/includes/php_compat.php';

serialized_state_assert(phpbb_safe_unserialize('O:8:"stdClass":0:{}') === false, 'objects must remain rejected');
$scalars = phpbb_safe_unserialize_scalar_array(serialize(array('ok', array('nested'), 'last')));
serialized_state_assert($scalars === array(0 => 'ok', 2 => 'last'), 'scalar-list decoder retained a nested value');

$tracking = phpbb_tracking_cookie_array(serialize(array(
	1 => time() - 10,
	2 => array('nested'),
	'bad' => time(),
	3 => time() + 86400,
)));
serialized_state_assert(isset($tracking[1], $tracking[3]) && !isset($tracking[2]) && !isset($tracking['bad']), 'tracking cookie shape was not normalized');
serialized_state_assert($tracking[3] <= time(), 'future tracking timestamp was not clamped');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/phpBB2', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file)
{
	if (!$file->isFile() || strtolower($file->getExtension()) !== 'php')
	{
		continue;
	}
	$relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
	$source = file_get_contents($file->getPathname());
	if ($relative !== 'phpBB2/includes/php_compat.php' && $relative !== 'phpBB2/includes/functions.php' && strpos($source, 'phpbb_safe_unserialize(') !== false)
	{
		fwrite(STDERR, $relative . ": directly consumes untyped serialized state\n");
		exit(1);
	}
	if (preg_match('/^(?=[^\r\n]*HTTP_COOKIE_VARS)(?=[^\r\n]*board_config\[\'cookie_name\'\])(?=[^\r\n]*phpbb_safe_unserialize_array)/m', $source))
	{
		fwrite(STDERR, $relative . ": tracking cookie bypasses numeric normalization\n");
		exit(1);
	}
}

$arcade = file_get_contents($root . '/phpBB2/includes/functions_arcade.php');
serialized_state_assert(strpos($arcade, "is_array(\$GameData[\$count])") !== false, 'Arcade tournament rows lack nested type validation');
$fields = file_get_contents($root . '/phpBB2/pafiledb/includes/functions_field.php');
serialized_state_assert(substr_count($fields, 'phpbb_safe_unserialize_scalar_array(') >= 7, 'paFileDB custom-field lists are not scalar-normalized');

echo "Serialized state safety checks passed.\n";
