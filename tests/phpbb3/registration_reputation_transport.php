<?php
namespace phpbb2plus\community\service;

// Installed only inside this test's disposable board. No network transport is available.
class fixture_reputation_http
{
	public function request($method, $url, array $options)
	{
		global $phpbb_root_path;
		$log = $phpbb_root_path . 'store/plus-test-reputation-calls';
		$query = $options['query'];
		if (getenv('PLUS_TEST_DATABASE') !== '1' || !is_file($log) || $method !== 'GET' ||
			$url !== 'https://api.stopforumspam.org/api' || $options['allow_redirects'] || !$options['verify'] ||
			!$options['stream'] || $options['timeout'] > 4 || $query['f'] !== 'json') { throw new \LogicException('Unexpected lookup'); }
		unset($query['f']);
		$type = key($query);
		if (count($query) !== 1 || !in_array($type, array('ip', 'email', 'username'), true)) { throw new \LogicException('Unexpected fields'); }
		file_put_contents($log, $type . "\n", FILE_APPEND);
		$value = $query[$type];
		$status = strpos($value, 'unavailable-') === 0 ? 503 : 200;
		$listed = strpos($value, 'listed-') === 0 || $value === 'SfsTestListed';
		$body = strpos($value, 'invalid-') === 0 ? '{"success":1}' : json_encode(array('success' => 1, $type => array('appears' => (int) $listed)));
		return new \GuzzleHttp\Psr7\Response($status, array(), $body);
	}
}
