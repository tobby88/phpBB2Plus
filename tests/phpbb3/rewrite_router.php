<?php
// The CLI web server ignores phpBB's .htaccess. Forward bare .html bookmarks to the
// same front controller as Apache and leave static files and PHP scripts to the server.
if (PHP_SAPI !== 'cli-server') { exit(1); }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path) || !preg_match('~^/[a-z0-9,-]+\.html$~D', $path)
	|| is_file($_SERVER['DOCUMENT_ROOT'] . $path))
{
	return false;
}
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/app.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/app.php';
unset($_SERVER['PATH_INFO']);
require $_SERVER['SCRIPT_FILENAME'];
return true;
