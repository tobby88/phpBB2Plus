<?php
// Included by the guarded post_references.php fixture on its already verified loopback database.
if (!defined('IN_PHPBB') || PHP_SAPI !== 'cli' || !isset($rows, $state, $argv[2]) || $dbhost !== '127.0.0.1' ||
	$dbname !== $argv[1] || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $dbname)) { exit(2); }
$n = 640000; $missing = $n+999; $end = $n+204; $key = 'test_plus_account_references';
$group = (int) $rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_type=" . GROUP_SPECIAL . " AND group_name='REGISTERED'")[0]['group_id'];
$scope = array(USERS_TABLE=>"user_id BETWEEN $n AND $end", USER_GROUP_TABLE=>"(user_id BETWEEN $n AND $end OR user_id=$missing)",
	PRIVMSGS_TABLE=>"msg_id BETWEEN $n AND " . ($n+6), PRIVMSGS_TO_TABLE=>"msg_id BETWEEN $n AND " . ($n+6),
	PRIVMSGS_FOLDER_TABLE=>"folder_id BETWEEN $n AND " . ($n+3));
$signature = static function () use ($rows, $scope) {
	$sort = array(USERS_TABLE=>'user_id', USER_GROUP_TABLE=>'user_id,group_id,user_pending,group_leader', PRIVMSGS_TABLE=>'msg_id',
		PRIVMSGS_TO_TABLE=>'msg_id,user_id,folder_id,author_id,pm_deleted', PRIVMSGS_FOLDER_TABLE=>'folder_id');
	$digest = hash_init('sha256');
	foreach ($scope as $table=>$where)
	{
		foreach ($rows("SELECT * FROM $table WHERE $where ORDER BY " . $sort[$table]) as $row) { hash_update($digest, serialize($row)); }
	}
	return hash_final($digest);
};
$delivery = static function ($message, $owner, $folder, $sender) { return array('msg_id'=>$message, 'user_id'=>$owner,
	'folder_id'=>$folder, 'author_id'=>$sender, 'pm_deleted'=>0, 'pm_new'=>0,'pm_unread'=>0); };
