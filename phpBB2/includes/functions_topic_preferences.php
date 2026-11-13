<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_topic_preference_storage.php';

class PhpbbTopicPreferenceException extends RuntimeException {}
function phpbb_topic_preference_error($key)
{
	global $lang;
	throw new PhpbbTopicPreferenceException(isset($lang[$key]) ? $lang[$key] : $key);
}
class PhpbbTopicPreferenceDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql)
	{
		$result = $this->connection->sql_query($sql);
		if (!$result) { phpbb_topic_preference_error('Topic_preference_failed'); }
		return $result;
	}
}
function phpbb_topic_preference_id($value)
{
	if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)) { return false; }
	$value = ltrim((string) $value, '0');
	return $value !== '' && strlen($value) <= 8 && (int) $value <= 16777215 ? (int) $value : false;
}
function phpbb_topic_preference_rows($db, $sql)
{
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
	return $rows;
}
function phpbb_topic_preference_can_read($db, $forum_id, $user)
{
	$user['session_logged_in'] = true;
	$view = auth(AUTH_VIEW, $forum_id, $user, '', $db);
	$read = auth(AUTH_READ, $forum_id, $user, '', $db);
	return !empty($view['auth_view']) && !empty($read['auth_read']);
}

// Internal API: endpoints validate their POST/SID or signed toolbar action.
// null reads state (and acknowledges watch notifications), bool sets state.
function phpbb_topic_preference($database, $topic_id, $kind, $state = null)
{
	global $userdata;
	$topic_id = phpbb_topic_preference_id($topic_id);
	$user_id = isset($userdata['user_id']) ? phpbb_topic_preference_id($userdata['user_id']) : false;
	if (!$topic_id || !$user_id || empty($userdata['session_logged_in']) || !in_array($kind, array('watch', 'bookmark'), true) || !in_array($state, array(null, true, false), true))
	{
		phpbb_topic_preference_error('Session_invalid');
	}
	$store = null;
	try
	{
		$store = new PhpbbTopicPreferenceStore($database, $userdata, $topic_id, $kind, $state);
		return $store->apply_preference();
	}
	catch (PhpbbTopicPreferenceException $error)
	{
		if ($store && $store->commit_attempted) { phpbb_topic_preference_error('Topic_preference_unconfirmed'); }
		throw $error;
	}
	catch (Exception $error) { phpbb_topic_preference_error($store && $store->commit_attempted ? 'Topic_preference_unconfirmed' : 'Topic_preference_failed'); }
	catch (Error $error) { phpbb_topic_preference_error($store && $store->commit_attempted ? 'Topic_preference_unconfirmed' : 'Topic_preference_failed'); }
	finally { if ($store) { $store->release(); } }
}

function phpbb_bookmark_removal_ids($values)
{
	if (!is_array($values) || count($values) > 1000) { phpbb_topic_preference_error('Session_invalid'); }
	$ids = array();
	foreach ($values as $value)
	{
		$id = phpbb_topic_preference_id($value);
		if (!$id) { phpbb_topic_preference_error('Session_invalid'); }
		$ids[$id] = $id;
	}
	sort($ids, SORT_NUMERIC);
	return $ids;
}

// Internal API: the bookmark form verifies POST and its session token first.
function phpbb_bookmark_remove_many($database, $values)
{
	global $userdata;
	$ids = phpbb_bookmark_removal_ids($values);
	if (!$ids) { return true; }
	$store = null;
	try
	{
		$store = new PhpbbTopicPreferenceStore($database, $userdata, $ids[0], 'bookmark', false);
		return $store->remove_bookmarks($ids);
	}
	catch (PhpbbTopicPreferenceException $error)
	{
		if ($store && $store->commit_attempted) { phpbb_topic_preference_error('Topic_preference_unconfirmed'); }
		throw $error;
	}
	catch (Exception $error) { phpbb_topic_preference_error($store && $store->commit_attempted ? 'Topic_preference_unconfirmed' : 'Topic_preference_failed'); }
	catch (Error $error) { phpbb_topic_preference_error($store && $store->commit_attempted ? 'Topic_preference_unconfirmed' : 'Topic_preference_failed'); }
	finally { if ($store) { $store->release(); } }
}
