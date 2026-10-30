<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}

function phpbb_post_tombstone_choice($database,$from_request=true)
{
    if(!is_bool($from_request)){throw new PhpbbPostSubmitException('Post_delete_invalid_choice');}
    $value=$from_request&&isset($_POST['delete_permanently'])?$_POST['delete_permanently']:'0';
    if(!(is_int($value)||is_string($value))||!in_array((string)$value,array('0','1'),true)){throw new PhpbbPostSubmitException('Post_delete_invalid_choice');}
    $rows=$database->rows('SELECT config_value FROM '.CONFIG_TABLE." WHERE config_name='post_delete_allow_permanent' FOR UPDATE");
    if(count($rows)>1||(count($rows)===1&&!in_array((string)$rows[0]['config_value'],array('0','1'),true))){throw new PhpbbPostSubmitException('Posting_submit_upgrade');}
    // Missing policy is deliberately conservative during an interrupted update.
    if((string)$value==='1'&&(!$rows||(string)$rows[0]['config_value']!=='1')){throw new PhpbbPostSubmitException('Post_delete_permanent_disabled');}
    return (string)$value!=='1';
}
function phpbb_post_is_deleted($row)
{
    return is_array($row)&&isset($row['post_deleted'])&&(string)$row['post_deleted']==='1';
}
function phpbb_post_tombstone_option($post_deletion)
{
    global$board_config,$lang;
    if($post_deletion!==true||!isset($board_config['post_delete_allow_permanent'])||!in_array($board_config['post_delete_allow_permanent'],array(true,1,'1'),true)){return '';}
    return '<br /><br /><label class="genmed"><input type="checkbox" name="delete_permanently" value="1" /> '.htmlspecialchars($lang['Post_delete_permanently'],ENT_QUOTES,'UTF-8').'</label>';
}

function phpbb_post_tombstone_assignments($actor,$when)
{
    if(!is_int($actor)||$actor<1||$actor>8388607||!is_int($when)||$when<1||(float)$when>4294967295){throw new PhpbbPostSubmitException('Posting_delete_unconfirmed');}
    return 'post_deleted=1,post_deleted_time='.$when.',post_deleted_by='.$actor.",poster_id=-1,post_username='',poster_ip='',post_author_removed=0,post_attachment=0,post_icon=0,post_bluecard=0,enable_bbcode=0,enable_html=0,enable_smilies=0,enable_sig=0,post_edit_time=NULL,post_edit_count=0,post_edit_user_id=NULL,post_edit_username=NULL";
}
function phpbb_post_tombstone_clear_text($database,$post_id)
{
    $id=phpbb_posting_scope_id($post_id);
    $database->sql_query('UPDATE '.POSTS_TEXT_TABLE." SET bbcode_uid='',post_subject='',post_text='' WHERE post_id=".$id.' AND EXISTS(SELECT 1 FROM '.POSTS_TABLE.' p WHERE p.post_id='.$id.' AND p.post_deleted=1)');
    $rows=$database->rows('SELECT bbcode_uid,post_subject,post_text FROM '.POSTS_TEXT_TABLE.' WHERE post_id='.$id);
    if(count($rows)!==1||$rows[0]['bbcode_uid']!==''||$rows[0]['post_subject']!==''||$rows[0]['post_text']!==''){throw new PhpbbPostSubmitException('Posting_delete_unconfirmed');}
}
function phpbb_post_tombstone_recount_user($database,$user_id)
{
    $id=(int)$user_id;if($id<1){return;}
    $database->sql_query('UPDATE '.USERS_TABLE.' SET user_posts=(SELECT COUNT(*) FROM '.POSTS_TABLE.' p JOIN '.FORUMS_TABLE.' f ON f.forum_id=p.forum_id WHERE p.poster_id='.$id." AND f.count_posts='1') WHERE user_id=".$id);
}
function phpbb_post_tombstone_topic_caption($database,$topic_id,$forum_id)
{
    global$lang;
    $topic=phpbb_posting_scope_id($topic_id);$forum=phpbb_posting_scope_id($forum_id);
    $active=$database->rows('SELECT post_id FROM '.POSTS_TABLE.' WHERE topic_id='.$topic.' AND forum_id='.$forum.' AND post_deleted=0 LIMIT 1');
    if(!$active){$title=$database->sql_escape(htmlspecialchars($lang['Topic_deleted_placeholder'],ENT_QUOTES,'UTF-8'));$database->sql_query('UPDATE '.TOPICS_TABLE." SET topic_title='".$title."',topic_desc='',topic_status=".TOPIC_LOCKED.' WHERE topic_id='.$topic.' AND forum_id='.$forum);return true;}
    return false;
}