if ($argv[2] === 'account-seed')
{
	if ($state->get($key) !== null || !$group || !$rows('SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id=71'))
	{ throw new RuntimeException('Unexpected initial account reference fixture'); }
	foreach ($scope as $table=>$where) { if ($rows("SELECT 1 FROM $table WHERE $where", 1)) { throw new RuntimeException('Reserved reference IDs are in use'); } }
	foreach (array(USERS_TABLE=>'user_id',GROUPS_TABLE=>'group_id',PRIVMSGS_FOLDER_TABLE=>'folder_id') as $table=>$id)
	{ if ($rows("SELECT $id FROM $table WHERE $id=$missing", 1)) { throw new RuntimeException('Missing reference target is occupied'); } }
	$account = $rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=87')[0];
	$message = $rows('SELECT * FROM ' . PRIVMSGS_TABLE . ' ORDER BY msg_id', 1)[0];
	$db->sql_transaction('begin');
	$users = $members = $copies = array();
	for ($i=0; $i<=204; $i++)
	{
		$id = $n+$i; $name = 'HiddenRefOwner' . $i;
		$users[] = array_merge($account, array('user_id'=>$id, 'username'=>$name, 'username_clean'=>strtolower($name),
			'user_type'=>USER_INACTIVE, 'user_email'=>"ref$i@example.invalid", 'user_permissions'=>'',
			'group_id'=>$i === 203 ? $missing : ($i === 204 ? 71 : $group)));
		$members[] = array('user_id'=>$id, 'group_id'=>$group, 'group_leader'=>0, 'user_pending'=>0);
		if ($i<=202) { $copies[] = $delivery($n, $id, PRIVMSGS_INBOX, 2); }
	}
	foreach (array_chunk($users, 25) as $batch) { $db->sql_multi_insert(USERS_TABLE, $batch); }
	$members[] = array('user_id'=>$n, 'group_id'=>71, 'group_leader'=>1, 'user_pending'=>1);
	$members[] = array('user_id'=>$n+204, 'group_id'=>71, 'group_leader'=>0, 'user_pending'=>1);
	$members[] = array('user_id'=>$n+1, 'group_id'=>$missing, 'group_leader'=>0, 'user_pending'=>1);
	$members[] = array('user_id'=>$missing, 'group_id'=>71, 'group_leader'=>0, 'user_pending'=>0);
	$db->sql_multi_insert(USER_GROUP_TABLE, $members);
	foreach (array(0=>2,1=>ANONYMOUS,2=>$missing,3=>ANONYMOUS,4=>$n,5=>2) as $i=>$author)
	{
		$db->sql_query('INSERT INTO ' . PRIVMSGS_TABLE . ' ' . $db->sql_build_array('INSERT', array_merge($message,
			array('msg_id'=>$n+$i, 'author_id'=>$author, 'message_subject'=>'Private mailbox subject',
				'message_text'=>$i === 5 ? '' : 'Private mailbox text', 'to_address'=>'u_' . $n, 'bcc_address'=>'u_' . ($n+1)))));
	}
	foreach (array($n,$n+1,$missing,ANONYMOUS) as $i=>$owner)
	{
		$db->sql_query('INSERT INTO ' . PRIVMSGS_FOLDER_TABLE . ' ' . $db->sql_build_array('INSERT',
			array('folder_id'=>$n+$i, 'user_id'=>$owner, 'folder_name'=>'Private mailbox folder', 'pm_count'=>0)));
	}
	// A sender can legitimately own an inbox and a sent copy of the same message.
	$copies[] = $delivery($n, $n, PRIVMSGS_INBOX, 2);
	$copies[] = $delivery($n+1, $n, $n, ANONYMOUS);
	$copies[] = $delivery($n+2, $n, PRIVMSGS_INBOX, $missing);
	foreach (array(PRIVMSGS_INBOX, PRIVMSGS_SENTBOX, PRIVMSGS_OUTBOX, PRIVMSGS_NO_BOX, PRIVMSGS_HOLD_BOX) as $folder)
	{ $copies[] = $delivery($n+4, $n, $folder, $n); }
	$copies[] = array_merge($delivery($n+5, $n+7, PRIVMSGS_INBOX, 2), array('pm_deleted'=>1));
	$copies[] = $delivery($n+5, $n, $n+1, 2);
	$copies[] = $delivery($n+5, $n+1, $missing, 2);
	$copies[] = $delivery($n+5, $n+2, -17, 2);
	$copies[] = $delivery($n+5, $missing, PRIVMSGS_INBOX, 2);
	$copies[] = $delivery($n+5, ANONYMOUS, PRIVMSGS_INBOX, 2);
	$copies[] = $delivery($n+5, $n+5, PRIVMSGS_INBOX, $n);
	$copies[] = $delivery($n+6, $n, PRIVMSGS_INBOX, 2);
	$db->sql_multi_insert(PRIVMSGS_TO_TABLE, $copies);
	$state->set($key, $signature());
	$db->sql_transaction('commit');
	return;
}
if ($state->get($key) === null) { throw new RuntimeException('Missing account reference fixture'); }
switch ($argv[2])
{
	case 'account-deliver':
		if ($signature() !== $state->get($key)) { throw new RuntimeException('Review changed a message, folder, membership or account'); }
		$db->sql_transaction('begin');
		$db->sql_query('DELETE FROM ' . PRIVMSGS_TO_TABLE . " WHERE msg_id=$n AND user_id=" . ($n+50));
		$db->sql_query('INSERT INTO ' . PRIVMSGS_TO_TABLE . ' ' . $db->sql_build_array('INSERT', $delivery($n, $n+203, PRIVMSGS_INBOX, 2)));
		$state->set($key, $signature());
		$db->sql_transaction('commit');
		break;
	case 'account-verify':
		if ($signature() !== $state->get($key)) { throw new RuntimeException('The report changed the reviewed test data'); }
		break;
	case 'account-restore':
		$db->sql_transaction('begin');
		foreach (array(PRIVMSGS_TO_TABLE,PRIVMSGS_FOLDER_TABLE,PRIVMSGS_TABLE,USER_GROUP_TABLE,USERS_TABLE) as $table)
		{ $db->sql_query("DELETE FROM $table WHERE " . $scope[$table]); }
		$state->delete($key); $db->sql_transaction('commit');
		break;
	default: exit(2);
}
