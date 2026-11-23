<?php
// The surrounding native fixture owns the schema and its synthetic moderator.
require_once $phpbb_root_path.'includes/functions_moderation.php';
foreach (array('config', 'posts', 'banlist') as $suffix) {
    $table = 'phpbb_'.$suffix;
    sn_check(preg_match('/CREATE TABLE '.$table.'\s*\([\s\S]*?;/', $canonical, $match) === 1, 'Installer table for moderator page');
    sn_sql(str_replace($table, 'fixture_'.$suffix, $match[0]));
}
sn_reset('moderator');
sn_insert('fixture_config', array('config_name'=>'session_length','config_value'=>'3600'));
sn_sql('UPDATE fixture_sessions SET session_time='.time());
sn_sql('UPDATE fixture_forums SET forum_topics=97 WHERE forum_id=3');
foreach (array(100=>3, 101=>3, 200=>4) as $id=>$forum) {
    sn_insert('fixture_posts', array('post_id'=>$id, 'topic_id'=>$id, 'forum_id'=>$forum, 'poster_id'=>9, 'post_time'=>500));
    sn_sql('UPDATE fixture_topics SET topic_first_post_id='.$id.',topic_last_post_id='.$id.' WHERE topic_id='.$id);
}
sn_insert('fixture_topics', array('topic_id'=>102, 'forum_id'=>3, 'topic_status'=>TOPIC_MOVED,
    'topic_moved_id'=>200, 'topic_first_post_id'=>200, 'topic_last_post_id'=>200, 'topic_title'=>'Moved topic'));
$reader_db = new sql_db($host, 'root', $password, $schema, false);
try {
    $first = phpbb_moderator_topic_page($reader_db, $userdata, 3, 1, 2);
    $last = phpbb_moderator_topic_page($reader_db, $userdata, 3, 999, 2);
    sn_check($first['start'] === 0 && $first['total'] === 3 && $first['pages'] === 2
        && array_map('intval', array_column($first['rows'],'topic_id')) === array(102,101), 'Real total, moved link and stable tied topic order');
    sn_check($last['start'] === 2 && $last['page'] === 2 && (int)$last['rows'][0]['topic_id'] === 100,
        'An obsolete moderator bookmark opens the last page');
    sn_check(phpbb_moderator_topic_page($reader_db,$userdata,3,0,0)['per_page'] === 50, 'Installer default when the old page size is zero');
    sn_check(phpbb_moderator_topic_page($reader_db,$userdata,4,0,2) === null, 'Ordinary moderator sees only the assigned forum');
    sn_sql('DELETE FROM fixture_topics WHERE forum_id=3');
    $empty = phpbb_moderator_topic_page($reader_db,$userdata,3,10,2);
    sn_check($empty['rows'] === array() && $empty['total'] === 0 && $empty['page'] === 1 && $empty['pages'] === 1,
        'An empty moderated forum has one empty page');
} finally { $reader_db->sql_close(); }
echo "Moderator topic pages passed.\n";
