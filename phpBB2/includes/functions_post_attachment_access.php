<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pm_attachment_access.php';

// Match the legacy ALL/REG/ACL/MOD/ADMIN rules in one current read. Neither a
// cached category tree nor a caller's repeatable-read snapshot grants access.
function phpbb_post_attachment_context($database, $post_id, $viewer, $attachment_id)
{
	global $lang;
	$posts = attach_delete_id_array(array($post_id));
	$files = attach_delete_id_array(array($attachment_id));
	if (!$posts || !$files || !is_array($viewer)) { return false; }
	$logged = !empty($viewer['session_logged_in']);
	$users = $logged && isset($viewer['user_id']) ? attach_delete_id_array(array($viewer['user_id'])) : false;
	if ($logged && (!$users || !isset($viewer['session_id']) || !is_string($viewer['session_id'])
		|| $viewer['session_id'] === '' || strlen($viewer['session_id']) > 32)) { return false; }
	$owner = null;
	try {
		// Share the already transaction-safe, independent, SELECT-only reader.
		$owner = new PhpbbPmAttachmentReader($database);
		$level = $logged ? 'u.user_level' : '0';
		$sql = 'SELECT d.*,' . $level . ' AS post_download_level,' . phpbb_attachment_extension_mode_sql()
			. ' AS attachment_download_mode FROM ' . POSTS_TABLE . ' p'
			. ' JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id'
			. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=p.forum_id'
			. ' JOIN ' . ATTACHMENTS_TABLE . ' a ON a.post_id=p.post_id AND a.privmsgs_id=0 AND a.attach_id=' . $files[0]
			. ' JOIN ' . ATTACHMENTS_DESC_TABLE . ' d ON d.attach_id=a.attach_id';
		if ($logged) {
			$sid = $owner->connection->sql_escape($viewer['session_id']);
			$sql .= ' JOIN ' . USERS_TABLE . ' u ON u.user_id=' . $users[0] . ' AND u.user_active<>0'
				. ' JOIN ' . SESSIONS_TABLE . ' s ON s.session_user_id=u.user_id AND s.session_logged_in=1'
				. " AND s.session_id='" . $sid . "' AND HEX(s.session_id)=HEX('" . $sid . "')";
		}
		$sql .= ' WHERE p.post_id=' . $posts[0] . ' AND p.post_deleted=0';
		foreach (array('auth_view','auth_read','auth_download') as $field) {
			$rule = 'f.' . $field . '=' . (int)AUTH_ALL;
			if ($logged) {
				$groups = 'SELECT 1 FROM ' . AUTH_ACCESS_TABLE . ' g JOIN ' . USER_GROUP_TABLE
					. ' m ON m.group_id=g.group_id AND m.user_id=u.user_id AND m.user_pending=0 WHERE g.forum_id=f.forum_id';
				$rule .= ' OR f.' . $field . '=' . (int)AUTH_REG
					. ' OR (f.' . $field . ' IN (' . (int)AUTH_ACL . ',' . (int)AUTH_MOD . ',' . (int)AUTH_ADMIN . ') AND u.user_level=' . (int)ADMIN . ')'
					. ' OR (f.' . $field . '=' . (int)AUTH_ACL . ' AND EXISTS (' . $groups . ' AND (g.' . $field . '<>0 OR g.auth_mod<>0)))'
					. ' OR (f.' . $field . '=' . (int)AUTH_MOD . ' AND EXISTS (' . $groups . ' AND g.auth_mod<>0))';
			}
			$sql .= ' AND (' . $rule . ')';
		}
		$sql .= ' AND (' . $level . '=' . (int)ADMIN . ' OR NOT EXISTS (SELECT 1 FROM ' . ATTACH_CONFIG_TABLE
			. " c WHERE c.config_name='disable_mod' AND c.config_value<>'0')) LIMIT 1";
		$rows = $owner->rows($sql);
		// Keep the earlier public-controller key during provider-first rollout.
		if ($rows) { $rows[0]['post_download_mode'] = $rows[0]['attachment_download_mode']; }
		return $rows ? $rows[0] : false;
	} catch (Exception $error) { message_die(GENERAL_ERROR, $lang['Error_no_attachment']); }
	catch (Error $error) { message_die(GENERAL_ERROR, $lang['Error_no_attachment']); }
	finally { if ($owner !== null) { $owner->release(); } }
}
