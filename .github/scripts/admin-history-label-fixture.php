<?php
require __DIR__ . '/admin-account-label-fixture.php';
$ah_cases = 0;
function ah_locale($locale) {
    al_locale($locale); global $lang, $phpEx;
    require $GLOBALS['al_root'] . 'language/lang_' . $locale . '/lang_dbmtnc.php';
}
function ah_expression($file, $variable) {
    $needle = $variable === 'rowset' ? '$rowset[$i][\'username\']' : '$' . $variable . '[\'username\']';
    al_check(preg_match('/(?:htmlspecialchars|phpbb_stored_text)\(' . preg_quote($needle, '/') . '[^\r\n)]*\)/', al_source($file), $m) === 1,
        'Actual account output expression: ' . $file . ':' . $variable);
    return $m[0];
}
function ah_reports($record, $raw) {
    global $lang;
    $row = $user_sync_row = $role_user = $record; $out = array();
    foreach (array('row','user_sync_row','role_user') as $variable) {
        al_check(preg_match('/^\s*echo\([^\r\n]*\$' . $variable . '\[\'username\'\][^\r\n]*;/m', al_source('admin/admin_db_maintenance.php'), $m) === 1,
            'Actual maintenance report statement');
        ob_start(); try { eval($m[0]); } finally { $out[] = ob_get_clean(); }
    }
    al_check(preg_match('/^\s*<li><\?php echo [^\r\n]+\$row\[\'username\'\][^\r\n]+$/m', al_source('admin/erc.php'), $m) === 1,
        'Actual recovery report markup');
    ob_start(); try { eval('?>' . $m[0]); } finally { $out[] = ob_get_clean(); }
    $id = (int)$record['user_id']; $count = (int)$record['user_posts'];
    $expected = array($raw . ' (' . $id . ')', sprintf($lang['Maintenance_user_counter_changed'], $raw, $id, $count),
        sprintf($lang['Changing_moderator_status'], $raw, $id), $raw);
    foreach ($out as $n=>$html) {
        al_check(al_visible(trim(strip_tags($html))) === $expected[$n], 'Actual report preserves raw name and numeric account details');
        al_check(substr_count($html, '<li>') === 1 && substr_count($html, '</li>') === 1, 'Name cannot introduce report markup');
        $GLOBALS['ah_cases']++;
    }
}
function ah_style_template() {
    $t = al_template('logs_body.tpl');
    $t->set_filenames(array('body'=>$GLOBALS['al_root'] . 'xs_mod/tpl/styles.tpl')); return $t;
}
function ah_templates($key, $raw) {
    $row = array('username'=>$key); $rowset = array($row); $i = 0;
    $html_name = eval('return ' . ah_expression('admin/admin_logs.php', 'row') . ';');
    $t = al_template('logs_body.tpl');
    $t->assign_block_vars('logrow', array('USERNAME'=>$html_name, 'U_USER'=>'admin_users.php?mode=edit&amp;u=7'));
    $html = al_html($t);
    al_check(preg_match('/href="admin_users.php\?mode=edit&amp;u=7">([^<]*)<\/a>/', $html, $m) === 1 && al_visible($m[1]) === $raw,
        'Real log template preserves the snapshot name and original numeric account link'); $GLOBALS['ah_cases']++;
    $html_name = eval('return ' . ah_expression('admin/xs_styles.php','rowset') . ';');
    $t = ah_style_template(); $t->assign_block_vars('list_users', array());
    $t->assign_block_vars('list_users.user', array('NUM'=>1,'ID'=>7,'NAME'=>$html_name)); $html = al_html($t);
    al_check(strpos($html, '<td>[7]</td>') !== false && strpos($html, '<td>' . $html_name . '</td>') !== false
        && al_visible($html_name) === $raw, 'Real nested XS template shows the correct identity beside its numeric ID'); $GLOBALS['ah_cases']++;
}
function ah_suite() {
    foreach (array('english','german') as $locale) { ah_locale($locale);
        foreach (al_names() as $raw) { foreach (array(ENT_QUOTES,ENT_COMPAT) as $flags) {
            $key = phpbb_username_key($raw,$flags); al_check($key !== '', 'Canonical identity fixture');
            ah_reports(array('username'=>$key,'user_id'=>7,'user_posts'=>12), $raw); ah_templates($key,$raw);
        }}
    }
}
