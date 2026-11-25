<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_card_threshold($value)
{
	return preg_match('/^[0-9]{1,5}$/D', (string) $value) && (int) $value <= 32767 ? max(1, (int) $value) : 0;
}

function plus_card_count($user_id, $value)
{
	return (int) $user_id > 0 ? max(0, (int) $value) : 0;
}

function plus_card_ip($rule_id, $ip, $mask = '')
{
	// Use the preserved distribution's reader, including its distinction between
	// a literal 255, an explicit wildcard and the original trailing-ff convention.
	require_once dirname(__DIR__, 2) . '/ext/phpbb2plus/community/legacy/address_bans.php';
	if (!phpbb_ip_ban_valid($ip, $mask) || $ip === '')
	{
		throw new \RuntimeException('Review the invalid IPv4 ban in phpBB2 Plus banlist row ' . (int) $rule_id . ' before converting.');
	}
	return phpbb_ip_ban_format($ip, $mask);
}

function plus_card_email($rule_id, $email)
{
	if ($email[0] === '@') { $email = '*' . $email; }
	$email = preg_replace('/\*+/', '*', $email);
	if (strlen($email) > 255 || mb_strlen($email, 'UTF-8') > 100 || strpbrk($email, "\0\r\n") !== false)
	{
		throw new \RuntimeException('Review the email ban in phpBB2 Plus banlist row ' . (int) $rule_id . '. It cannot be represented in the native ban list.');
	}
	return $email;
}

function plus_card_active_block($source_id, $end)
{
	global $db;
	if ((int) $end < time()) { return; }
	$id = (int) phpbb_user_id($source_id);
	$end = (int) $end;
	// Batches iterate a stable set of source IDs, even if blocks expire during
	// the conversion. An interrupted batch must not duplicate an active block.
	$result = $db->sql_query('SELECT ban_id FROM ' . BANLIST_TABLE . " WHERE ban_userid=$id AND ban_end=$end");
	$exists = $db->sql_fetchfield('ban_id') !== false;
	$db->sql_freeresult($result);
	if (!$exists)
	{
		$db->sql_query('INSERT INTO ' . BANLIST_TABLE . ' ' . $db->sql_build_array('INSERT', array('ban_userid' => $id, 'ban_end' => $end, 'plus_card_legacy' => 1)));
	}
}

function plus_card_finish()
{
	global $db, $config, $cache;
	$result = $db->sql_query_limit('SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE plus_card_legacy=1', 1);
	$config->set('plus_card_has_bans', (int) ($db->sql_fetchfield('ban_id') !== false));
	$db->sql_freeresult($result);
	$cache->destroy('sql', BANLIST_TABLE);
}

function plus_card_ban_schema($original)
{
	global $src_db, $convert;
	$table = $src_db->sql_escape($convert->src_table_prefix . 'banlist');
	$result = $src_db->sql_query("SELECT COUNT(*) AS present FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='ban_ip_mask'");
	$masked = (bool) $src_db->sql_fetchfield('present');
	$src_db->sql_freeresult($result);
	$ip_fields = array('banlist.ban_id', 'banlist.ban_ip');
	if ($masked) { $ip_fields[] = 'banlist.ban_ip_mask'; }
	// Each component of a combined legacy record was an independent rule.
	// Keep the native username collision check and its original position.
	return array(
		array('target' => BANLIST_TABLE, 'primary' => 'banlist.ban_id',
			'execute_first' => $original['execute_first'], 'query_first' => $original['query_first'],
			array('', 'banlist.ban_id', ''), array('ban_userid', 'users.user_id', 'phpbb_user_id'), array('plus_card_legacy', 1, ''),
			'where' => 'banlist.ban_userid=users.user_id AND users.user_id>0'),
		array('target' => BANLIST_TABLE, 'primary' => 'banlist.ban_id',
			array('ban_ip', $ip_fields, 'plus_card_ip'), array('plus_card_legacy', 1, ''),
			'where' => $masked ? "(banlist.ban_ip<>'' OR banlist.ban_ip_mask<>'')" : "banlist.ban_ip<>''"),
		array('target' => BANLIST_TABLE, 'primary' => 'banlist.ban_id',
			array('ban_email', array('banlist.ban_id', 'banlist.ban_email'), 'plus_card_email'), array('plus_card_legacy', 1, ''),
			'where' => "banlist.ban_email<>''"),
		array('target' => BANLIST_TABLE, 'primary' => 'users.user_id',
			array('', array('users.user_id', 'users.user_blocktime'), 'plus_card_active_block'),
			'where' => 'users.user_id>0 AND users.user_blocktime>0'),
	);
}
