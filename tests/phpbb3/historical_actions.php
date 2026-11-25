<?php
function seed_historical_actions($db)
{
	$db->query("INSERT INTO phpbb_logs (id_log,mode,topic_id,user_id,username,user_ip,log_time) VALUES
		(901,'lock',1,1,'Archivname 📗 &amp; Co','c000022d',1200000901),
		(902,'move',7,2,'FrühererName','2001:0db8:0000:0000:0000:0000:0000:0030',1200000902),
		(903,'archive <note>',990006,1888,'0','unrecorded',0)");
}

function verify_historical_actions($db, $member)
{
	$rows = $db->query("SELECT * FROM p3_log WHERE log_operation LIKE 'LOG_PLUS_ARCHIVE_%'")->fetch_all(MYSQLI_ASSOC);
	$events = array();
	foreach ($rows as $row)
	{
		$data = unserialize($row['log_data'], array('allowed_classes' => false));
		check_fixture(is_array($data) && !isset($events[$data['entry']]) && (int) $row['log_type'] === 0, 'Misclassified or duplicated historical action');
		$events[$data['entry']] = $row + $data;
	}
	check_fixture(count($events) === 3 && (int) $events[901]['user_id'] === (int) $member && (int) $events[901]['topic_id'] === 1
		&& $events[901]['log_ip'] === '192.0.2.45' && (int) $events[901]['log_time'] === 1200000901 && $events[901]['log_operation'] === 'LOG_PLUS_ARCHIVE_LOCK',
		'A moderation action lost its remapped account, topic, address, time or operation');
	check_fixture((int) $events[902]['forum_id'] === 7 && $events[902]['log_ip'] === '2001:db8::30' && $events[902]['name'] === 'FrühererName',
		'A historic action lost its IPv6 address or recorded name');
	check_fixture((int) $events[903]['user_id'] === 1 && (int) $events[903]['topic_id'] === 0 && (int) $events[903]['topic'] === 990006
		&& $events[903]['name'] === '0' && (int) $events[903]['account'] === 1888 && $events[903]['log_operation'] === 'LOG_PLUS_ARCHIVE_OTHER'
		&& $events[903]['action'] === 'archive &lt;note&gt;' && $events[903]['source_ip'] === 'unrecorded' && $events[903]['log_ip'] === '',
		'An unrecognised action was discarded or attached to an unrelated account or topic');
	check_fixture(!$db->query('SELECT source_id FROM p3_plus_action_import LIMIT 1')->fetch_row(), 'Historical log data remained in the import stage');
}
