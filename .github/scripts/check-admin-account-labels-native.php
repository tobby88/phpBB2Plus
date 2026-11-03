<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_ADMIN_LABELS_NATIVE') !== '1') {
    echo "Admin account label checks require an explicitly enabled disposable database.\n"; return;
}
require __DIR__ . '/admin-account-label-fixture.php';
require $al_root . 'db/mysqli.php';
class AdminLabelNativeDatabase extends sql_db {
    var $setup = true; var $fail = false;
    function sql_query($sql = '', $transaction = false) {
        if (!$this->setup && !preg_match('/^SELECT\b/i', ltrim($sql))) { throw new RuntimeException('Admin display must be read-only'); }
        if (!$this->setup && $this->fail) { return false; }
        return parent::sql_query($sql, $transaction);
    }
}
function aln_sql($sql) {
    $r = $GLOBALS['db']->sql_query($sql);
    if ($r === false) { $error = $GLOBALS['db']->sql_error(); throw new RuntimeException('Owned admin-label SQL: ' . $error['message']); }
    return $r;
}
function aln_change($sql) { $GLOBALS['db']->setup = true; try { return aln_sql($sql); } finally { $GLOBALS['db']->setup = false; } }
function aln_snapshot() {
    $r = aln_sql('SELECT * FROM fixture_users ORDER BY user_id');
    $rows = $GLOBALS['db']->sql_fetchrowset($r); $GLOBALS['db']->sql_freeresult($r); return serialize($rows);
}
function aln_route($route, $raw = '') {
    global $template, $db, $lang, $theme, $board_config, $userdata, $phpEx;
    $userdata = array('user_id'=>2, 'session_logged_in'=>true, 'session_id'=>'synthetic-admin-label');
    $files = array('account'=>'admin_account_body.tpl','ip'=>'user_ip_list.tpl','color'=>'color_groups_user_list.tpl','banner'=>'banner_edit_body.tpl');
    $template = al_template($files[$route]);
    if ($route === 'account') {
        $order_by = 'user_regdate ASC LIMIT 0, 20'; $start = 0;
        eval(al_fragment('admin/admin_account.php', '//output', '$sql = "SELECT * FROM "'));
    } elseif ($route === 'ip') {
        $username_input = $raw; $resolve = false;
        eval(al_fragment('admin/admin_reg_ip.php', '$main = phpbb_username_lookup', '$template->pparse(\'body\');'));
    } elseif ($route === 'color') {
        $group_id = 5; $color_group_list = array(5=>'Raw&amp;Group', 6=>'Other<group>');
        eval(al_fragment('admin/admin_color_groups.php', '$user_list = array();', '$group_list = array();'));
        $template->assign_vars(array('S_USER_LIST_BOX'=>$user_list_box));
    } else {
        $banner_info = array('banner_owner'=>7);
        eval(al_fragment('admin/admin_banner.php', '$owner = get_userdata', '$s_time_week_begin='));
        $template->assign_vars(array('BANNER_OWNER'=>eval('return ' . al_binding('admin/admin_banner.php', 'BANNER_OWNER') . ';')));
    }
    return al_html($template);
}
function aln_verify($route, $raw) {
    $html = aln_route($route, $raw); $expected = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    if ($route === 'account') {
        al_check(preg_match('/<a href="[^\"]*u=7(?:&amp;[^\"]*)?" class="gen">([^<]*)<\/a>/', $html, $m) === 1
            && al_visible($m[1]) === $raw, 'Actual inactive-account SQL keeps the current name on the same numeric profile link');
        al_check(strpos($html, 'ActiveCanary') === false && strpos($html, 'AnonymousCanary') === false, 'Inactive list retains active/anonymous exclusion');
    } elseif ($route === 'ip') {
        al_check(strpos($html, '<th colspan="2">' . $expected . '</th>') !== false, 'Actual identity lookup renders current main account');
        al_check(strpos($html, $expected . '</a>') !== false && strpos($html, 'ActiveCanary') !== false && strpos($html, 'OtherIP') === false, 'Actual shared-IP query includes only matching registration IPs');
        al_check(preg_match('/name="username" value="([^"]*)"/', $html, $m) === 1 && al_visible($m[1]) === $raw, 'Resolve form keeps the same raw lookup identity');
    } elseif ($route === 'color') {
        al_check(preg_match('/<option value="7" selected="true">(.*?)<\/option>/', $html, $m) === 1
            && al_visible($m[1]) === $raw . ' (Raw&amp;Group)', 'Actual group query preserves numeric selection and raw group metadata');
        al_check(strpos($html, 'value="8"') !== false && strpos($html, 'value="-1"') === false, 'Group list retains other accounts and excludes anonymous');
    } else {
        al_check(preg_match('/name="username"[^>]*value="([^"]*)"/', $html, $m) === 1 && al_visible($m[1]) === $raw, 'Actual owner-by-ID lookup and input preserve current name');
    }
    $GLOBALS['al_cases']++;
}
$db = $control = null; $created = false; $schema = 'codex_admin_labels_' . bin2hex(phpbb_random_bytes(8));
try {
    $port = getenv('PHPBB_ADMIN_LABELS_PORT') ?: '3306'; $password = getenv('PHPBB_ADMIN_LABELS_PASSWORD') ?: '';
    al_check(preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port > 0 && (int)$port <= 65535, 'Owned loopback database port');
    $host = '127.0.0.1:' . $port; $control = new sql_db($host, 'root', $password, '', false);
    al_check((bool)$control->db_connect_id, 'Owned server connection');
    al_check(preg_match('/^codex_admin_labels_[a-f0-9]{16}$/D', $schema)
        && $control->sql_query('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Exclusive owned schema'); $created = true;
    $db = new AdminLabelNativeDatabase($host, 'root', $password, $schema, false);
    al_check((bool)$db->db_connect_id, 'Owned schema connection');
    $canonical = file_get_contents($al_root . 'install/schemas/mysql_schema.sql');
    al_check(preg_match('/CREATE TABLE phpbb_users \(.*?\) ENGINE=InnoDB[^;]*;/s', $canonical, $m) === 1, 'Canonical InnoDB schema');
    aln_sql(str_replace('phpbb_users','fixture_users',$m[0]));
    aln_sql("INSERT INTO fixture_users(user_id,username,user_password,user_active,user_reg_ip,user_color_group,user_regdate) VALUES
        (7,'Member','',0,'192.0.2.7',5,1),(8,'ActiveCanary','',1,'192.0.2.7',6,2),
        (9,'OtherIP','',0,'192.0.2.9',6,3),(-1,'AnonymousCanary','',0,'',0,4)"); $db->setup = false;
    foreach (array('english','german') as $locale) { al_locale($locale);
        foreach (al_names() as $raw) { foreach (array(ENT_QUOTES,ENT_COMPAT) as $flags) {
            $key = phpbb_username_key($raw,$flags); al_check($key !== '', 'Canonical stored fixture');
            aln_change("UPDATE fixture_users SET username='" . $db->sql_escape($key) . "' WHERE user_id=7"); $before = aln_snapshot();
            foreach (array('account','ip','color','banner') as $route) { aln_verify($route,$raw); }
            al_check(aln_snapshot() === $before, 'Every actual display route leaves all account fields unchanged');
        }}
    }
    // A removed owner's ID must not silently resolve a new account with its old name.
    aln_change('DELETE FROM fixture_users WHERE user_id=7');
    aln_change("INSERT INTO fixture_users(user_id,username,user_password,user_reg_ip) VALUES(11,'0','','192.0.2.11')");
    $before = aln_snapshot(); $html = aln_route('banner');
    al_check(preg_match('/name="username"[^>]*value=""/', $html) === 1, 'Deleted numeric owner stays empty even when the old name is reused'); $al_cases++;
    foreach (array('account','ip','color','banner') as $route) {
        $db->fail = true; $denied = false;
        try { aln_route($route, '0'); } catch (AdminLabelDenied $e) { $denied = true; } finally { $db->fail = false; }
        al_check($denied, 'Read failure is rejected instead of rendering a different account'); $al_cases++;
    }
    al_check(aln_snapshot() === $before, 'Missing owners and failed reads are read-only');
    echo 'Admin account labels native: ', $al_cases, ' cases, ', $al_checks, " assertions PASS; canonical users, actual four controller query paths and templates, current/deleted IDs, query scopes and unchanged account data.\n";
} finally {
    if ($db) { $db->sql_close(); }
    if ($created) { al_check(preg_match('/^codex_admin_labels_[a-f0-9]{16}$/D',$schema) && $control->sql_query('DROP DATABASE ' . $schema), 'Drop exact owned schema'); }
    if ($control) { $control->sql_close(); } restore_error_handler();
}
