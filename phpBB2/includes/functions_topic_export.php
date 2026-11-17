<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_forum_read_snapshot.php';
require_once dirname(__FILE__).'/functions_post_tombstone.php';
require_once dirname(__FILE__).'/bbcode.php';

class PhpbbTopicExportFailure extends PhpbbForumReadFailure{}

function phpbb_topic_export_attribute($tag, $name)
{
    // Match the complete attribute name, never data-href/data-src. Preserve its
    // stored entity layer until the final text conversion.
    $matched = preg_match('~(?:^|[\x20\t\r\n\f])' . preg_quote($name, '~')
        . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $tag, $match);
    if ($matched === false || preg_last_error() !== PREG_NO_ERROR) { throw new PhpbbTopicExportFailure('Topic_export_unavailable'); }
    if (!$matched) { return null; }
    foreach (array(1, 2, 3) as $index) {
        if (isset($match[$index]) && $match[$index] !== '') { return $match[$index]; }
    }
    return '';
}
function phpbb_topic_export_line(&$text)
{
    if ($text !== '' && substr($text, -1) !== "\n") { $text .= "\n"; }
}
function phpbb_topic_export_list_label($number, $type)
{
    if ($type !== 'a' && $type !== 'A') { return $number . '. '; }
    $label = '';
    do { $number--; $label = chr(97 + $number % 26) . $label; $number = (int)floor($number / 26); } while ($number);
    return ($type === 'A' ? strtoupper($label) : $label) . '. ';
}
function phpbb_topic_export_text($html)
{
    // Process rendered tags in order. An image inside a link must become its
    // visible URL before the link label is compared with its destination.
    // No DOM extension is required on the supported PHP versions.
    $parts = preg_split('~(<!--.*?-->|<[/!a-z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>)~is',
        (string)$html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts) || preg_last_error() !== PREG_NO_ERROR) { throw new PhpbbTopicExportFailure('Topic_export_unavailable'); }
    $text = ''; $anchors = $lists = array(); $controls = 0;
    foreach ($parts as $index => $part) {
        if ($index % 2 === 0) {
            if (!$controls) { $text .= $part; }
            continue;
        }
        $matched = preg_match('~^<(/?)([a-z][a-z0-9]*)\b~i', $part, $tag);
        if ($matched === false || preg_last_error() !== PREG_NO_ERROR) { throw new PhpbbTopicExportFailure('Topic_export_unavailable'); }
        if (!$matched) { continue; }
        $closing = $tag[1] === '/'; $name = strtolower($tag[2]); $self_closing = substr($part, -2) === '/>';
        if ($controls) {
            if ($name === 'span') { $controls += $closing ? -1 : ($self_closing ? 0 : 1); }
            continue;
        }
        if ($name === 'span' && !$closing && phpbb_topic_export_attribute($part, 'data-sxbb-controls') === '1') {
            $controls = $self_closing ? 0 : 1;
            continue;
        }
        if ($name === 'a') {
            if (!$closing) {
                if (count($anchors) >= 256) { throw new PhpbbTopicExportFailure('Topic_export_unavailable'); }
                $anchors[] = array('start'=>strlen($text), 'href'=>phpbb_topic_export_attribute($part, 'href'));
            } elseif ($anchors) {
                $anchor = array_pop($anchors);
                if ($anchor['href'] !== null && $anchor['href'] !== '') {
                    $href = html_entity_decode($anchor['href'], ENT_QUOTES, 'UTF-8');
                    $label = html_entity_decode(substr($text, $anchor['start']), ENT_QUOTES, 'UTF-8');
                    if ($label === '') { $text .= htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); }
                    elseif ($label !== $href && 'mailto:'.$label !== $href) { $text .= ' ('.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').')'; }
                }
            }
        } elseif ($name === 'img' && !$closing) {
            $src = phpbb_topic_export_attribute($part, 'src');
            if ($src === null || $src === '') { $src = phpbb_topic_export_attribute($part, 'alt'); }
            if ($src !== null) { $text .= $src; }
        } elseif (($name === 'ul' || $name === 'ol') && !$closing) {
            if (count($lists) >= 256) { throw new PhpbbTopicExportFailure('Topic_export_unavailable'); }
            phpbb_topic_export_line($text);
            $lists[] = array('ordered'=>$name === 'ol', 'type'=>phpbb_topic_export_attribute($part, 'type'), 'number'=>0);
        } elseif (($name === 'ul' || $name === 'ol') && $closing) {
            if ($lists) { array_pop($lists); } phpbb_topic_export_line($text);
        } elseif ($name === 'li') {
            phpbb_topic_export_line($text);
            if (!$closing) {
                $last = count($lists) - 1; $label = '- ';
                if ($last >= 0 && $lists[$last]['ordered']) {
                    $lists[$last]['number']++;
                    $label = phpbb_topic_export_list_label($lists[$last]['number'], $lists[$last]['type']);
                }
                $text .= str_repeat('  ', max(0, $last)) . $label;
            }
        } elseif ($name === 'br' && !$closing) { $text .= "\n"; }
        elseif ($closing && in_array($name, array('p','div','tr','table','blockquote','pre'), true)) { $text .= "\n"; }
        elseif ($closing && ($name === 'td' || $name === 'th')) { $text .= "\t"; }
    }
    // Reject an incomplete interactive control wrapper rather than silently
    // consuming all following post content.
    if ($controls || $anchors) { throw new PhpbbTopicExportFailure('Topic_export_unavailable'); }
    return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
}
function phpbb_topic_export_body($source,$uid)
{
    global$board_config;$source=(string)$source;$uid=(string)$uid;$blocks=array();
    $prefix='PHPBBTEXT'.hash('sha256',$source).'_';while(strpos($source,$prefix)!==false){$prefix.='_';}
    if($uid!==''&&!empty($board_config['allow_bbcode'])){
        // Keep literal code out of the HTML renderer: its controls/highlighter
        // are presentation, and HTML decoding must not consume code twice.
        $source=preg_replace_callback('~\[(code|php)(?::1)?:'.preg_quote($uid,'~').'\](.*?)\[/\1(?::1)?:'.preg_quote($uid,'~').'\]~is',function($m)use(&$blocks,$prefix,$uid){$token=$prefix.count($blocks).'END';$blocks[$token]=phpbb_bbcode_code_source($m[2],$uid);return $token;},$source);
        if($source===null){throw new PhpbbTopicExportFailure('Topic_export_unavailable');}
        $source=phpbb_author_filter_quote_headers($source);
        $text=phpbb_topic_export_text(make_clickable(bbencode_second_pass($source,$uid)));
        $text=strtr($text,$blocks);
    }else{
        $source=phpbb_author_filter_quote_headers($source);
        $text=$uid!==''?phpbb_bbcode_code_source(strip_tags($source),$uid):phpbb_topic_export_text(make_clickable($source));
    }
    return str_replace("\n","\r\n",str_replace(array("\r\n","\r"),"\n",$text));
}
function phpbb_topic_export_row($row)
{
    global$lang;
    if(phpbb_post_is_deleted($row)){return "\r\n".$lang['Post_deleted_placeholder']."\r\n";}
    if(isset($row['user_id'])&&(int)$row['user_id']>0){$name=$row['username'];}
    elseif((int)$row['poster_id']>0){$name=htmlspecialchars($lang['Former_member'],ENT_QUOTES,'UTF-8');}
    else{$name=phpbb_post_author_name($row);}
    $name=html_entity_decode(strip_tags($name),ENT_QUOTES,'UTF-8');$name=str_replace(array("\r","\n","\t"),' ',$name);
    return "\r\n".$name.":\r\n".phpbb_topic_export_body(isset($row['post_text'])?$row['post_text']:'',isset($row['bbcode_uid'])?$row['bbcode_uid']:'')."\r\n";
}
function phpbb_topic_export_filename($title,$id)
{
    $title=html_entity_decode((string)$title,ENT_QUOTES,'UTF-8');$title=preg_replace('~[\x00-\x1f\x7f<>:"/\\\\|?*]+~','-',$title);$title=trim($title," .-\t");
    $characters=preg_match('//u',$title)===1?preg_split('//u',$title,-1,PREG_SPLIT_NO_EMPTY):false;$base='';if(is_array($characters)){foreach($characters as$character){if(strlen($base)+strlen($character)>180){break;}$base.=$character;}}
    $base=trim($base," .-\t");if($base===''){ $base='topic-'.(int)$id; }
    if(preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i',$base)){$base='topic-'.$base;}
    $ascii=strtr($base,array('Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'));$ascii=preg_replace('/[^a-zA-Z0-9._-]+/','-',$ascii);$ascii=trim($ascii,'.-_');if($ascii===''){$ascii='topic-'.(int)$id;}
    // RFC6266: ASCII fallback first, then the UTF-8 extended filename value.
    return 'attachment; filename="'.$ascii.'.txt"; filename*=UTF-8\'\''.rawurlencode($base.'.txt');
}
function phpbb_topic_export_write($stream,$piece,&$bytes,$maximum)
{
    $length=strlen($piece);if($bytes>$maximum-$length){throw new PhpbbTopicExportFailure('Topic_export_too_large');}
    if(!is_resource($stream)||@fwrite($stream,$piece)!==$length){throw new PhpbbTopicExportFailure('Topic_export_unavailable');}$bytes+=$length;
}
function phpbb_topic_export($database,$actor,$topic,$post,$direction='ASC',$maximum=67108864)
{
    global$db,$userdata,$lang;
    if(!is_int($topic)||$topic<0||$topic>16777215||!is_int($post)||$post<0||$post>16777215||(!$topic&&!$post)||!in_array($direction,array('ASC','DESC'),true)||!is_int($maximum)||$maximum<1||$maximum>67108864){throw new PhpbbTopicExportFailure('Topic_export_unavailable');}
    $reader=null;$stream=null;$transfer=false;$caller_db=$db;$caller_user=$userdata;
    try{
        // Session, current permissions, forum location and exported posts must
        // come from one read-only view, independent of a caller transaction.
        $reader=new PhpbbForumReadSnapshot($database,array(CONFIG_TABLE,SESSIONS_TABLE,USERS_TABLE,BANLIST_TABLE,FORUMS_TABLE,TOPICS_TABLE,POSTS_TABLE,POSTS_TEXT_TABLE,USER_GROUP_TABLE,AUTH_ACCESS_TABLE,REMOVED_AUTHOR_NAMES_TABLE));
        $current=array('session_logged_in'=>false,'user_id'=>ANONYMOUS,'user_level'=>USER);
        if(isset($actor['session_logged_in'])&&in_array($actor['session_logged_in'],array(true,1,'1'),true)){$current=$reader->authenticated_user($actor);if(!$current){return array('status'=>'denied');}}
        $join=$post?' JOIN '.POSTS_TABLE.' target ON target.topic_id=t.topic_id AND target.forum_id=t.forum_id':'';$where=$post?'target.post_id='.$post:'t.topic_id='.$topic;
        $context=$reader->rows('SELECT t.topic_id,t.topic_title,f.* FROM '.TOPICS_TABLE.' t JOIN '.FORUMS_TABLE.' f ON f.forum_id=t.forum_id'.$join.' WHERE '.$where);
        if(count($context)!==1){return array('status'=>'missing');}$info=$context[0];$access=auth(AUTH_ALL,(int)$info['forum_id'],$current,$info,$reader);
        if(empty($access['auth_view'])||empty($access['auth_read'])){return array('status'=>'denied');}
        // Spill above 2 MiB and finish before sending any download headers.
        // A failed query/write/size check must never masquerade as a full file.
        $stream=@fopen('php://temp/maxmemory:2097152','w+b');if(!$stream){throw new PhpbbTopicExportFailure('Topic_export_unavailable');}$bytes=0;
        $title=html_entity_decode($info['topic_title'],ENT_QUOTES,'UTF-8');$forum=html_entity_decode($info['forum_name'],ENT_QUOTES,'UTF-8');$title=str_replace(array("\r","\n","\t"),' ',$title);$forum=str_replace(array("\r","\n","\t"),' ',$forum);
        $count=preg_match_all('/./us',$title,$unused);$header=$lang['Forum'].":\t".$forum."\r\n".$lang['Topic'].":\t".$title."\r\n".str_repeat('-',min(200,$count===false?0:$count)+8)."\r\n";
        phpbb_topic_export_write($stream,$header,$bytes,$maximum);$db=$reader;$userdata=$current;$cursor='';$operator=$direction==='ASC'?'>':'<';$topic_id=(int)$info['topic_id'];$forum_id=(int)$info['forum_id'];
        // Canonical post_text is TEXT: at most 32 stored bodies per batch.
        // The post ID breaks timestamp ties in either ordering direction.
        do{
            $rows=$reader->rows('SELECT p.*,u.user_id,u.username,pt.post_text,pt.bbcode_uid FROM '.POSTS_TABLE.' p LEFT JOIN '.USERS_TABLE.' u ON u.user_id=p.poster_id LEFT JOIN '.POSTS_TEXT_TABLE.' pt ON pt.post_id=p.post_id WHERE p.topic_id='.$topic_id.' AND p.forum_id='.$forum_id.$cursor.' ORDER BY p.post_time '.$direction.',p.post_id '.$direction.' LIMIT 32');
            foreach($rows as$row){if(!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$row['post_time'])||(float)$row['post_time']>4294967295||!preg_match('/^[1-9][0-9]{0,7}$/D',(string)$row['post_id'])||(float)$row['post_id']>16777215){throw new PhpbbTopicExportFailure('Topic_export_unavailable');}phpbb_topic_export_write($stream,phpbb_topic_export_row($row),$bytes,$maximum);$cursor=' AND (p.post_time'.$operator.$row['post_time'].' OR (p.post_time='.$row['post_time'].' AND p.post_id'.$operator.$row['post_id'].'))';}
        }while(count($rows)===32);
        if(!@fflush($stream)||@ftell($stream)!==$bytes||@fseek($stream,0)!==0){throw new PhpbbTopicExportFailure('Topic_export_unavailable');}
        if($cursor===''){return array('status'=>'missing');}
        $transfer=true;return array('status'=>'ok','stream'=>$stream,'bytes'=>$bytes,'disposition'=>phpbb_topic_export_filename($info['topic_title'],$topic_id));
    }finally{$db=$caller_db;$userdata=$caller_user;if(!$transfer&&is_resource($stream)){fclose($stream);}if($reader!==null){$reader->release();}}
}
