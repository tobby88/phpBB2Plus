<?php
// Execute the real read controller through its fresh display query. Mutations
// still use the real worker and dedicated lock, not a stubbed read API.
$fixture = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/check-pm-read-recovery.php'));
$end = strpos($fixture, "\nset_error_handler(");
if ($end === false) { throw new RuntimeException('Read fixture boundary missing'); }
eval(substr($fixture, 5, $end - 5));
$controller = str_replace("\r\n", "\n", file_get_contents($root . 'privmsg.php'));
$start = strpos($controller, "\tswitch( \$folder )");
$end = strpos($controller, "\t\$privmsg['user_icq'] =", $start);
pm_repair_check($start !== false && $end > $start, 'Actual folder authorization/read/display branch found');
$read_controller = substr($controller, $start, $end - $start);
$start = strpos($controller, '// Mark complete messages noticed');
$end = strpos($controller, "//\n// Generate page", $start);
pm_repair_check($start !== false && $end > $start, 'Actual mailbox visit branch found');
$visit_controller = substr($controller, $start, $end - $start);
class PmReadControllerRedirect extends RuntimeException {}
function append_sid($url, $unused = false) { return $url; }
function redirect($url) { throw new PmReadControllerRedirect($url); }
class PmReadDisplayForum extends PmRepairForum
{
    function sql_query($sql)
    {
        pm_repair_check(strpos($sql, 'SELECT ') === 0, 'Controller writes only through owning worker');
        $pdo = $GLOBALS['pm_repair_server']->pdo;
        return new PmRepairRows($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
    function sql_fetchrow($result) { return array_shift($result->rows); }
}
function pm_read_controller_fixture($engine)
{
    global $db, $read_controller, $board_config, $userdata;
    pm_read_fixture($engine);
    // Add the actual profile projection fields with inert defaults. Keep the
    // real SQL/WHERE clause intact, including folder and recipient ownership.
    preg_match_all('/\bu2?\.(user_[a-z_]+)\b/', $read_controller, $matches);
    foreach (array_unique($matches[1]) as $field)
    {
        if (in_array($field, array('user_id','user_posts','user_new_privmsg','user_unread_privmsg','user_level','user_active','user_allow_pm'), true))
        {
            if ($field !== 'user_posts') { continue; }
        }
        $GLOBALS['pm_repair_server']->pdo->exec('ALTER TABLE fixture_users ADD COLUMN ' . $field . " VARCHAR(255) DEFAULT ''");
    }
    $db = new PmReadDisplayForum();
    $GLOBALS['pm_repair_server']->pdo->exec('ALTER TABLE fixture_users ADD COLUMN user_last_privmsg INTEGER DEFAULT 0');
    $board_config = array('max_sentbox_privmsgs'=>1);
    $GLOBALS['pm_repair_server']->pdo->exec("INSERT INTO fixture_pm (privmsgs_id,privmsgs_type,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date) VALUES (30,2,1,8,900)");
    $GLOBALS['pm_repair_server']->pdo->exec("INSERT INTO fixture_text (privmsgs_text_id,privmsgs_text) VALUES (30,'Older sent copy')");
}
function pm_read_controller_run($id = 12, $selected_folder = 'inbox')
{
    global $db, $userdata, $board_config, $lang, $phpEx, $read_controller;
    $privmsgs_id = $id; $folder = $selected_folder;
    eval($read_controller);
    return $privmsg;
}
function pm_visit_controller_run()
{
    global $db, $userdata, $visit_controller;
    eval($visit_controller);
}
function pm_compose_source($kind)
{
    global $controller, $userdata, $db;
    $marker = $kind === 'edit' ? 'SELECT pm.*, pmt.privmsgs_bbcode_uid' : 'SELECT pm.privmsgs_subject, pm.privmsgs_date, pmt.privmsgs_bbcode_uid';
    $start = strpos($controller, '$sql = "' . $marker);
    $end = strpos($controller, "\n\t\t\tif ( !(\$result", $start);
    pm_repair_check($start !== false && $end > $start, 'Actual compose source query found');
    $privmsg_id = 12;
    eval(substr($controller, $start, $end - $start));
    return $db->sql_fetchrow($db->sql_query($sql));
}
set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
    foreach ($native ? array('MyISAM','InnoDB') : array('SQLite') as $engine)
    {
        foreach (array('english','german') as $locale)
        {
            include $root . 'language/lang_' . $locale . '/lang_main.php';
            foreach (array('INSERT INTO fixture_pm ', 'INSERT INTO fixture_text ', 'INSERT INTO fixture_links ', 'success') as $failure)
            {
                pm_read_controller_fixture($engine);
                if ($failure !== 'success')
                {
                    $pm_repair_server->failure = $failure;
                    mailbox_storage_fail(function() { pm_read_controller_run(); }, $lang['PM_cleanup_failed']);
                    pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=30') === 1, 'Controller failure preserves older sent message');
                    $pm_repair_server->failure = '';
                }
                $display = pm_read_controller_run(); pm_read_controller_run(); pm_read_assert_complete();
                pm_repair_check((int)$display['privmsgs_type'] === PRIVMSGS_READ_MAIL, 'Displayed row is fetched after read transition');
                pm_repair_check($display['privmsgs_text'] === 'Überraschung: Grüße & <b>Test</b>', 'Displayed body remains exact UTF-8');
                pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_id=30') === 0, 'Controller quota preserves newer copy with older source date');
            }
            foreach (array('foreign','pending','outbox') as $case)
            {
                pm_read_controller_fixture($engine);
                if ($case !== 'pending') { $userdata['user_id'] = 1; }
                else { $pm_repair_server->pdo->exec("UPDATE fixture_pm SET privmsgs_write_payload='pending' WHERE privmsgs_id=12"); }
                $before = pm_repair_snapshot('fixture_pm');
                if ($case === 'pending') { mailbox_storage_fail(function() { pm_read_controller_run(); }, $lang['PM_write_pending']); }
                elseif ($case === 'outbox')
                {
                    $display = pm_read_controller_run(12, 'outbox');
                    pm_repair_check((int)$display['privmsgs_type'] === PRIVMSGS_NEW_MAIL, 'Author preview does not mark recipient message read');
                }
                else
                {
                    $redirected = false;
                    try { pm_read_controller_run(); } catch (PmReadControllerRedirect $error) { $redirected = true; }
                    pm_repair_check($redirected, 'Foreign inbox request redirects without rendering');
                }
                pm_repair_check(pm_repair_snapshot('fixture_pm') === $before, 'No parent changes for ' . $case);
            }
            foreach (array('UPDATE fixture_pm SET privmsgs_type = 5', 'UPDATE fixture_users SET', 'success') as $boundary)
            {
                foreach (array(false, true) as $lost_ack)
                {
                    pm_read_controller_fixture($engine); $userdata['session_start'] = 500;
                    $pm_repair_server->pdo->exec("UPDATE fixture_pm SET privmsgs_write_payload='unfinished writer' WHERE privmsgs_id=10");
                    $pm_repair_server->pdo->exec('UPDATE fixture_users SET user_last_privmsg=900 WHERE user_id=8');
                    if ($boundary !== 'success')
                    {
                        if ($lost_ack)
                        {
                            $pm_repair_server->hook = function($sql) use ($boundary)
                            {
                                if (strpos($sql, $boundary) !== 0) { return; }
                                $s = $GLOBALS['pm_repair_server']; $s->hook = null; $s->pdo->exec($sql); $s->failure = $boundary;
                            };
                        }
                        else { $pm_repair_server->failure = $boundary; }
                        mailbox_storage_fail(function() { pm_visit_controller_run(); }, $lang['PM_cleanup_failed']);
                        $pm_repair_server->failure = ''; $pm_repair_server->hook = null;
                    }
                    pm_visit_controller_run(); pm_visit_controller_run();
                    pm_repair_check((int)$userdata['user_new_privmsg'] === 0 && (int)$userdata['user_unread_privmsg'] === 3, 'Post-visit display counters derive from completed rows, not stale 99/99');
                    pm_repair_check((int)$userdata['user_last_privmsg'] === 900, 'Older session start cannot lower last message time');
                    pm_repair_check(pm_repair_value('SELECT privmsgs_type FROM fixture_pm WHERE privmsgs_id=10') === PRIVMSGS_NEW_MAIL, 'Pending publication is neither noticed nor counted');
                    pm_repair_check(pm_repair_value('SELECT COUNT(*) FROM fixture_pm WHERE privmsgs_copy_token IS NOT NULL') === 0, 'Mailbox visit alone does not mark messages read or create copies');
                }
            }
            foreach (array('edit','quote') as $compose_mode)
            {
                pm_read_controller_fixture($engine); $userdata['user_id'] = $compose_mode === 'edit' ? 1 : 8;
                pm_repair_check((bool)pm_compose_source($compose_mode), 'Completed message can enter ' . $compose_mode);
                $pm_repair_server->pdo->exec("UPDATE fixture_pm SET privmsgs_write_payload='pending edit' WHERE privmsgs_id=12");
                pm_repair_check(!pm_compose_source($compose_mode), 'Partial content cannot enter ' . $compose_mode);
                $pm_repair_server->pdo->exec('UPDATE fixture_pm SET privmsgs_write_payload=NULL,privmsgs_type=6 WHERE privmsgs_id=12');
                pm_repair_check(!pm_compose_source($compose_mode), 'Never-published staging copy cannot enter ' . $compose_mode);
            }
            echo $engine . ' ' . $locale . " actual PM read/visit/compose controller checks passed.\n";
        }
    }
}
finally
{
    if (isset($pm_repair_server) && $pm_repair_server->owner !== null) { $pm_repair_server->owner->sql_close(); }
    restore_error_handler();
}
