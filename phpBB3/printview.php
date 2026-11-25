<?php
require_once __DIR__ . '/ext/phpbb2plus/community/service/print_options.php';

// The old form posted the display filters. Its topic always came from the URL.
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, array('GET', 'HEAD', 'POST'), true))
{
	header('Allow: GET, HEAD, POST');
	http_response_code(405);
	return;
}
$id = $_GET['t'] ?? $_GET['topic'] ?? '';
if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id)) { http_response_code(404); return; }
$filters = \phpbb2plus\community\service\print_options::filters($_POST['postdays'] ?? $_GET['postdays'] ?? 0,
	$_POST['postorder'] ?? $_GET['postorder'] ?? '');
if (isset($_GET['sid']) && is_string($_GET['sid']) && preg_match('/^[a-f0-9]{32}$/D', $_GET['sid'])) { $filters['sid'] = $_GET['sid']; }
header('Cache-Control: private, no-store');
header('Location: ./app.php/topic/' . $id . '/print' . ($filters ? '?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986) : ''),
	true, $method === 'POST' ? 303 : 302);
