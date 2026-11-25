<?php
// The old TXT exporter also accepted bookmarks by post. That post selected the thread.
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, array('GET', 'HEAD', 'POST'), true))
{
	header('Allow: GET, HEAD, POST');
	http_response_code(405);
	return;
}
$read_id = static function ($value) {
	return is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) ? (int) $value : 0;
};
$post = $read_id($_GET['p'] ?? '');
$topic = $read_id(isset($_GET['t']) && $_GET['t'] !== '' ? $_GET['t'] : ($_GET['topic'] ?? ''));
if (!$post && !$topic) { http_response_code(404); return; }
$order = $_POST['postorder'] ?? $_GET['postorder'] ?? '';
$params = is_string($order) && strcasecmp($order, 'desc') === 0 ? array('postorder' => 'desc') : array();
if (isset($_GET['sid']) && is_string($_GET['sid']) && preg_match('/^[a-f0-9]{32}$/D', $_GET['sid'])) { $params['sid'] = $_GET['sid']; }
$path = $post ? 'post/' . $post . '/topic.txt' : 'topic/' . $topic . '/export.txt';
header('Cache-Control: private, no-store');
header('Location: ./app.php/' . $path . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : ''), true, $method === 'POST' ? 303 : 302);
