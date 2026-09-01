<?php

$root = dirname(dirname(__DIR__));
require_once $root . '/phpBB2/includes/php_compat.php';

function host_alias_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Host-alias safety check failed: $message\n");
		exit(1);
	}
}

$_SERVER = array('HTTPS' => 'on', 'SERVER_PORT' => '80');
host_alias_assert(phpbb_request_is_https(), 'HTTPS server flag was ignored');
$_SERVER = array('HTTPS' => 'off', 'SERVER_PORT' => '443');
host_alias_assert(phpbb_request_is_https(), 'direct TLS port was ignored');
$_SERVER = array('HTTPS' => 'off', 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https');
host_alias_assert(!phpbb_request_is_https(), 'untrusted forwarded protocol enabled TLS state');
$common_source = file_get_contents($root . '/phpBB2/common.php');
host_alias_assert(strpos($common_source, "header('Strict-Transport-Security: max-age=31536000')") !== false, 'HTTPS responses omit HSTS');

host_alias_assert(phpbb_board_hosts_match('www.example.com', 'example.com'), 'www/apex pair rejected');
host_alias_assert(phpbb_board_hosts_match('EXAMPLE.com.', 'example.com'), 'case/trailing-dot normalization failed');
host_alias_assert(!phpbb_board_hosts_match('forum.example.com', 'www.example.com'), 'unrelated subdomain accepted');
host_alias_assert(!phpbb_board_hosts_match('example.com.attacker.test', 'example.com'), 'suffix-spoofing host accepted');

host_alias_assert(phpbb_normalize_host('EXAMPLE.com.:443') === 'example.com', 'valid host and port were not normalized');
host_alias_assert(phpbb_normalize_host('[2001:db8::1]:8443') === '[2001:db8::1]', 'IPv6 host and port were not normalized');
host_alias_assert(phpbb_normalize_host("example.com\r\nInjected: value", 'safe.example') === 'safe.example', 'header-control data was accepted as a host');
host_alias_assert(phpbb_normalize_host('example.com/path', 'safe.example') === 'safe.example', 'host path data was accepted');
host_alias_assert(phpbb_normalize_host('user@example.com', 'safe.example') === 'safe.example', 'host credentials were accepted');
host_alias_assert(phpbb_normalize_port('443', 80) === 443, 'valid configured port was rejected');
host_alias_assert(phpbb_normalize_port('65536', 443) === 443, 'out-of-range configured port was accepted');
host_alias_assert(phpbb_normalize_port('80x', 443) === 443, 'non-numeric configured port was accepted');
host_alias_assert(phpbb_normalize_script_path('/forum/admin/../', '/') === '/', 'path traversal was accepted');
host_alias_assert(phpbb_normalize_script_path('/forum/', '/') === '/forum/', 'valid script path was not canonicalized');
host_alias_assert(phpbb_normalize_script_path('/forum%2fadmin/', '/safe/') === '/safe/', 'encoded script path data was accepted');
host_alias_assert(phpbb_normalize_external_url('https://example.com/file.zip?a=1&amp;b=2') === 'https://example.com/file.zip?a=1&b=2', 'valid HTML-encoded external URL was not normalized');
host_alias_assert(phpbb_normalize_external_url('https://example.com/image.jpg?size=large', array('jpg', 'png')) !== false, 'valid external image URL was rejected');
foreach (array('javascript:alert(1)', 'https://user@example.com/file', "https://example.com/bad\\path", "https://example.com/line\nbreak", 'https://example.com:70000/file', str_repeat('a', 2049)) as $invalid_url)
{
	host_alias_assert(phpbb_normalize_external_url($invalid_url) === false, 'unsafe external URL was accepted');
}
host_alias_assert(phpbb_normalize_external_url('https://example.com/image.svg', array('jpg', 'png')) === false, 'disallowed external image suffix was accepted');

host_alias_assert(phpbb_referer_is_allowed('https://example.com/album.php', 'www.example.com'), 'apex Referer rejected');
host_alias_assert(phpbb_referer_is_allowed('https://www.example.com/album.php', 'example.com'), 'www Referer rejected');
host_alias_assert(phpbb_referer_is_allowed('https://cdn.example.net/file', 'example.com', 'example.net'), 'explicit subdomain allowlist rejected');
host_alias_assert(!phpbb_referer_is_allowed('https://example.com.attacker.test/file', 'example.com'), 'Referer suffix spoof accepted');

$board_config = array(
	'server_name' => 'www.example.com',
	'server_port' => 443,
	'script_path' => '/',
	'cookie_secure' => 1
);
$_SERVER = array('REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://example.com');
host_alias_assert(phpbb_request_origin_is_valid(), 'apex POST Origin rejected');
$_SERVER['HTTP_ORIGIN'] = 'https://attacker.test';
host_alias_assert(!phpbb_request_origin_is_valid(), 'cross-site POST Origin accepted');
$_SERVER['HTTP_ORIGIN'] = 'http://example.com';
host_alias_assert(!phpbb_request_origin_is_valid(), 'cross-scheme POST Origin accepted');
$_SERVER['HTTP_ORIGIN'] = 'https://example.com:8443';
host_alias_assert(!phpbb_request_origin_is_valid(), 'cross-port POST Origin accepted');
foreach (array(
	'https://example.com/path',
	'https://example.com?query=1',
	'https://example.com#fragment',
	'https://user@example.com',
	'https://example.com/'
) as $malformed_origin)
{
	$_SERVER['HTTP_ORIGIN'] = $malformed_origin;
	host_alias_assert(!phpbb_request_origin_is_valid(), 'malformed POST Origin accepted: ' . $malformed_origin);
}
$_SERVER['HTTP_ORIGIN'] = array('https://example.com');
host_alias_assert(!phpbb_request_origin_is_valid(), 'non-scalar Origin was accepted');
$_SERVER = array('REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://example.com', 'HTTP_SEC_FETCH_SITE' => 'cross-site');
host_alias_assert(!phpbb_request_origin_is_valid(), 'contradictory cross-site Fetch Metadata was ignored');
$_SERVER = array('REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => array('same-origin'));
host_alias_assert(!phpbb_request_origin_is_valid(), 'non-scalar Fetch Metadata was accepted');

$_SERVER = array('HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_ORIGIN' => 'https://example.com');
host_alias_assert(phpbb_request_source_is_same_origin(), 'same-site www/apex Arcade source rejected');
$_SERVER['HTTP_ORIGIN'] = 'https://forum.example.com';
host_alias_assert(!phpbb_request_source_is_same_origin(), 'unrelated same-site Arcade source accepted');
$_SERVER['HTTP_ORIGIN'] = 'https://example.com/path';
host_alias_assert(!phpbb_request_source_is_same_origin(), 'malformed Arcade Origin accepted');

define('IN_PHPBB', true);
define('CTRACKER_SECURITY_NO_AUTO_RUN', true);
require_once $root . '/phpBB2/ctracker/engines/ct_security.php';
$alias_post = array(
	'REQUEST_METHOD' => 'POST',
	'HTTP_HOST' => 'www.example.com',
	'HTTP_ORIGIN' => 'https://example.com',
	'HTTP_SEC_FETCH_SITE' => 'same-site'
);
host_alias_assert(!ct_security_cross_site_write($alias_post), 'CrackerTracker rejected www/apex write');
$alias_post['HTTP_ORIGIN'] = 'https://forum.example.com';
host_alias_assert(ct_security_cross_site_write($alias_post), 'CrackerTracker accepted unrelated subdomain write');

foreach (array('album_pic.php', 'album_picm.php', 'album_thumbnail.php', 'pafiledb/modules/pa_download.php') as $relative)
{
	$source = file_get_contents($root . '/phpBB2/' . $relative);
	host_alias_assert(strpos($source, 'phpbb_referer_is_allowed') !== false, "$relative bypasses the shared Referer validator");
	host_alias_assert(strpos($source, 'strstr($check_referer') === false, "$relative retains substring-based Referer matching");
}

echo "Host-alias safety checks passed.\n";

?>
