<?php
/***************************************************************************
 *                               export.php
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);
include($phpbb_root_path . 'includes/bbcode.'.$phpEx);
require_once($phpbb_root_path . 'includes/functions_post_tombstone.'.$phpEx);

//
// Start initial var setup
//
$topic_id = $post_id = 0;
if (phpbb_request_scalar($_GET, POST_TOPIC_URL) !== '')
{
   $topic_id = intval(phpbb_request_scalar($_GET, POST_TOPIC_URL));
}
else if (phpbb_request_scalar($_GET, 'topic') !== '')
{
   $topic_id = intval(phpbb_request_scalar($_GET, 'topic'));
}

if (phpbb_request_scalar($_GET, POST_POST_URL) !== '')
{
   $post_id = intval(phpbb_request_scalar($_GET, POST_POST_URL));
}

if (!$topic_id && !$post_id)
{
   message_die(GENERAL_MESSAGE, 'Topic_post_not_exist');
}

// Resolve the forum for session startup. The exporter re-reads this context
// and its current permissions in its own snapshot; this is not an ACL cache.
$lookup=$post_id?'p.post_id='.$post_id:'t.topic_id='.$topic_id;
$join=$post_id?' JOIN '.POSTS_TABLE.' p ON p.topic_id=t.topic_id AND p.forum_id=t.forum_id':'';
$sql='SELECT t.topic_id,f.forum_id FROM '.TOPICS_TABLE.' t JOIN '.FORUMS_TABLE.' f ON f.forum_id=t.forum_id'.$join.' WHERE '.$lookup;
$result=$db->sql_query($sql);if(!$result){message_die(GENERAL_ERROR,$lang['Topic_export_unavailable']);}
$initial=$db->sql_fetchrow($result);$db->sql_freeresult($result);if(!$initial){message_die(GENERAL_MESSAGE,$lang['Topic_post_not_exist']);}
$userdata=session_pagestart($user_ip,(int)$initial['forum_id']);init_userprefs($userdata);
$direction=strtolower(phpbb_request_scalar($_POST,'postorder',phpbb_request_scalar($_GET,'postorder','asc')))==='desc'?'DESC':'ASC';
require_once $phpbb_root_path.'includes/functions_topic_export.'.$phpEx;
try{$archive=phpbb_topic_export($db,$userdata,$topic_id,$post_id,$direction);}
catch(Exception$error){$key=$error instanceof PhpbbTopicExportFailure&&$error->getMessage()==='Topic_export_too_large'?'Topic_export_too_large':'Topic_export_unavailable';message_die(GENERAL_ERROR,$lang[$key]);}
catch(Error$error){message_die(GENERAL_ERROR,$lang['Topic_export_unavailable']);}
if($archive['status']!=='ok'){
    if($archive['status']==='denied'&&empty($userdata['session_logged_in'])){$target=$post_id?POST_POST_URL.'='.$post_id:POST_TOPIC_URL.'='.$topic_id;redirect(append_sid("login.$phpEx?redirect=viewtopic.$phpEx&".$target,true));}
    message_die(GENERAL_MESSAGE,$lang['Topic_post_not_exist']);
}
try{
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Length: '.$archive['bytes']);
    header('Content-Disposition: '.$archive['disposition']);
    fpassthru($archive['stream']);
}finally{fclose($archive['stream']);}
?>
