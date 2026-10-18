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
class SerializedWakeFixture
{
	function __wakeup() { $GLOBALS['serialized_wakeup_calls']++; }
}
class SerializedCustomFixture implements Serializable
{
	function serialize() { return ''; }
	function unserialize($data) { $GLOBALS['serialized_custom_calls']++; }
	function __serialize() { return array(); }
	function __unserialize($data) { $GLOBALS['serialized_custom_calls']++; }
}
$serialized_wakeup_calls=$serialized_custom_calls=0;
foreach(array('SerializedWakeFixture'=>'O','SerializedCustomFixture'=>'C') as $name=>$kind)
{
	foreach(array((string)strlen($name),'+'.strlen($name),'+00'.strlen($name)) as $size)
	{
		$payload=$kind.':'.$size.':"'.$name.'":0:{}';
		foreach(array($payload,'a:1:{i:0;'.$payload.'}') as $encoded)
		{
			$value=phpbb_safe_unserialize($encoded);
			serialized_state_assert($serialized_wakeup_calls===0 && $serialized_custom_calls===0, 'signed/zero-padded top-level or nested object triggered native magic');
			serialized_state_assert(!($value instanceof SerializedWakeFixture) && !($value instanceof SerializedCustomFixture), 'unsafe root object returned');
		}
	}
}
// The oldest lowercase-o token also accepts signed property counts on PHP 5.
foreach(array('o:+0:"}','o:-0:"}','a:1:{i:0;o:+0:"}}') as $encoded)
{
	serialized_state_assert(phpbb_safe_unserialize($encoded)===false,'legacy signed object prefix must be rejected before decoding');
}
$serialized_autoload_calls=0;
$serialized_loader=function($name){$GLOBALS['serialized_autoload_calls']++;};spl_autoload_register($serialized_loader);
foreach(array('O','C') as $kind)
{
	$name='MissingSerializedFixture';$payload=$kind.':+'.strlen($name).':"'.$name.'":0:{}';
	phpbb_safe_unserialize($payload);phpbb_safe_unserialize('a:1:{i:0;'.$payload.'}');
}
spl_autoload_unregister($serialized_loader);
serialized_state_assert($serialized_autoload_calls===0,'unsafe signed object input reached autoload');
$serialized_old_limit=ini_get('pcre.backtrack_limit');
try
{
	ini_set('pcre.backtrack_limit','1');
	$encoded=serialize(array(';O:'.str_repeat('1',1000).'x',new SerializedWakeFixture));
	$value=phpbb_safe_unserialize($encoded);
	if(version_compare(PHP_VERSION,'7.0.0','<'))
	{
		serialized_state_assert($value===false && preg_last_error()===PREG_BACKTRACK_LIMIT_ERROR,'PCRE error must reject input rather than allow native PHP 5 decoding');
	}
	serialized_state_assert($serialized_wakeup_calls===0 && $serialized_custom_calls===0,'filter failure reached object magic');
}
finally { ini_set('pcre.backtrack_limit',$serialized_old_limit); }
foreach(array(null,false,true,0,-12,1.25,"Grüße 😀\0\\'",array('userid'=>2,'autologinid'=>'token'),array('cache'=>array(1,2,3))) as $expected)
{
	serialized_state_assert(phpbb_safe_unserialize(serialize($expected))===$expected,'ordinary scalar/cookie/cache state changed');
}
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
