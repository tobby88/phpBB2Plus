<?php
/***************************************************************************
 *                           admin_phpinfo.php
 *                           -----------------
 * Safe PHP runtime summary for the administration panel.
 ***************************************************************************/

if (!empty($setmodules))
{
	$file = basename(__FILE__);
	$module['Systeminfo']['PHPInfo'] = $file;
	return;
}

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
$phpbb_root_path = '../';
require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);

function phpbb_runtime_info_html($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$german = isset($board_config['default_lang']) && $board_config['default_lang'] === 'german';
$title = $german ? 'PHP-Laufzeitinformationen' : 'PHP runtime information';
$labels = $german
	? array(
		'PHP-Version', 'Server-Schnittstelle', 'Betriebssystem', 'Architektur',
		'Speicherlimit', 'Maximale Uploadgröße', 'Maximale POST-Größe',
		'Maximale Laufzeit', 'Geladene Erweiterungen'
	)
	: array(
		'PHP version', 'Server API', 'Operating system', 'Architecture',
		'Memory limit', 'Maximum upload size', 'Maximum POST size',
		'Maximum execution time', 'Loaded extensions'
	);

$extensions = get_loaded_extensions();
sort($extensions, SORT_STRING | SORT_FLAG_CASE);
$rows = array(
	array($labels[0], PHP_VERSION),
	array($labels[1], PHP_SAPI),
	array($labels[2], PHP_OS),
	array($labels[3], PHP_INT_SIZE >= 8 ? '64 bit' : '32 bit'),
	array($labels[4], ini_get('memory_limit')),
	array($labels[5], ini_get('upload_max_filesize')),
	array($labels[6], ini_get('post_max_size')),
	array($labels[7], ini_get('max_execution_time') . ' s'),
	array($labels[8], implode(', ', $extensions))
);

echo '<h1>' . phpbb_runtime_info_html($title) . '</h1>';
echo '<table width="95%" cellspacing="1" cellpadding="4" border="0" align="center" class="forumline">';
foreach ($rows as $index => $row)
{
	$row_class = ($index % 2) ? 'row2' : 'row1';
	echo '<tr><th align="left" width="25%">' . phpbb_runtime_info_html($row[0]) . '</th>' .
		'<td class="' . $row_class . '">' . phpbb_runtime_info_html($row[1]) . '</td></tr>';
}
echo '</table>';

include('./page_footer_admin.' . $phpEx);

?>
