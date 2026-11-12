<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_report_storage.php';

function phpbb_report_composer_rows($database, $sql)
{
    $result = $database->sql_query($sql);
    if ($result === false) { phpbb_card_error(); }
    try {
        $rows = $database->sql_fetchrowset($result);
        if (!is_array($rows)) { phpbb_card_error(); }
        return $rows;
    } finally { $database->sql_freeresult($result); }
}

// The destination posting permission does not grant access to the reported
// source. This reader deliberately uses current storage instead of the cached
// forum tree or a role captured while opening the reporting form.
function phpbb_report_composer_subject($database, $post_id, $viewer)
{
    $post_id = phpbb_card_id($post_id, 16777215);
    $logged = !empty($viewer['session_logged_in']);
    if (!isset($viewer['user_id'], $viewer['session_id']) || !is_string($viewer['session_id']) || $viewer['session_id'] === ''
        || (!$logged && (string)$viewer['user_id'] !== (string)ANONYMOUS)) { phpbb_card_error('Not_Authorised'); }
    $actor_id = $logged ? phpbb_card_id($viewer['user_id'], 8388607) : ANONYMOUS;
    $actors = phpbb_report_composer_rows($database, 'SELECT user_id,user_level,user_active,user_blocktime FROM ' . USERS_TABLE . ' WHERE user_id=' . $actor_id);
    if (count($actors) !== 1 || ($logged && ((int)$actors[0]['user_active'] !== 1 || (int)$actors[0]['user_blocktime'] >= time()))) { phpbb_card_error('Not_Authorised'); }
    $sid = $database->sql_escape($viewer['session_id']);
    $sessions = phpbb_report_composer_rows($database, 'SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=" . $actor_id . ' AND session_logged_in=' . ($logged ? 1 : 0));
    if (count($sessions) !== 1) { phpbb_card_error('Not_Authorised'); }
    $actor = $actors[0]; $actor['session_logged_in'] = $logged;
    $source = phpbb_report_composer_rows($database, 'SELECT p.forum_id FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE
        . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=p.forum_id'
        . ' WHERE p.post_id=' . $post_id . ' AND p.post_deleted=0 AND t.topic_moved_id=0');
    if (count($source) !== 1) { phpbb_card_error('No_such_post'); }
    $rights = auth(AUTH_ALL, (int)$source[0]['forum_id'], $actor, '', $database);
    if (empty($rights['auth_view']) || empty($rights['auth_read']) || empty($rights['auth_bluecard'])) { phpbb_card_error('No_such_post'); }
    $rows = phpbb_report_composer_rows($database, 'SELECT CASE WHEN first_post.post_deleted=0 THEN pt.post_subject ELSE t.topic_title END AS report_subject FROM ' . POSTS_TABLE
        . ' target JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=target.topic_id AND t.forum_id=target.forum_id'
        . ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id JOIN ' . POSTS_TABLE . ' first_post'
        . ' ON first_post.post_id=t.topic_first_post_id AND first_post.topic_id=t.topic_id AND first_post.forum_id=t.forum_id'
        . ' JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id=first_post.post_id WHERE target.post_id=' . $post_id
        . ' AND target.forum_id=' . (int)$source[0]['forum_id'] . ' AND target.post_deleted=0 AND t.topic_moved_id=0');
    if (count($rows) !== 1) { phpbb_card_error('No_such_post'); }
    return $rows[0]['report_subject'];
}
