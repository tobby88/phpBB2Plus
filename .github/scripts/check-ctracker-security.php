<?php

define('IN_PHPBB', true);
define('CTRACKER_SECURITY_NO_AUTO_RUN', true);
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/engines/ct_security.php';

$options = array(
	'get_ignored' => array(),
	'post_ignored' => array(),
	'get_free_text' => array('search_keywords'),
	'post_free_text' => array('message', 'subject', 'website'),
	'custom_rules' => array(),
	'scan_post' => true
);
$errors = array();

function security_expect($expected, $label, $get, $post, $server, $options, &$errors)
{
	$actual = ct_security_request_is_attack($get, $post, $server, $options);
	if ($actual !== $expected)
	{
		$errors[] = $label . ': expected ' . ($expected ? 'blocked' : 'allowed');
	}
}

security_expect(false, 'ordinary query', array('t' => '28', 'start' => '20'), array(), array('QUERY_STRING' => 't=28&start=20'), $options, $errors);
security_expect(false, 'natural language search', array('search_keywords' => "Tom's PHP 8.5 guide: select, drop and update"), array(), array(), $options, $errors);
security_expect(false, 'technical forum discussion', array(), array('subject' => 'XSS example', 'message' => '<script>alert("example")</script> and php://filter'), array(), $options, $errors);
security_expect(false, 'ordinary website profile', array(), array('website' => 'https://example.org/docs/config.php'), array(), $options, $errors);
security_expect(true, 'SQL union injection', array('id' => '1 UNION SELECT user_password FROM phpbb_users'), array(), array(), $options, $errors);
security_expect(true, 'encoded traversal', array('path' => '%252e%252e%252fetc%252fpasswd'), array(), array(), $options, $errors);
security_expect(true, 'script scheme', array('redirect' => 'javascript:alert(1)'), array(), array(), $options, $errors);
security_expect(true, 'stacked SQL statement', array(), array('setting' => "safe'; DROP TABLE phpbb_users"), array(), $options, $errors);
security_expect(true, 'nested exploit value', array('filter' => array('value' => '1 UNION ALL SELECT 2')), array(), array(), $options, $errors);
security_expect(true, 'superglobal key', array('_SERVER' => 'value'), array(), array(), $options, $errors);
security_expect(true, 'null byte in free text', array(), array('message' => "hello\0world"), array(), $options, $errors);

if (ct_security_cross_site_write(array('REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'forum.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site')) !== true)
{
	$errors[] = 'Fetch Metadata cross-site POST was not blocked.';
}
if (ct_security_cross_site_write(array('REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'forum.example', 'HTTP_ORIGIN' => 'https://evil.example')) !== true)
{
	$errors[] = 'Cross-origin POST was not blocked.';
}
if (ct_security_cross_site_write(array('REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'Forum.Example', 'HTTP_ORIGIN' => 'https://forum.example')) !== false)
{
	$errors[] = 'Same-origin POST was incorrectly blocked.';
}
if (ct_security_cross_site_write(array('REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'forum.example')) !== false)
{
	$errors[] = 'Headerless legacy POST was incorrectly blocked.';
}
if (!ct_security_disallowed_method(array('REQUEST_METHOD' => 'TRACE')) || ct_security_disallowed_method(array('REQUEST_METHOD' => 'GET')))
{
	$errors[] = 'Unsafe HTTP method classification failed.';
}

$custom_options = $options;
$custom_options['custom_rules'] = array('project-specific-sentinel');
security_expect(true, 'custom literal rule', array('value' => 'PROJECT-SPECIFIC-SENTINEL'), array(), array(), $custom_options, $errors);

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "CrackerTracker structural request-security checks passed.\n";

?>
