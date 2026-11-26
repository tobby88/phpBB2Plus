<?php
// The original direct entry displayed the smiley picker; replies use posting.php.
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, array('GET', 'HEAD', 'POST'), true)) { header('Allow: GET, HEAD, POST'); http_response_code(405); return; }
$mode = $method === 'POST' ? ($_POST['mode'] ?? $_GET['mode'] ?? '') : ($_GET['mode'] ?? '');
if ($mode !== 'smilies') { http_response_code(404); return; }
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('Location: ./posting.php?mode=smilies', true, $method === 'POST' ? 303 : 302);
