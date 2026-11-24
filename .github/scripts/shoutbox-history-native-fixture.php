<?php
// Exercise the production page and compact query on the existing disposable schema.
class ShoutHistoryConcurrentReader extends sql_db
{
	var $after_count;
	function sql_query($sql = '', $transaction = false)
	{
		$result = parent::sql_query($sql, $transaction);
		if ($result && $this->after_count && strpos($sql, 'SELECT COUNT(*) AS total') === 0)
		{
			$write = $this->after_count;
			$this->after_count = null;
			$write();
		}
		return $result;
	}
}
function sn_page_ids($page) { return array_map('intval', array_column($page['rows'], 'shout_id')); }

require_once __DIR__ . '/uacp-list-native-fixture.php';
sx_check(preg_match('/^CREATE TABLE phpbb_users\s*\([\s\S]*?;/m', $schema, $ddl) === 1, 'Canonical users table');
sn_sql(str_replace('phpbb_users', 'fixture_users', $ddl[0]));
uacp_list_fixture_insert($db, 'fixture_users', array('user_id' => -1, 'username' => 'Anonymous'));
uacp_list_fixture_insert($db, 'fixture_users', array('user_id' => 4, 'username' => 'Member'));
sn_sql('DELETE FROM fixture_shout');
$empty = phpbb_shoutbox_history_page($db, 24, 0, 0);
sx_check($empty === array('total'=>0, 'start'=>0, 'rows'=>array()), 'Empty history starts at zero');
sn_sql("INSERT INTO fixture_shout(shout_id,shout_user_id,shout_session_time,shout_text) VALUES
	(10,4,100,'ten'),(11,-1,100,'eleven'),(12,4,90,'twelve'),(13,4,80,'thirteen'),
	(14,4,70,'fourteen'),(99,900,200,'unmatched account')");
$db->history_reader = function () use ($host, $password, $fixture) {
	$reader = new ShoutHistoryConcurrentReader($host, 'root', $password, $fixture, false);
	$reader->after_count = function () {
		sn_sql("INSERT INTO fixture_shout(shout_id,shout_user_id,shout_session_time,shout_text) VALUES (15,4,110,'fifteen')");
	};
	return $reader;
};
$first = phpbb_shoutbox_history_page($db, 0, 2, 0);
$db->history_reader = null;
sx_check($first['total'] === 5 && sn_page_ids($first) === array(11,10),
	'Count and rows use one snapshot when another session inserts, with a stable order for tied timestamps');
$linked = phpbb_shoutbox_history_page($db, 0, 2, 10);
sx_check($linked['total'] === 6 && $linked['start'] === 2 && sn_page_ids($linked) === array(10,12),
	'An existing entry link finds its current page after a new shout');
$last = phpbb_shoutbox_history_page($db, 999, 2, 0);
sx_check($last['start'] === 4 && sn_page_ids($last) === array(13,14), 'Stale offset resolves to the last visible page');
sx_check(phpbb_shoutbox_history_page($db, 0, 2, 99) === false
	&& phpbb_shoutbox_history_page($db, 0, 2, 77) === false, 'Unlisted and absent entries have no public target');
$compact = sn_sql(phpbb_shoutbox_rows_sql(1, 2, true));
try { sx_check(array_column($db->sql_fetchrowset($compact), 'shout_text') === array('eleven','ten'),
	'Compact view uses the same order and visible rows'); }
finally { $db->sql_freeresult($compact); }
sx_check(!array_key_exists('user_password', $linked['rows'][0]) && !array_key_exists('user_email', $linked['rows'][0]),
	'History selects just the fields used to render entries');
sn_sql("ALTER TABLE fixture_users ADD user_avatar_url varchar(128) NOT NULL DEFAULT ''");
sn_sql("UPDATE fixture_users SET user_avatar_url='https://example.org/avatar' WHERE user_id=4");
$legacy = phpbb_shoutbox_history_page($db, 0, 2, 10);
sx_check($legacy['rows'][0]['user_avatar_url'] === 'https://example.org/avatar', 'Optional legacy avatar links still display');
