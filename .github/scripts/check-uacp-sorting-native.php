<?php
// Execute the actual controls, read-only listing and pagination on native SQL.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_UACP_NATIVE') !== '1') { echo "Attachment sorting requires explicit disposable storage.\n"; return; }
define('IN_PHPBB', true);
$table_prefix = 'phpbb_';
$phpEx = 'php';
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
require $phpbb_root_path . 'includes/php_compat.php';
require $phpbb_root_path . 'includes/constants.php';
require $phpbb_root_path . 'attach_mod/includes/constants.php';
require $phpbb_root_path . 'attach_mod/includes/functions_attach.php';
require $phpbb_root_path . 'attach_mod/includes/functions_includes.php';
require $phpbb_root_path . 'includes/auth.php';
require $phpbb_root_path . 'db/mysqli.php';
require $phpbb_root_path . 'includes/functions_uacp_listing.php';
require __DIR__ . '/uacp-list-native-fixture.php';
function us_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function message_die($level, $message) { $e = $GLOBALS['db']->sql_error(); throw new RuntimeException($message . ': ' . $e['message']); }
function us_sql($sql) { $r = $GLOBALS['db']->sql_query($sql); us_check($r !== false, 'Own fixture query'); return $r; }
function us_rows($sql) { $r = us_sql($sql); try { return $GLOBALS['db']->sql_fetchrowset($r); } finally { $GLOBALS['db']->sql_freeresult($r); } }
function generate_pagination($url, $total, $size, $offset) { $GLOBALS['us_page'] = array($url, $total, $size, $offset); return 'fixture pages'; }
class UacpSortTemplate { var $vars = array(); function assign_vars($vars) { $this->vars = array_merge($this->vars, $vars); } }
function us_request($request, $expected_mode, $expected_ids)
{
    global $HTTP_POST_VARS, $HTTP_GET_VARS, $board_config, $lang, $db, $us_sort, $us_select, $us_pagination, $us_page, $phpbb_root_path, $phpEx;
    $HTTP_POST_VARS = array(); $HTTP_GET_VARS = $request;
    $board_config = array('topics_per_page' => 2); $profiledata = array('user_id' => 2);
    $userdata = array('user_id'=>2,'session_id'=>str_repeat('a',32),'session_logged_in'=>true);
    eval($us_sort);
    eval($us_select);
    $actual = array(); foreach ($attachments as $row) { $actual[] = (int) $row['attach_id']; }
    us_check($actual === $expected_ids, 'Actual native attachment page: ' . json_encode($request) . '; expected ' . json_encode($expected_ids) . ', got ' . json_encode($actual));
    us_check($mode === $expected_mode, 'Canonical mode retained for pagination');
    us_check(preg_match_all('/<option value="([^"]+)" selected="selected">/', $select_sort_mode, $m) === 1 && $m[1][0] === $expected_mode, 'Exactly the actual active sort is selected');
    us_check(preg_match_all('/<option value="([^"]+)" selected="selected">/', $select_sort_order, $m) === 1 && $m[1][0] === $sort_order, 'Actual selected direction');
    us_check($total_rows === 5, 'Distinct owned/recipient attachment count, excluding another owner');
    $template = new UacpSortTemplate(); $userdata = array('session_id' => str_repeat('a', 32)); $us_page = null;
    eval($us_pagination);
    us_check($us_page === array($phpbb_root_path . 'uacp.php?mode=' . $expected_mode . '&amp;order=' . $sort_order . '&amp;u=2&amp;sid=' . $userdata['session_id'], 5, 2, $start), 'Actual pagination carries canonical sort, direction, owner and offset');
    us_check(isset($template->vars['PAGINATION'], $template->vars['PAGE_NUMBER']), 'Actual page controls rendered');
    return array($mode, $sort_order, $start, $select_sort_mode);
}
$port = getenv('PHPBB_UACP_PORT') ?: '3306';
us_check(preg_match('/^[0-9]{1,5}$/D', $port) === 1 && (int) $port > 0 && (int) $port <= 65535, 'Disposable loopback port');
$host = '127.0.0.1:' . $port; $password = getenv('PHPBB_UACP_PASSWORD') ?: '';
$schema = 'codex_uacp_' . bin2hex(phpbb_random_bytes(8));
$control = new sql_db($host, 'root', $password, '', false); $db = null;
us_check($control->sql_query('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci') !== false, 'Unique own database');
$reporting = error_reporting(E_ALL);
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try {
    $db = new sql_db($host, 'root', $password, $schema, false);
    if (getenv('PHPBB_UACP_MYSQL') === '8.4') { $v = us_rows('SELECT VERSION() version'); us_check(strpos($v[0]['version'], '8.4.') === 0 && stripos($v[0]['version'], 'MariaDB') === false, 'Actual MySQL 8.4 required'); }
    uacp_list_fixture_install($db,$phpbb_root_path,'phpbb_');
    uacp_list_fixture_insert($db,'phpbb_users',array('user_id'=>2,'username'=>'Synthetic owner','user_active'=>1,'user_level'=>USER,'user_blocktime'=>0));
    uacp_list_fixture_insert($db,'phpbb_users',array('user_id'=>3,'username'=>'Synthetic other','user_active'=>1,'user_level'=>USER,'user_blocktime'=>0));
    uacp_list_fixture_insert($db,'phpbb_config',array('config_name'=>'session_length','config_value'=>'3600'));
    uacp_list_fixture_insert($db,'phpbb_sessions',array('session_id'=>str_repeat('a',32),'session_user_id'=>2,'session_logged_in'=>1,'session_time'=>time()));
    uacp_list_fixture_insert($db,'phpbb_forums',array('forum_id'=>1,'auth_view'=>AUTH_ALL,'auth_read'=>AUTH_REG));
    foreach(array(101,102,103,105,106,107)as$id){uacp_list_fixture_insert($db,'phpbb_topics',array('topic_id'=>$id,'forum_id'=>1,'topic_title'=>'Synthetic topic '.$id));uacp_list_fixture_insert($db,'phpbb_posts',array('post_id'=>$id,'forum_id'=>1,'topic_id'=>$id,'poster_id'=>$id===107?3:2,'post_deleted'=>0));}
    uacp_list_fixture_insert($db,'phpbb_privmsgs',array('privmsgs_id'=>204,'privmsgs_type'=>PRIVMSGS_READ_MAIL,'privmsgs_from_userid'=>3,'privmsgs_to_userid'=>2,'privmsgs_write_payload'=>null));
    us_sql("INSERT INTO phpbb_attachments_desc(attach_id,physical_filename,real_filename,comment,extension,filesize,download_count,filetime) VALUES
        (1,'own1','delta','Bravo','jpg',30,5,300),
        (2,'own2','alpha','Delta','png',10,7,200),
        (3,'own3','alpha','Alpha','png',20,5,100),
        (4,'own4','charlie','Charlie','gif',40,9,400),
        (5,'own5','alpha','Alpha','png',20,5,100),
        (6,'other','aardvark','Aardvark','aaa',1,1,1)");
    us_sql('INSERT INTO phpbb_attachments VALUES(1,101,0,2,0),(2,102,0,2,0),(3,103,0,2,0),(4,0,204,3,2),(5,105,0,2,0),(5,106,0,2,0),(6,107,0,3,0)');
    $before = us_rows('SELECT * FROM phpbb_attachments_desc ORDER BY attach_id');
    $links = us_rows('SELECT * FROM phpbb_attachments ORDER BY attach_id,post_id');
    $source = str_replace("\r\n", "\n", file_get_contents($phpbb_root_path . 'uacp.php'));
    $a = strpos($source, '$start = max('); $b = strpos($source, '$delete = ', $a);
    us_check($a !== false && $b > $a, 'Actual request, ORDER BY and dropdown boundaries'); $us_sort = substr($source, $a, $b - $a);
    $a = strpos($source, '// Read count, page and parent labels'); $b = strpos($source, "\n$" . "hidden_fields = '';\n", $a);
    us_check($a !== false && $b > $a, 'Actual snapshot/listing call and effective page boundaries'); $us_select = substr($source, $a, $b - $a);
    $a = strpos($source, '// Generate Pagination'); $b = strpos($source, "$" . "template->pparse('body');", $a);
    us_check($a !== false && $b > $a, 'Actual pagination controls'); $us_pagination = substr($source, $a, $b - $a);
    $cases = 0;
    // Fixed fixture expectations, independent of the controller's SQL mapping.
    $orders = array('downloads' => array(1,3,5,2,4), 'post_time' => array(3,5,2,1,4), 'real_filename' => array(2,3,5,4,1), 'comment' => array(3,5,1,4,2), 'extension' => array(4,1,2,3,5), 'filesize' => array(2,3,5,1,4));
    foreach (array('english', 'german') as $locale) {
        $lang = array(); require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main.php'; require $phpbb_root_path . 'language/lang_' . $locale . '/lang_admin_attach.php';
        foreach ($orders as $mode => $ids) { foreach (array('ASC', 'DESC') as $direction) {
            $expected = $direction === 'ASC' ? $ids : array_reverse($ids); $joined = array();
            foreach (array(0,2,4) as $start) { us_request(array('mode'=>$mode,'order'=>$direction,'start'=>$start), $mode, array_slice($expected,$start,2)); $joined = array_merge($joined,array_slice($expected,$start,2)); $cases++; }
            us_check($joined === $expected && count(array_unique($joined)) === 5, 'Complete stable pagination with no skipped or repeated ties');
            $out=us_request(array('mode'=>$mode,'order'=>$direction,'start'=>6),$mode,array_slice($expected,4,2));us_check($out[2]===4,'Stale next-page offset clamps to last real page');$cases++;
        } }
        foreach (array('filename'=>'real_filename','a.real_filename'=>'real_filename','download_count'=>'downloads','filetime'=>'post_time') as $alias=>$mode) {
            us_request(array('mode'=>$alias,'order'=>'DESC'), $mode, array_slice(array_reverse($orders[$mode]),0,2)); $cases++;
        }
        foreach (array('', 'not-a-sort', 'downloads DESC; DROP TABLE phpbb_attachments', array('downloads')) as $bad) {
            us_request(array('mode'=>$bad), 'real_filename', array(2,3)); $cases++;
        }
        foreach (array('desc', 'DESC; SELECT 1', array('DESC')) as $bad) { us_request(array('mode'=>'filesize','order'=>$bad), 'filesize', array(2,3)); $cases++; }
        foreach (array(-1, -999999, array(4)) as $bad) { us_request(array('mode'=>'comment','start'=>$bad), 'comment', array(3,5)); $cases++; }
        foreach (array(1000001,2147483647) as $bad) { $out=us_request(array('mode'=>'comment','start'=>$bad), 'comment', array(2)); us_check($out[2]===4,'Bounded stale offset clamps to the actual last page'); $cases++; }
    }
    us_check(us_rows('SELECT * FROM phpbb_attachments_desc ORDER BY attach_id') === $before && us_rows('SELECT * FROM phpbb_attachments ORDER BY attach_id,post_id') === $links, 'All native sort requests are read-only');
    echo 'Attachment manager sorting: ', $cases, " actual-controller/native pages PASS; EN/DE six columns, stable ties, both directions, dropdowns, legacy aliases, malformed inputs, owner/recipient boundaries and unchanged data.\n";
} finally {
    if ($db) { $db->sql_close(); }
    us_check($control->sql_query('DROP DATABASE ' . $schema) !== false, 'Drop only this owned fixture'); $control->sql_close();
    restore_error_handler(); error_reporting($reporting);
}
