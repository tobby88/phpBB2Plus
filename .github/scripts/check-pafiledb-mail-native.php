<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_PA_MAIL_NATIVE') !== '1') { echo "Native paFileDB mail fixture skipped.\n"; return; }
define('IN_PHPBB', true);
if (isset($argv[1]) && $argv[1] === 'legacy') { define('PHPBB_LEGACY_REQUEST_ESCAPED', true); }
$project = dirname(dirname(__DIR__)); $phpbb_root_path = $project . '/phpBB2/'; $phpEx = 'php'; $table_prefix = 'fixture_';
require $phpbb_root_path . 'includes/php_compat.php';
require $phpbb_root_path . 'includes/constants.php';
require $phpbb_root_path . 'attach_mod/includes/constants.php';
require $phpbb_root_path . 'pafiledb/includes/pafiledb_constants.php';
require $phpbb_root_path . 'db/mysqli.php';
require $phpbb_root_path . 'pafiledb/includes/functions.php';
require $phpbb_root_path . 'pafiledb/modules/pa_email.php';
require $phpbb_root_path . 'includes/emailer.php';
require $phpbb_root_path . 'includes/template.php';
require $phpbb_root_path . 'includes/functions_board_mail.php';
class PaMailMessage extends RuntimeException {}
function message_die($level, $text) { throw new PaMailMessage($text); }
function append_sid($url, $html = true) { return $url; }
function redirect($url) { throw new PaMailMessage('redirect:' . $url); }
function pm_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['pm_cases']++; }
class PaMailTemplate {
    public $data = array(), $blocks = array();
    function assign_vars($data) { $this->data = array_merge($this->data, $data); }
    function assign_block_vars($name, $data) { $this->blocks[$name][] = $data; }
}
class PaMailConnection {
    public $inner, $db_connect_id;
    function __construct($inner) { $this->inner = $inner; $this->db_connect_id = $inner->db_connect_id; }
    function __call($name, $args) { return call_user_func_array(array($this->inner, $name), $args); }
    function sql_query($sql, $transaction = false) {
        if (is_callable($GLOBALS['pm_query_hook'])) { call_user_func($GLOBALS['pm_query_hook'], $sql); }
        if ($GLOBALS['pm_fail_sql'] !== '' && strpos($sql, $GLOBALS['pm_fail_sql']) === 0) { return false; }
        return $this->inner->sql_query($sql, $transaction);
    }
}
class PaMailSource extends sql_db {
    function sql_dedicated_connection() {
        $GLOBALS['pm_factories']++;
        if (isset($GLOBALS['pm_borrowed'])) { return $GLOBALS['pm_borrowed']; }
        $owner = new PaMailConnection(parent::sql_dedicated_connection()); $GLOBALS['pm_owner'] = $owner; return $owner;
    }
}
class PaMailCapture extends emailer {
    function php_mail($to) {
        $GLOBALS['pm_capture'][] = array('to'=>$to,'subject'=>$this->subject,'message'=>$this->msg,'headers'=>$this->extra_headers,'vars'=>$this->vars);
        if (is_callable($GLOBALS['pm_transport_hook'])) { call_user_func($GLOBALS['pm_transport_hook']); }
        if ($GLOBALS['pm_transport'] === 'throw') { throw new RuntimeException('Fixture transport exception'); }
        return $GLOBALS['pm_transport'] === 'accept';
    }
}
class PaMailController extends pafiledb_email {
    function generate_category_nav($id) {}
    function display($title, $file) { $GLOBALS['pm_displayed'] = $file; }
    function create_mailer($smtp) { pm_check((string)$smtp === '0', 'Capture transport cannot use SMTP'); return new PaMailCapture(false, true); }
}
function pm_sql($sql) { $r = $GLOBALS['peer']->sql_query($sql); if (!$r) { throw new RuntimeException('Own mail fixture SQL failed: ' . json_encode($GLOBALS['peer']->sql_error())); } return $r; }
function pm_rows($sql) { $r = pm_sql($sql); try { return $GLOBALS['peer']->sql_fetchrowset($r); } finally { $GLOBALS['peer']->sql_freeresult($r); } }
function pm_insert($table, $values) {
    foreach (pm_rows('SHOW COLUMNS FROM ' . $table) as $column) {
        if (!array_key_exists($column['Field'], $values) && $column['Null'] === 'NO' && $column['Default'] === null && strpos($column['Extra'], 'auto_increment') === false) {
            $values[$column['Field']] = preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/', $column['Type']) ? 0 : '';
        }
    }
    $encoded = array(); foreach ($values as $value) { $encoded[] = $value === null ? 'NULL' : "'" . $GLOBALS['peer']->sql_escape((string)$value) . "'"; }
    pm_sql('INSERT INTO ' . $table . ' (' . implode(',', array_keys($values)) . ') VALUES(' . implode(',', $encoded) . ')');
}
function pm_post($data) { return defined('PHPBB_LEGACY_REQUEST_ESCAPED') ? phpbb_addslashes_recursive($data) : $data; }
function pm_reset() {
    global $userdata, $board_config, $pafiledb_config, $pafiledb_template;
    $GLOBALS['pm_capture'] = array(); $GLOBALS['pm_transport'] = 'accept'; $GLOBALS['pm_transport_hook'] = $GLOBALS['pm_query_hook'] = null;
    $GLOBALS['pm_fail_sql'] = $GLOBALS['pm_displayed'] = ''; unset($GLOBALS['pm_borrowed']);
    foreach (array('pa_files','pa_cat','pa_auth','users','sessions','user_group','groups','config','ctracker_config','ctracker_rate_limits','banlist','disallow','words') as $table) { pm_sql('DELETE FROM fixture_' . $table); }
    $board_config = array('session_length'=>'3600','flood_interval'=>'60','smtp_delivery'=>'0','smtp_host'=>'','smtp_username'=>'','smtp_password'=>'',
        'board_email'=>'board@example.invalid','server_name'=>'fixture.invalid','server_port'=>'443','script_path'=>'','cookie_secure'=>1,'sitename'=>'Native mail','default_lang'=>'english','sendmail_fix'=>'0');
    foreach ($board_config as $name=>$value) { pm_insert('fixture_config', array('config_name'=>$name,'config_value'=>$value)); }
    foreach (array('massmail_protection'=>'1','massmail_time'=>'5') as $name=>$value) { pm_insert('fixture_ctracker_config', array('ct_config_name'=>$name,'ct_config_value'=>$value)); }
    foreach (array(-1,8,9) as $id) { pm_insert('fixture_users', array('user_id'=>$id,'username'=>$id === -1 ? 'Anonymous' : ($id === 8 ? 'Sender &amp; Änne' : 'Other member'),
        'user_email'=>$id === -1 ? '' : 'member' . $id . '@example.invalid','user_active'=>$id === -1 ? 0 : 1,'user_level'=>USER,'user_emailtime'=>0,'user_blocktime'=>0)); }
    foreach (array(array('fixture-member',8,1),array('fixture-other',9,1),array('fixture-guest',-1,0)) as $session) {
        pm_insert('fixture_sessions', array('session_id'=>$session[0],'session_user_id'=>$session[1],'session_logged_in'=>$session[2],'session_time'=>time()));
    }
    pm_insert('fixture_pa_cat', array('cat_id'=>5,'cat_name'=>'Mail category','cat_parent'=>0,'auth_view'=>AUTH_ALL,'auth_read'=>AUTH_ALL,'auth_view_file'=>AUTH_ALL,'auth_email'=>AUTH_REG));
    pm_insert('fixture_pa_files', array('file_id'=>42,'file_catid'=>5,'file_name'=>'Datei Äöü 😀','file_approved'=>1));
    $userdata = array('user_id'=>8,'user_level'=>USER,'session_id'=>'fixture-member','session_logged_in'=>true,'username'=>'Sender &amp; Änne','user_email'=>'member8@example.invalid');
    $pafiledb_config = array('auth_toplist'=>0,'auth_search'=>0,'auth_stats'=>0,'auth_viewall'=>0,'settings_dbname'=>'Downloads');
    $pafiledb_template = new PaMailTemplate(); $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_REQUEST = array('file_id'=>'42'); $_POST = pm_post(array('sid'=>'fixture-member','submit'=>'Send','fname'=>'Friend Änne','femail'=>'friend@example.invalid','subject'=>'Grüße 😀','message'=>"C:\\Pfad\\Grüße O'Reilly &amp;"));
}
function pm_send() { $controller = new PaMailController(); return pafiledb_send_recommendation($GLOBALS['db'], 42, $_POST, array($controller, 'create_mailer')); }
function pm_denied($call, $key = null) {
    $caught = null; try { $call(); } catch (PaFileDbMailFailure $error) { $caught = $error->getMessage(); }
    pm_check($caught !== null && ($key === null || $key === $caught), 'Expected controlled mail rejection ' . $key . ', got ' . $caught);
}
function pm_controller($controller) { try { $controller->main(); } catch (PaMailMessage $message) { return $message->getMessage(); } return null; }
$port = getenv('PHPBB_PA_MAIL_PORT') ?: '3306'; $password = getenv('PHPBB_PA_MAIL_PASSWORD') ?: '';
if (!preg_match('/^[0-9]{1,5}$/D', $port) || (int)$port < 1 || (int)$port > 65535) { throw new RuntimeException('Own loopback fixture port required'); }
$fixture = 'codex_pa_mail_' . bin2hex(phpbb_random_bytes(8)); $host = '127.0.0.1:' . $port;
$control = new sql_db($host, 'root', $password, '', false); $db = $peer = null; $created = false; $pm_cases = $pm_factories = 0;
try {
    pm_check($control->sql_query('CREATE DATABASE ' . $fixture . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Own disposable mail schema'); $created = true;
    $db = new PaMailSource($host, 'root', $password, $fixture, false); $peer = new sql_db($host, 'root', $password, $fixture, false);
    $schema = file_get_contents($phpbb_root_path . 'install/schemas/mysql_schema.sql');
    foreach (array('pa_files','pa_cat','pa_auth','users','sessions','user_group','groups','config','ctracker_config','ctracker_rate_limits','banlist','disallow','words') as $table) {
        pm_check(preg_match('/^CREATE TABLE `?phpbb_' . $table . '`?\s*\([\s\S]*?;/m', $schema, $match) === 1, 'Canonical mail participant: ' . $table);
        pm_sql(str_replace('phpbb_' . $table, 'fixture_' . $table, $match[0]));
    }
    foreach (array('english','german') as $locale) {
        $lang = array(); require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main.php'; require $phpbb_root_path . 'language/lang_' . $locale . '/lang_pafiledb.php';
        foreach (array('PA_mail_invalid','PA_mail_too_long','PA_mail_sender_invalid','PA_mail_busy','PA_mail_wait','PA_mail_failed','PA_mail_upgrade') as $key) { pm_check(isset($lang[$key]) && preg_match('//u', $lang[$key]) === 1, 'Mail feedback translated'); }
    }
    pm_reset(); $controller = new PaMailController(); $controller->init(); $message = pm_controller($controller);
    pm_check($message !== null && count($pm_capture) === 1, 'Actual member form sends without a posted sender name');
    pm_check($pm_capture[0]['vars']['FROM_USERNAME'] === 'Sender & Änne' && strpos($pm_capture[0]['headers'], 'Reply-to: member8@example.invalid') !== false, 'Stored member sender decoded once and used for real headers');
    pm_check($pm_capture[0]['vars']['MESSAGE'] === "C:\\Pfad\\Grüße O'Reilly &amp;", 'Real mailer preserves raw Unicode/backslash/entity body');
    pm_check(strpos($pm_capture[0]['headers'], 'charset=UTF-8') !== false && preg_match('//u', $pm_capture[0]['message']) === 1, 'Real MIME body is UTF-8');
    pm_denied(function() { pm_send(); }, 'PA_mail_wait'); pm_check(count($pm_capture) === 1, 'Sequential duplicate never reaches transport');
    pm_reset(); $_POST['sname'] = 'Forged'; $_POST['semail'] = 'forged@example.invalid'; pm_sql("UPDATE fixture_users SET username='Fresh Sender',user_email='fresh@example.invalid' WHERE user_id=8"); pm_send();
    pm_check($pm_capture[0]['vars']['FROM_USERNAME'] === 'Fresh Sender' && strpos($pm_capture[0]['headers'], 'Reply-to: fresh@example.invalid') !== false, 'Current account replaces snapshot and forged POST identity');
    foreach (array('fname'=>100,'subject'=>200,'message'=>10000) as $field=>$limit) {
        pm_reset(); $_POST[$field] = pm_post(array('v'=>str_repeat('x', $limit - 1) . '😀')); $_POST[$field] = $_POST[$field]['v']; pm_send();
        $actual = $field === 'fname' ? $pm_capture[0]['vars']['TO_USERNAME'] : ($field === 'message' ? $pm_capture[0]['vars']['MESSAGE'] : $pm_capture[0]['subject']);
        pm_check($actual === str_repeat('x', $limit - 1) . '😀' && preg_match('//u', $actual) === 1, 'Actual mailer retains full Unicode field at character limit');
    }
    foreach (array('DELETE FROM fixture_sessions','UPDATE fixture_sessions SET session_time=1','UPDATE fixture_sessions SET session_user_id=9','UPDATE fixture_sessions SET session_logged_in=0',
        'UPDATE fixture_users SET user_active=0 WHERE user_id=8','DELETE FROM fixture_users WHERE user_id=8','UPDATE fixture_users SET user_blocktime=' . (time()+3600) . ' WHERE user_id=8',
        'UPDATE fixture_pa_cat SET auth_email=' . AUTH_ADMIN,'UPDATE fixture_pa_cat SET auth_view_file=' . AUTH_ADMIN,'UPDATE fixture_pa_cat SET cat_parent=5',
        'UPDATE fixture_pa_files SET file_approved=0','DELETE FROM fixture_pa_files') as $change) {
        pm_reset(); $controller = new PaMailController(); $controller->init(); pm_sql($change); pm_controller($controller);
        pm_check(count($pm_capture) === 0 && !pm_rows('SELECT * FROM fixture_ctracker_rate_limits'), 'Revoked current state never sends or consumes a quota');
    }
    foreach (array('', 'wrong', array()) as $sid) { pm_reset(); $_POST['sid'] = $sid; $before = $pm_factories; pm_denied(function() { pm_send(); }, 'Session_invalid'); pm_check($before === $pm_factories, 'Invalid SID rejected before owned connection'); }
    pm_reset(); $userdata['session_id'] = $_POST['sid'] = ''; pm_denied(function() { pm_send(); }, 'Session_invalid');
    pm_reset(); $_SERVER['REQUEST_METHOD'] = 'GET'; pm_denied(function() { pm_send(); }, 'Session_invalid');
    pm_reset(); pm_sql('UPDATE fixture_pa_cat SET auth_email=' . AUTH_ADMIN); $controller = new PaMailController(); $controller->init(); pm_sql('UPDATE fixture_pa_cat SET auth_email=' . AUTH_REG); pm_controller($controller); pm_check(count($pm_capture) === 1, 'Fresh grant can authorize send despite cached denial');
    pm_reset(); pm_insert('fixture_pa_cat', array('cat_id'=>6,'cat_name'=>'Parent','cat_parent'=>0,'auth_view'=>AUTH_ADMIN,'auth_read'=>AUTH_ALL)); pm_sql('UPDATE fixture_pa_cat SET cat_parent=6 WHERE cat_id=5'); pm_denied(function() { pm_send(); }, 'Not_Authorised');
    pm_reset(); pm_sql('UPDATE fixture_pa_cat SET auth_email=' . AUTH_ACL); pm_insert('fixture_groups',array('group_id'=>12,'group_name'=>'Mail group')); pm_insert('fixture_user_group',array('group_id'=>12,'user_id'=>8,'user_pending'=>0)); pm_insert('fixture_pa_auth',array('group_id'=>12,'cat_id'=>5,'auth_email'=>1)); pm_send();
    pm_sql('UPDATE fixture_user_group SET user_pending=1'); pm_denied(function() { pm_send(); }, 'Not_Authorised');
    foreach (array('member','email','ip') as $ban) {
        pm_reset(); pm_insert('fixture_banlist', array('ban_userid'=>$ban === 'member' ? 8 : 0,'ban_email'=>$ban === 'email' ? '*@example.invalid' : '', 'ban_ip'=>$ban === 'ip' ? '7f000001' : '', 'ban_ip_mask'=>'')); pm_denied(function() { pm_send(); }, 'Not_Authorised');
    }
    pm_reset(); $userdata = array('user_id'=>-1,'session_id'=>'fixture-guest','session_logged_in'=>false,'user_level'=>USER,'username'=>'Anonymous','user_email'=>'');
    $_POST = pm_post(array('sid'=>'fixture-guest','submit'=>'Send','sname'=>'Guest Änne','semail'=>'guest@example.invalid','fname'=>'Friend','femail'=>'friend@example.invalid','subject'=>'Guest subject','message'=>'Guest body'));
    pm_sql('UPDATE fixture_pa_cat SET auth_email=' . AUTH_ALL); pm_send(); pm_check($pm_capture[0]['vars']['FROM_USERNAME'] === 'Guest Änne', 'Configured guest can send with actual guest identity');
    pm_denied(function() { pm_send(); }, 'PA_mail_wait'); $_SERVER['REMOTE_ADDR'] = '127.0.0.2'; pm_send(); pm_check(count($pm_capture) === 2, 'Guest quota uses verified per-address identity, not shared anonymous user');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.3'; $_POST['sname'] = 'Other member'; pm_denied(function() { pm_send(); }, 'PA_mail_sender_invalid');
    pm_reset(); $_SERVER['REMOTE_ADDR'] = 'invalid'; pm_denied(function() { pm_send(); });
    pm_reset(); $_SERVER['REMOTE_ADDR'] = '::1'; pm_send(); pm_check(count($pm_capture) === 1, 'Valid IPv6 peer accepted');
    // Network I/O owns only a sender lease, not a transaction or global lock.
    pm_reset(); $pm_transport_hook = function() use ($fixture) {
        $r = $GLOBALS['pm_owner']->inner->sql_query('SELECT @@in_transaction active'); $row = $GLOBALS['pm_owner']->inner->sql_fetchrow($r); $GLOBALS['pm_owner']->inner->sql_freeresult($r);
        pm_check((string)$row['active'] === '0', 'No row transaction held across mail delivery');
        $lock = 'attachment:' . md5($fixture . "\0" . ATTACHMENTS_TABLE); $rows = pm_rows("SELECT GET_LOCK('" . $lock . "',0) held"); pm_check((string)$rows[0]['held'] === '1', 'Global attachment mutex released during SMTP');
        pm_rows("SELECT RELEASE_LOCK('" . $lock . "') released");
        pm_denied(function() { pm_send(); }, 'PA_mail_busy');
        $GLOBALS['pm_transport_hook'] = null;
    }; pm_send(); pm_check(count($pm_capture) === 1, 'Parallel duplicate transport is blocked');
    pm_reset(); $pm_transport_hook = function() {
        $GLOBALS['pm_transport_hook'] = null;
        $previous_user = $GLOBALS['userdata']; $previous_post = $_POST;
        $GLOBALS['userdata'] = array('user_id'=>9,'user_level'=>USER,'session_id'=>'fixture-other','session_logged_in'=>true,'username'=>'Other member','user_email'=>'member9@example.invalid');
        $_POST['sid'] = 'fixture-other';
        try { pm_send(); } finally { $GLOBALS['userdata'] = $previous_user; $_POST = $previous_post; }
    }; pm_send(); pm_check(count($pm_capture) === 2, 'Another sender can use the module during transport');
    foreach (array('fail','throw') as $failure) {
        pm_reset(); $pm_transport = $failure; pm_denied(function() { pm_send(); }, 'PA_mail_failed'); $quota = pm_rows('SELECT * FROM fixture_ctracker_rate_limits');
        pm_check(count($quota) === 1 && (int)$quota[0]['updated_at'] === 0 && (int)$quota[0]['window_start'] >= time()-2, 'Failed transport restores long success quota but retains recent attempt');
        pm_denied(function() { pm_send(); }, 'PA_mail_wait'); pm_check(count($pm_capture) === 1, 'Failed transport cannot be retried in a tight loop');
        pm_sql('UPDATE fixture_ctracker_rate_limits SET window_start=' . (time()-61)); pm_sql('UPDATE fixture_users SET user_emailtime=0 WHERE user_id=8'); $pm_transport = 'accept'; pm_send();
        pm_check(count($pm_capture) === 2, 'Retry after ordinary flood interval is not penalized by long success cooldown');
    }
    pm_reset(); pm_sql('UPDATE fixture_users SET user_emailtime=' . time() . ' WHERE user_id=8'); pm_denied(function() { pm_send(); }, 'PA_mail_wait');
    pm_reset(); pm_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='flood_interval'"); $hash = hash('sha256', "mail-user\0user:8");
    pm_insert('fixture_ctracker_rate_limits',array('bucket_hash'=>$hash,'window_start'=>time()-61,'request_count'=>2,'updated_at'=>time()-61)); pm_denied(function() { pm_send(); }, 'PA_mail_wait');
    pm_sql("UPDATE fixture_ctracker_config SET ct_config_value='0' WHERE ct_config_name='massmail_protection'"); pm_send(); pm_check(count($pm_capture) === 1, 'Current CrackerTracker setting determines long cooldown');
    pm_reset(); $pm_fail_sql = 'INSERT INTO fixture_ctracker_rate_limits'; pm_denied(function() { pm_send(); }); pm_check(count($pm_capture) === 0 && !pm_rows('SELECT * FROM fixture_ctracker_rate_limits'), 'Quota failure cannot send'); $pm_fail_sql = ''; pm_send();
    // A transport acceptance is final from the user's perspective even when
    // its later database acknowledgement fails. Never encourage a resend.
    pm_reset(); $pm_transport_hook = function() { $GLOBALS['pm_fail_sql'] = 'UPDATE fixture_ctracker_rate_limits'; };
    pm_send(); $pm_fail_sql = ''; $pm_transport_hook = null;
    pm_check(count($pm_capture) === 1 && (int)pm_rows('SELECT updated_at FROM fixture_ctracker_rate_limits')[0]['updated_at'] > time(), 'Accepted mail with failed finalization retains conservative pending claim');
    pm_denied(function() { pm_send(); }, 'PA_mail_wait'); pm_check(count($pm_capture) === 1, 'Uncertain acknowledgement cannot replay accepted transport');
    pm_reset(); $pm_transport_hook = function() {
        pm_sql('UPDATE fixture_ctracker_rate_limits SET request_count=request_count+1,updated_at=12345');
        pm_sql('UPDATE fixture_users SET user_emailtime=12345 WHERE user_id=8');
    }; pm_send();
    pm_check((int)pm_rows('SELECT updated_at FROM fixture_ctracker_rate_limits')[0]['updated_at'] === 12345 && (int)pm_rows('SELECT user_emailtime FROM fixture_users WHERE user_id=8')[0]['user_emailtime'] === 12345, 'Finalization fence never overwrites a different claim or account timestamp');
    pm_reset(); $pm_fail_sql = 'COMMIT'; pm_denied(function() { pm_send(); }); $pm_fail_sql = '';
    pm_check(count($pm_capture) === 0 && !pm_rows('SELECT * FROM fixture_ctracker_rate_limits'), 'Uncommitted reservation cannot reach transport');
    pm_reset(); $pm_borrowed = $db; pm_denied(function() { pm_send(); }); unset($pm_borrowed); pm_check($db->sql_query('SELECT 1') !== false, 'Caller connection cannot be borrowed or closed');
    pm_reset(); $db->sql_query('START TRANSACTION'); $db->sql_query("UPDATE fixture_users SET username='Caller transaction' WHERE user_id=9"); pm_send(); $db->sql_query('ROLLBACK');
    pm_check(pm_rows('SELECT username FROM fixture_users WHERE user_id=9')[0]['username'] === 'Other member', 'Caller-owned transaction survives send untouched');
    pm_reset(); pm_sql('ALTER TABLE fixture_ctracker_rate_limits ENGINE=MyISAM'); pm_denied(function() { pm_send(); }, 'PA_mail_upgrade'); pm_check(count($pm_capture) === 0, 'Nontransactional storage fails closed with upgrade guidance'); pm_sql('ALTER TABLE fixture_ctracker_rate_limits ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
    // Form errors keep an escaped editable draft; the real template uses it.
    pm_reset(); $raw = '</textarea><script>fixture()</script> Änne \\ Pfad'; $_POST = pm_post(array('sid'=>'fixture-member','submit'=>'Send','fname'=>'Friend','femail'=>'bad','subject'=>'Draft','message'=>$raw));
    $controller = new PaMailController(); $controller->init(); pm_controller($controller);
    pm_check($pm_displayed === 'pa_email_body.tpl' && count($pm_capture) === 0 && isset($pafiledb_template->blocks['mail_error']), 'Invalid form redisplays without transport');
    pm_check(html_entity_decode($pafiledb_template->data['MESSAGE'], ENT_QUOTES, 'UTF-8') === $raw && strpos($pafiledb_template->data['MESSAGE'], '<script>') === false, 'Editable draft retained and escaped');
    $theme = array('template_name'=>'fisubsilversh');
    $board_config['xs_use_cache'] = $board_config['xs_auto_compile'] = $board_config['xs_auto_recompile'] = 0;
    $template = (new ReflectionClass('Template'))->newInstanceWithoutConstructor(); $template->vars =& $template->_tpldata['.'][0];
    $template->load_config($phpbb_root_path . 'templates/fisubsilversh', false); $template->set_filenames(array('body'=>'pa_email_body.tpl'));
    $template->assign_vars($pafiledb_template->data);
    foreach ($pafiledb_template->blocks as $block=>$rows) { foreach ($rows as $row) { $template->assign_block_vars($block, $row); } }
    ob_start(); try { $template->pparse('body'); $html = ob_get_contents(); } finally { ob_end_clean(); }
    pm_check(strpos($html, 'name="femail" value="bad"') !== false && strpos($html, 'name="subject" value="Draft"') !== false, 'Actual Extreme Styles form retains recipient and subject');
    pm_check(strpos($html, '&lt;/textarea&gt;&lt;script&gt;') !== false && strpos($html, '<script>fixture()') === false && strpos($html, 'role="alert"') !== false, 'Actual rendered textarea escapes draft and displays feedback');
    pm_check(strpos($html, 'name="sname"') === false && strpos($html, 'Sender &amp; Änne') !== false, 'Member template displays account sender without requiring posted name');
    // All three entry points must share the same lease, not only timestamps.
    pm_reset(); $pm_transport_hook = function() {
        $GLOBALS['pm_transport_hook'] = null; $previous = $_POST;
        $_POST = pm_post(array('submit'=>'Send','sid'=>'fixture-member','friendemail'=>'friend@example.invalid','friendname'=>'Friend','topic'=>'Topic','message'=>'Body'));
        $caught = null; $controller = new PaMailController();
        try { phpbb_send_board_mail($GLOBALS['db'],'friend',0,$_POST,array($controller,'create_mailer')); }
        catch (PhpbbMailFailure $error) { $caught = $error->getMessage(); }
        finally { $_POST = $previous; }
        pm_check($caught === 'Mail_busy', 'Active download mail lease blocks overlapping core recommendation');
    }; pm_send(); pm_check(count($pm_capture) === 1, 'Download to core overlap cannot double-send');
    pm_reset(); $pa_post = $_POST; $_POST = pm_post(array('submit'=>'Send','sid'=>'fixture-member','friendemail'=>'friend@example.invalid','friendname'=>'Friend','topic'=>'Topic','message'=>'Body'));
    $pm_transport_hook = function() use ($pa_post) { $GLOBALS['pm_transport_hook'] = null; $previous = $_POST; $_POST = $pa_post; try { pm_denied(function() { pm_send(); },'PA_mail_busy'); } finally { $_POST = $previous; } };
    $controller = new PaMailController(); phpbb_send_board_mail($db,'friend',0,$_POST,array($controller,'create_mailer'));
    pm_check(count($pm_capture) === 1, 'Core to download overlap shares the same lease');
    $_POST = $pa_post; pm_denied(function() { pm_send(); },'PA_mail_wait');
    echo 'Native paFileDB mail: ', $pm_cases, ' cases PASS (', defined('PHPBB_LEGACY_REQUEST_ESCAPED') ? 'legacy' : 'raw', "); real mail sent: 0; own disposable schema only.\n";
} finally {
    unset($GLOBALS['pm_borrowed']); foreach (array($peer,$db) as $connection) { if ($connection) { $connection->sql_close(); } }
    if (!preg_match('/^codex_pa_mail_[a-f0-9]{16}$/D', $fixture)) { throw new RuntimeException('Owned mail schema required'); }
    if ($created) { $control->sql_query('DROP DATABASE ' . $fixture); } $control->sql_close();
}
