<?php
function su_notification_cases()
{
    global $as_app, $phpbb_root_path, $user_ip, $userdata, $board_config, $cases;
    su_reset(array('games_use_pms' => 1));
    $schema = file_get_contents($as_app.'install/schemas/mysql_schema.sql');
    foreach (array(CONFIG_TABLE, PRIVMSGS_TABLE, PRIVMSGS_TEXT_TABLE) as $table) {
        $original = 'phpbb_'.substr($table, strlen('fixture_'));
        as_check(preg_match('/CREATE TABLE '.preg_quote($original, '/').' \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $ddl) === 1, 'Canonical inbox fixture '.$table);
        ag_sql(str_replace($original, $table, $ddl[0]));
    }
    ag_sql("INSERT INTO fixture_config(config_name,config_value) VALUES ('privmsg_disable','0'),('max_inbox_privmsgs','2'),('allow_bbcode','1'),('allow_smilies','1')");
    ag_sql("UPDATE fixture_users SET games_block_pm=0,user_notify_pm=0,user_new_privmsg=7,user_unread_privmsg=9 WHERE user_id=8");
    ag_sql("INSERT INTO fixture_privmsgs(privmsgs_id,privmsgs_type,privmsgs_subject,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date,privmsgs_ip) VALUES(100,".PRIVMSGS_UNREAD_MAIL.",'Personal message',2,8,1,'7f000001')");
    ag_sql("INSERT INTO fixture_privmsgs_text(privmsgs_text_id,privmsgs_text) VALUES(100,'Existing body')");
    $messages = 'SELECT p.*,t.privmsgs_bbcode_uid,t.privmsgs_text FROM fixture_privmsgs p JOIN fixture_privmsgs_text t ON t.privmsgs_text_id=p.privmsgs_id ORDER BY p.privmsgs_id';
    $counters = 'SELECT user_new_privmsg,user_unread_privmsg,user_last_privmsg FROM fixture_users WHERE user_id=8';
    $original = su_rows($messages);
    $root = $phpbb_root_path;
    $phpbb_root_path = $as_app;
    $user_ip = '7f000001';
    $board_config['privmsg_disable'] = 0;
    $userdata['games_block_pm'] = 1;
    try {
        $board_config['cookie_secure'] = 1;
        $board_config['server_port'] = 8443;
        $board_config['script_path'] = 'jahrgang/forum';
        foreach (array('german', 'english') as $locale) {
            as_language($locale);
            foreach (array(false, true) as $all_time) {
                $formatted = phpbb_arcade_highscore_notice($all_time, array('game_id' => 41, 'game_desc' => 'Spiel 😀'));
                as_check(strpos($formatted, '[url=https://forum.invalid:8443/jahrgang/forum/activity.php?mode=game&id=41&win=self]Spiel 😀[/url]') !== false, 'Localized record notices link to the configured HTTPS board and exact game');
            }
        }
        $subject = "Grüße aus O'Briens Spielhalle \\ Archiv 😀";
        ina_send_user_pm(8, $subject, "[b]Grüße[/b] aus O'Briens Spielhalle & mehr 😀", 2, 'YES', true);
        $rows = su_rows($messages);
        as_check(count($rows) === 2 && $rows[0] === $original[0], 'Opted-in recipient gets a notice while existing personal mail survives');
        $notice = $rows[1];
        $uid = $notice['privmsgs_bbcode_uid'];
        as_check($notice['privmsgs_subject'] === $subject && $uid !== '' && $notice['privmsgs_text'] === '[b:'.$uid.']Grüße[/b:'.$uid.'] aus O&#039;Briens Spielhalle &amp; mehr 😀', 'Quotes, backslashes, Unicode and formatted notification text round-trip');
        as_check((int)$notice['privmsgs_from_userid'] === 2 && (int)$notice['privmsgs_to_userid'] === 8 && (int)$notice['privmsgs_type'] === PRIVMSGS_NEW_MAIL && (int)$notice['privmsgs_enable_bbcode'] === 1 && (int)$notice['privmsgs_enable_html'] === 0 && (int)$notice['privmsgs_enable_smilies'] === 1, 'Stored delivery options match current board policy');
        $counts = su_rows($counters);
        as_check((int)$counts[0]['user_new_privmsg'] === 1 && (int)$counts[0]['user_unread_privmsg'] === 1 && (int)$counts[0]['user_last_privmsg'] === (int)$notice['privmsgs_date'], 'Both inbox counters and last delivery agree with durable mail');
        ina_send_user_pm(8, 'Inbox full', 'Skipped', 2, 'YES', true);
        as_check(su_rows($messages) === $rows && su_rows($counters) === $counts, 'Full inbox preserves both complete messages and counters');
        $cases += 2;

        ag_sql("UPDATE fixture_config SET config_value='3' WHERE config_name='max_inbox_privmsgs'");
        foreach (array(
            array('UPDATE fixture_users SET games_block_pm=1 WHERE user_id=8', 'UPDATE fixture_users SET games_block_pm=0 WHERE user_id=8'),
            array('UPDATE fixture_users SET user_active=0 WHERE user_id=8', 'UPDATE fixture_users SET user_active=1 WHERE user_id=8'),
            array('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=2', 'UPDATE fixture_users SET user_allow_pm=1 WHERE user_id=2'),
            array("UPDATE fixture_config SET config_value='1' WHERE config_name='privmsg_disable'", "UPDATE fixture_config SET config_value='0' WHERE config_name='privmsg_disable'"),
            array("UPDATE fixture_ina_data SET config_value='0' WHERE config_name='games_use_pms'", "UPDATE fixture_ina_data SET config_value='1' WHERE config_name='games_use_pms'")
        ) as $change) {
            ag_sql($change[0]);
            ina_send_user_pm(8, 'Disabled', 'Skipped', 2, 'YES', true);
            as_check(su_rows($messages) === $rows && su_rows($counters) === $counts, 'Current sender, recipient and board settings apply with spare capacity');
            ag_sql($change[1]);
            $cases++;
        }
        ag_sql("CREATE TRIGGER fixture_notice_body_failure BEFORE INSERT ON fixture_privmsgs_text FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Disposable body failure'");
        $failed = false;
        try { ina_send_user_pm(8, 'Interrupted', 'Failed body', 2, 'YES', true); }
        catch (RuntimeException $error) { $failed = $error->getMessage() === 'Arcade notification unavailable'; }
        as_check($failed && count(su_rows('SELECT privmsgs_id FROM fixture_privmsgs')) === 2 && su_rows($messages) === $rows && su_rows($counters) === $counts, 'A body write failure rolls back its header and preserves existing mail and counters');
        ag_sql('DROP TRIGGER fixture_notice_body_failure');
        ag_sql("UPDATE fixture_config SET config_value='0' WHERE config_name IN ('allow_bbcode','allow_smilies')");
        ina_send_user_pm(8, 'System notice', '[b]plain[/b]', -1, 'YES', true);
        $rows = su_rows($messages);
        as_check(count($rows) === 3 && (int)$rows[2]['privmsgs_from_userid'] === -1 && $rows[2]['privmsgs_bbcode_uid'] === '' && $rows[2]['privmsgs_text'] === '[b]plain[/b]' && (int)$rows[2]['privmsgs_enable_smilies'] === 0, 'System delivery retries cleanly and respects disabled formatting');
        $cases += 2;
    } finally {
        $phpbb_root_path = $root;
    }
}
