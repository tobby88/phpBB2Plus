<?php
// Actual read controller and pagination, canonical disposable native tables.
// Template/date/colour adapters only capture output; ACP write tests stay in
// check-userlist-native.php. This suite never invokes a bulk action.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_USERLIST_PAGES_NATIVE') !== '1') {
    echo "User-list page checks require an explicitly enabled disposable database.\n"; return;
}
define('IN_PHPBB', true); define('GENERAL_ERROR', 2); define('GENERAL_MESSAGE', 1); define('POST_USERS_URL', 'u');
define('USERS_TABLE', 'fixture_users'); define('GROUPS_TABLE', 'fixture_groups'); define('BANLIST_TABLE', 'fixture_banlist');
$up_source = dirname(dirname(__DIR__)) . '/phpBB2/'; $phpEx = 'php';
require $up_source . 'db/mysqli.php';
function up_check($ok, $why) { if (!$ok) { throw new RuntimeException($why); } }
function message_die($level, $why) { throw new RuntimeException('Actual controller error: ' . $why); }
function append_sid($url) { return $url; }
function phpbb_admin_session_field() { return ''; }
function color_group_colorize_name($id, $no_profile) { return 'Fixture ' . (int)$id; }
function create_date($format, $time, $zone) { return (string)$time; }
class UserlistPageTemplate {
    var $vars = array(); var $blocks = array(); var $parsed = false;
    function set_filenames($names) { up_check($names === array('body'=>'admin/admin_users_list_body.tpl'), 'Actual list template selected'); }
    function assign_vars($vars) { $this->vars = array_merge($this->vars, $vars); }
    function assign_block_vars($name, $vars) { $this->blocks[$name][] = $vars; }
    function pparse($name) { up_check($name === 'body', 'Actual list response'); $this->parsed = true; }
}
function up_rows($sql) {
    $result = $GLOBALS['db']->sql_query($sql); up_check($result, 'Native fixture query: ' . $sql); $rows = array();
    try { while ($row = $GLOBALS['db']->sql_fetchrow($result)) { $rows[] = $row; } }
    finally { $GLOBALS['db']->sql_freeresult($result); }
    return $rows;
}
function up_sql($sql) { up_check($GLOBALS['db']->sql_query($sql), 'Native fixture statement: ' . $sql); }
function up_insert($table, $values) {
    foreach ($GLOBALS['up_columns'][$table] as $column) {
        if (!array_key_exists($column['Field'], $values) && $column['Null'] === 'NO' && $column['Default'] === null && strpos($column['Extra'], 'auto_increment') === false) {
            $values[$column['Field']] = preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/', $column['Type']) ? 0 : '';
        }
    }
    $quoted = array(); foreach ($values as $value) { $quoted[] = "'".$GLOBALS['db']->sql_escape((string)$value)."'"; }
    up_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$quoted).')');
}
function up_page($request, $post = array()) {
    global $db, $phpEx, $lang, $board_config, $template;
    $_GET = $request; $_POST = $post; $_SERVER['REQUEST_METHOD'] = 'GET';
    $template = new UserlistPageTemplate(); eval($GLOBALS['up_body']);
    up_check($template->parsed, 'Actual controller completed');
    $ids = array(); foreach (isset($template->blocks['userrow']) ? $template->blocks['userrow'] : array() as $row) { $ids[] = $row['NUMBER']; }
    return array('ids'=>$ids, 'vars'=>$template->vars, 'blocks'=>$template->blocks);
}
$port = getenv('PHPBB_USERLIST_PAGES_PORT') ?: '3306';
up_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Explicit loopback fixture port');
$host = '127.0.0.1:'.$port; $password = getenv('PHPBB_USERLIST_PAGES_PASSWORD') ?: '';
$control = new sql_db($host, 'root', $password, '', false);
$fixture = 'codex_userlist_pages_'.bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8));
up_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Own canonical schema');
$db = new sql_db($host, 'root', $password, $fixture, false); $cases = 0;
try {
    set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
    if (getenv('PHPBB_USERLIST_PAGES_MYSQL') === '8.4') {
        $version = up_rows('SELECT VERSION() AS version');
        up_check(preg_match('/^8\.4\./', $version[0]['version']) && stripos($version[0]['version'], 'MariaDB') === false, 'Actual MySQL 8.4 server');
    }
    $schema = file_get_contents($up_source.'install/schemas/mysql_schema.sql'); $up_columns = array();
    foreach (array('users','groups','banlist') as $suffix) {
        up_check(preg_match('/CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/', $schema, $match) === 1, 'Canonical table '.$suffix);
        up_sql(str_replace('phpbb_'.$suffix, 'fixture_'.$suffix, $match[0]));
        $up_columns['fixture_'.$suffix] = up_rows('SHOW COLUMNS FROM fixture_'.$suffix);
    }
    up_sql('START TRANSACTION');
    foreach (array_merge(array(-1,0), range(1,60)) as $id) {
        up_insert(USERS_TABLE, array('user_id'=>$id, 'username'=>($id%2 ? 'Alpha ' : 'Beta ').$id,
            'user_password'=>'unused fixture hash', 'user_email'=>'fixture@example.invalid',
            'user_regdate'=>100, 'user_lastvisit'=>200, 'user_posts'=>5, 'user_active'=>1));
    }
    up_insert(GROUPS_TABLE, array('group_id'=>10, 'group_single_user'=>0, 'group_name'=>'Fixture <group>'));
    up_insert(GROUPS_TABLE, array('group_id'=>11, 'group_single_user'=>1, 'group_name'=>'Private fixture group'));
    up_insert(BANLIST_TABLE, array('ban_id'=>1, 'ban_userid'=>2, 'ban_ip'=>'', 'ban_email'=>''));
    up_sql('COMMIT');
    $controller = file_get_contents($up_source.'admin/admin_users_list.php');
    $a = strpos($controller, '$start = '); $b = strpos($controller, "include('./page_footer_admin.", $a);
    up_check($a !== false && $b > $a, 'Complete actual read controller'); $up_body = substr($controller, $a, $b-$a);
    // Use the real shared navigation formatter without bootstrapping a forum.
    $tokens = token_get_all(file_get_contents($up_source.'includes/functions.php'));
    for ($i=0; $i<count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $j=$i+1; while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (!is_array($tokens[$j]) || $tokens[$j][1] !== 'generate_pagination') { continue; }
        $body=''; $depth=0; $started=false;
        for (; $i<count($tokens); $i++) { $token=$tokens[$i]; $body.=is_array($token)?$token[1]:$token;
            if ($token==='{') { $depth++; $started=true; } elseif ($token==='}') { $depth--; } if ($started && !$depth) { break; }
        }
        eval($body); break;
    }
    up_check(function_exists('generate_pagination'), 'Actual shared pagination loaded');
    $board_config = array('topics_per_page'=>5, 'board_timezone'=>0); $snapshots = array();
    foreach (array(USERS_TABLE,GROUPS_TABLE,BANLIST_TABLE) as $table) { $snapshots[$table] = up_rows('SELECT * FROM '.$table.' ORDER BY 1'); }
    foreach (array('english','german') as $language) {
        $lang=array(); require $up_source.'language/lang_'.$language.'/lang_main.php'; require $up_source.'language/lang_'.$language.'/lang_admin.php';
        $page = up_page(array('show'=>'5','start'=>'999999'));
        up_check(count($page['ids']) === 5 && $page['vars']['PAGE_NUMBER'] === sprintf($lang['Page_of'],12,12), 'Old out-of-range page returns the last real page: '.$language); $cases++;
        $page = up_page(array('show'=>'5','start'=>'7'));
        up_check($page['vars']['PAGE_NUMBER'] === sprintf($lang['Page_of'],2,12), 'Partial offset belongs to a real page: '.$language); $cases++;
        $partial = $page['ids']; $aligned = up_page(array('show'=>'5','start'=>'5'));
        up_check($partial === $aligned['ids'], 'Partial offsets return the same complete page: '.$language); $cases++;
        foreach (array('user_id','user_regdate','user_lastvisit','user_posts','user_email','user_active') as $sort) {
            foreach (array('ASC','DESC') as $order) {
                foreach (array(1,5,7,200) as $size) {
                    $ids = array();
                    for ($offset=0; $offset<60; $offset+=$size) { $page=up_page(array('sort'=>$sort,'order'=>$order,'show'=>(string)$size,'start'=>(string)$offset)); $ids=array_merge($ids,$page['ids']); }
                    up_check($ids === ($order === 'ASC' ? range(1,60) : range(60,1)), 'Every tied sorted page is complete and deterministic: '.$sort.'/'.$order.'/'.$size.'/'.$language); $cases++;
                }
            }
        }
        foreach (array('A','B','Z') as $alpha) {
            $expected=array(); foreach(range(1,60) as $id) { if (($alpha==='A' && $id%2) || ($alpha==='B' && !($id%2))) { $expected[]=$id; } }
            $seen=array(); $limit=max(1,count($expected));
            for($offset=0;$offset<$limit;$offset+=7){$page=up_page(array('alpha'=>$alpha,'sort'=>'user_id','order'=>'ASC','show'=>'7','start'=>(string)$offset));$seen=array_merge($seen,$page['ids']);}
            up_check($seen===$expected && $page['vars']['TOTAL_USERS']===count($expected), 'Filter count and all real rows agree: '.$alpha.'/'.$language);$cases++;
            $last=up_page(array('alpha'=>$alpha,'sort'=>'user_id','order'=>'ASC','show'=>'7','start'=>'2147483647'));
            up_check($last['ids']===array_slice($expected,$expected?((int)floor((count($expected)-1)/7)*7):0), 'Filtered stale page is bounded, including empty results: '.$alpha.'/'.$language);$cases++;
        }
        $page=up_page(array('sort'=>'username','order'=>'ASC','show'=>'200'));
        $expected=up_rows('SELECT user_id FROM fixture_users WHERE user_id>0 ORDER BY username,user_id');$ids=array();foreach($expected as$row){$ids[]=(int)$row['user_id'];}
        up_check($page['ids']===$ids, 'Username collation and secondary identity ordering: '.$language);$cases++;
        $page=up_page(array('sort'=>array('bad'),'order'=>array('bad'),'start'=>array('bad'),'alpha'=>array('bad'),'show'=>array('bad')));
        up_check(count($page['ids'])===5 && $page['ids']===range(60,56), 'Array parameters use safe defaults: '.$language);$cases++;
        $page=up_page(array('sort'=>'invalid','order'=>'wrong','show'=>'0','start'=>'-10'));
        up_check($page['ids']===array(60) && $page['vars']['TOTAL_USERS']===60, 'Invalid values remain bounded and anonymous rows excluded: '.$language);$cases++;
        $page=up_page(array('sort'=>'user_id','order'=>'ASC','show'=>'1'),array('sort'=>'user_id','order'=>'DESC','show'=>'2'));
        up_check($page['ids']===array(60,59) && strpos($page['vars']['PAGINATION'],'order=DESC')!==false && strpos($page['vars']['PAGINATION'],'show=2')!==false, 'POST preferences survive generated navigation: '.$language);$cases++;
    }
    foreach($snapshots as$table=>$rows){up_check(up_rows('SELECT * FROM '.$table.' ORDER BY 1')===$rows,'Actual list pages never mutate '.$table);}
    echo 'Native user-list pages: '.$cases." cases PASS; actual read controller/navigation, canonical tables, EN/DE, tied sorts, stale/partial/empty pages, filters and unchanged data.\n";
} finally { $db->sql_close(); $control->sql_query('DROP DATABASE '.$fixture); $control->sql_close(); restore_error_handler(); }
