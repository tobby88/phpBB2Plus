<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_forum_read_snapshot.php';

function phpbb_recent_period($mode,$days,$now,$timezone)
{
    if(!in_array($mode,array('today','yesterday','last24','lastweek','lastXdays'),true)||!is_int($days)||$days<1||$days>365||!is_int($now)||$now<0||$now>2147483647||!(is_string($timezone)||is_int($timezone)||is_float($timezone))||!preg_match('/^[+-]?[0-9]{1,2}(?:\.[0-9]{1,6})?$/D',(string)$timezone)||abs((float)$timezone)>14){throw new PhpbbForumReadFailure('Invalid recent period');}
    // phpBB stores a fixed UTC offset, not an IANA zone. Match create_date()
    // rather than the database server's local calendar or YYYYMMDD arithmetic.
    $offset=round((float)$timezone*3600);$midnight=floor(((float)$now+$offset)/86400)*86400-$offset;$end=(float)$now+1;
    if($mode==='today'){$begin=$midnight;}
    elseif($mode==='yesterday'){$begin=$midnight-86400;$end=$midnight;}
    else{$begin=(float)$now-86400*($mode==='lastweek'?7:($mode==='lastXdays'?$days:1));}
    return array('from'=>sprintf('%.0f',max(0,$begin)),'to'=>sprintf('%.0f',max(0,$end)));
}

function phpbb_recent_forum_ids($values)
{
    if(!is_array($values)||count($values)>10000){throw new PhpbbForumReadFailure('Invalid recent forums');}$ids=array();
    foreach($values as$value){if(!(is_int($value)||is_string($value))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value)||(float)$value>2147483647){throw new PhpbbForumReadFailure('Invalid recent forum');}$ids[(int)$value]=(int)$value;}
    sort($ids,SORT_NUMERIC);return array_values($ids);
}

function phpbb_recent_topics($database,$forums,$period,$start,$limit)
{
    $forums=phpbb_recent_forum_ids($forums);
    if(!is_array($period)||array_keys($period)!==array('from','to')||!is_int($start)||$start<0||$start>1000000||!is_int($limit)||$limit<1||$limit>100){throw new PhpbbForumReadFailure('Invalid recent page');}
    foreach($period as$value){if(!is_string($value)||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$value)||(float)$value>2147483648){throw new PhpbbForumReadFailure('Invalid recent range');}}
    if((float)$period['from']>(float)$period['to']){throw new PhpbbForumReadFailure('Invalid recent range order');}
    if(!$forums||$period['from']===$period['to']){return array('rows'=>array(),'total'=>0,'start'=>0);}
    $reader=null;
    try{
        $reader=new PhpbbForumReadSnapshot($database,array(TOPICS_TABLE,POSTS_TABLE,FORUMS_TABLE,USERS_TABLE));
        $from=' FROM '.TOPICS_TABLE.' t JOIN '.POSTS_TABLE.' p ON p.post_id=t.topic_last_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id AND p.post_deleted=0'
            .' JOIN '.FORUMS_TABLE.' f ON f.forum_id=t.forum_id'
            .' LEFT JOIN '.POSTS_TABLE.' p2 ON p2.post_id=t.topic_first_post_id AND p2.topic_id=t.topic_id AND p2.forum_id=t.forum_id'
            .' LEFT JOIN '.USERS_TABLE.' u ON u.user_id=p.poster_id LEFT JOIN '.USERS_TABLE.' u2 ON u2.user_id=p2.poster_id'
            .' WHERE t.forum_id IN ('.implode(',',$forums).') AND t.topic_moved_id=0 AND t.topic_status<>'.TOPIC_MOVED
            .' AND p.post_time>='.$period['from'].' AND p.post_time<'.$period['to'];
        $count=$reader->rows('SELECT COUNT(*) total_topics'.$from);
        if(count($count)!==1||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$count[0]['total_topics'])||(float)$count[0]['total_topics']>2147483647){throw new PhpbbForumReadFailure('Invalid recent count');}
        $total=(int)$count[0]['total_topics'];$start=$total?min((int)(floor($start/$limit)*$limit),(int)(floor(($total-1)/$limit)*$limit)):0;
        $rows=$total?$reader->rows('SELECT t.*,p.poster_id,p.post_username last_poster_name,p.post_author_removed last_poster_removed,p.post_id,p.post_time,f.forum_name,f.forum_id,u.username last_poster,u.user_id last_poster_id,u2.username first_poster,u2.user_id first_poster_id,p2.poster_id first_actor_id,p2.post_id first_post_id,p2.post_time first_post_time,p2.post_deleted first_post_deleted,p2.post_username first_poster_name,p2.post_author_removed first_poster_removed'.$from.' ORDER BY p.post_time DESC,p.post_id DESC,t.topic_id DESC LIMIT '.$start.','.$limit):array();
        return array('rows'=>$rows,'total'=>$total,'start'=>$start);
    }finally{if($reader!==null){$reader->release();}}
}
