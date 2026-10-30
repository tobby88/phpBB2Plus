<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_post_tombstone.php';

function phpbb_tombstone_topics_owned($storage,$forum_id,$topics,$topic_guard)
{
    if(!($storage instanceof PhpbbTopicRemovalDatabase)||!$storage->transactional||(int)$forum_id!==$storage->forum_id){throw new PhpbbPostSubmitException('Moderation_delete_unconfirmed');}
    $removed=array('topic_ids'=>array(),'post_ids'=>array(),'retained_placeholders'=>true);$posters=array();$user=$storage->actor();$when=time();
    $selected=$storage->rows('SELECT topic_id FROM '.TOPICS_TABLE.' WHERE forum_id='.$forum_id.' AND topic_id IN('.implode(',',$topics).')'.$topic_guard.' ORDER BY topic_id FOR UPDATE');
    foreach($selected as$topic){
        $id=(int)$topic['topic_id'];$posts=$storage->rows('SELECT post_id,poster_id,post_deleted FROM '.POSTS_TABLE.' WHERE topic_id='.$id.' AND forum_id='.$forum_id.' ORDER BY post_id FOR UPDATE');
        if($storage->policy==='forum_admin'&&$posts){throw new PhpbbPostSubmitException('Post_delete_forum_move_required');}
        $changed=false;
        foreach($posts as$post){
            if(phpbb_post_is_deleted($post)){continue;}$post_id=(int)$post['post_id'];$owner=(int)$post['poster_id'];
            $storage->sql_query('UPDATE '.POSTS_TABLE.' SET '.phpbb_post_tombstone_assignments((int)$user['user_id'],$when).' WHERE post_id='.$post_id.' AND topic_id='.$id.' AND forum_id='.$forum_id.' AND poster_id='.$owner.' AND post_deleted=0');
            if((int)$storage->sql_affectedrows()!==1){throw new PhpbbPostSubmitException('Moderation_delete_changed');}
            phpbb_post_tombstone_clear_text($storage,$post_id);remove_search_post($post_id,true,true,$storage);$storage->detach_post_files($post_id);$posters[$owner]=$owner;$removed['post_ids'][]=$post_id;$changed=true;
        }
        if(!$posts){
            // An empty redirect contains no contribution needing a marker.
            $storage->sql_query('DELETE FROM '.TOPICS_TABLE.' WHERE topic_id='.$id.' AND forum_id='.$forum_id.' AND NOT EXISTS(SELECT 1 FROM '.POSTS_TABLE.' WHERE topic_id='.$id.')');
            if((int)$storage->sql_affectedrows()!==1){throw new PhpbbPostSubmitException('Moderation_delete_changed');}$changed=true;
            foreach(array(BOOKMARK_TABLE,TOPICS_WATCH_TABLE,TOPIC_VIEW_TABLE)as$table){$storage->sql_query('DELETE FROM '.$table.' WHERE topic_id='.$id.' AND NOT EXISTS(SELECT 1 FROM '.TOPICS_TABLE.' WHERE topic_id='.$id.')');}
            phpbb_posting_cleanup_empty_redirects($storage,$id);
        }
        if(!$changed){continue;}
        foreach($storage->rows('SELECT vote_id FROM '.VOTE_DESC_TABLE.' WHERE topic_id='.$id.' ORDER BY vote_id FOR UPDATE')as$poll){$vote=(int)$poll['vote_id'];foreach(array(VOTE_RESULTS_TABLE,VOTE_USERS_TABLE)as$table){$storage->sql_query('DELETE FROM '.$table.' WHERE vote_id='.$vote);}$storage->sql_query('DELETE FROM '.VOTE_DESC_TABLE.' WHERE vote_id='.$vote.' AND topic_id='.$id);}
        if($posts){
            $storage->sql_query('UPDATE '.TOPICS_TABLE.' SET topic_vote=0,topic_poster='.ANONYMOUS.' WHERE topic_id='.$id.' AND forum_id='.$forum_id);attachment_sync_topic($id,$storage);phpbb_post_tombstone_topic_caption($storage,$id,$forum_id);
        }
        $removed['topic_ids'][]=$id;
    }
    foreach($posters as$id){phpbb_post_tombstone_recount_user($storage,$id);}phpbb_posting_sync_forum($storage,$forum_id);return$removed;
}
