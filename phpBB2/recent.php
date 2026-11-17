<?php
// ############         Edit below         ########################################
$topic_length = 40;	// length of topic title
$topic_limit = 10;	// limit of displayed topics per page
$special_forums = 0;	// specify forums ('0' = no; '1' = yes)
$forum_ids = '';		// IDs of forums; separate them with a comma
$set_mode = 'today';	// set default mode ('today', 'yesterday', 'last24', 'lastweek', 'lastXdays')
$set_days = '3';		// set default days (used for lastXdays mode)
// ############         Edit above         ########################################

define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path .'extension.inc');
include($phpbb_root_path .'common.'.$phpEx);
include_once($phpbb_root_path.'includes/functions_color_groups.'.$phpEx);
require_once $phpbb_root_path.'includes/functions_recent_topics.'.$phpEx;

$userdata = session_pagestart($user_ip, PAGE_RECENT);
init_userprefs($userdata);

$start = (isset($_GET['start']) && is_scalar($_GET['start'])) ? max(0, min(1000000, intval($_GET['start']))) : 0;

$mode = $set_mode;
if (isset($_GET['mode']) && is_scalar($_GET['mode']))
{
	$mode = (string) $_GET['mode'];
}
else if (isset($_POST['mode']) && is_scalar($_POST['mode']))
{
	$mode = (string) $_POST['mode'];
}

$valid_modes = array('today', 'yesterday', 'last24', 'lastweek', 'lastXdays');
if (!in_array($mode, $valid_modes, true))
{
	$mode = $set_mode;
}

$amount_days = intval($set_days);
if (isset($_GET['amount_days']) && is_scalar($_GET['amount_days']))
{
	$amount_days = intval($_GET['amount_days']);
}
else if (isset($_POST['amount_days']) && is_scalar($_POST['amount_days']))
{
	$amount_days = intval($_POST['amount_days']);
}
$amount_days = max(1, min(365, $amount_days));
$topic_length = max(1, min(200, intval($topic_length)));
$topic_limit = max(1, min(100, intval($topic_limit)));
$special_forums = ((int) $special_forums === 1);

$page_title = $lang['Recent_topics'];
include($phpbb_root_path .'includes/page_header.'.$phpEx);

$sql_auth = "SELECT * FROM ". FORUMS_TABLE;
if( !$result_auth = $db->sql_query($sql_auth) )
{
	message_die(GENERAL_ERROR, 'could not query forums information.', '', __LINE__, __FILE__, $sql_auth);
}
$forums = array();
while( $row_auth = $db->sql_fetchrow($result_auth) )
{
	$forums[] = $row_auth;
}
$db->sql_freeresult($result_auth);

$is_auth_ary = array();
$is_auth_ary = auth(AUTH_ALL, AUTH_LIST_ALL, $userdata);

$allowed_forums = array();
foreach($forums as$forum){$id=(int)$forum['forum_id'];if(isset($is_auth_ary[$id])&&!empty($is_auth_ary[$id]['auth_read'])&&!empty($is_auth_ary[$id]['auth_view'])){$allowed_forums[]=$id;}}
if($special_forums){$allowed_forums=array_values(array_intersect($allowed_forums,array_map('intval',explode(',',phpbb_sql_id_list($forum_ids)))));}
try{
    $period=phpbb_recent_period($mode,$amount_days,time(),$board_config['board_timezone']);
    $recent_page=phpbb_recent_topics($db,$allowed_forums,$period,$start,$topic_limit);
}catch(Exception$error){message_die(GENERAL_ERROR,'Could not obtain recent topic information.');}
catch(Error$error){message_die(GENERAL_ERROR,'Could not obtain recent topic information.');}
$line=$recent_page['rows'];$total_topics=$recent_page['total'];$start=$recent_page['start'];
$status_keys=array('today'=>'Recent_today','yesterday'=>'Recent_yesterday','last24'=>'Recent_last24','lastweek'=>'Recent_lastweek','lastXdays'=>'Recent_lastXdays');
$title_keys=array('today'=>'Recent_title_today','yesterday'=>'Recent_title_yesterday','last24'=>'Recent_title_last24','lastweek'=>'Recent_title_lastweek','lastXdays'=>'Recent_title_lastXdays');
$status=$lang[$status_keys[$mode]];$l_mode=$lang[$title_keys[$mode]];
if($mode==='lastXdays'){$status=sprintf($status,$amount_days);$l_mode=sprintf($l_mode,$amount_days);}
$template->assign_vars(array('STATUS'=>$status));

$template->set_filenames(array('body' => 'recent_body.tpl'));

$orig_word = array();
$replacement_word = array();
obtain_word_list($orig_word, $replacement_word);

$tracking_topics = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_t']) ) ? phpbb_tracking_cookie_array($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_t']) : array();
$tracking_forums = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_f']) ) ? phpbb_tracking_cookie_array($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_f']) : array();
$tracking_topics = is_array($tracking_topics) ? $tracking_topics : array();
$tracking_forums = is_array($tracking_forums) ? $tracking_forums : array();
$posts_per_page = max(1, intval($board_config['posts_per_page']));
for( $i = 0; $i < count($line); $i++ )
{
	$forum_id = (int) $line[$i]['forum_id'];
	$forum_url = append_sid("viewforum.$phpEx?". POST_FORUM_URL ."=$forum_id");
	$topic_id = (int) $line[$i]['topic_id'];
	$topic_url = append_sid("viewtopic.$phpEx?". POST_TOPIC_URL ."=$topic_id");

	$word_censor = ( count($orig_word) ) ? preg_replace($orig_word, $replacement_word, $line[$i]['topic_title']) : $line[$i]['topic_title'];
	$topic_title = phpbb_text_excerpt(html_entity_decode($word_censor, ENT_QUOTES, 'UTF-8'), $topic_length);

	$topic_type =  ( $line[$i]['topic_type'] == POST_ANNOUNCE ) ? $lang['Topic_Announcement'] .' ': '';
	$topic_type .= ( $line[$i]['topic_type'] == POST_GLOBAL_ANNOUNCE ) ? $lang['Topic_Global_Announcement'] .' ': '';
	$topic_type .= ( $line[$i]['topic_type'] == POST_STICKY ) ? $lang['Topic_Sticky'] .' ': '';
	$topic_type .= ( $line[$i]['topic_vote'] ) ? $lang['Topic_Poll'] .' ': '';

	$views = $line[$i]['topic_views'];
	$replies = $line[$i]['topic_replies'];
	if( ( $replies + 1 ) > $posts_per_page )
	{
		$total_pages = ceil( ( $replies + 1 ) / $posts_per_page );
		$goto_page = ' [ ';
		$times = '1';
		for( $j = 0; $j < $replies + 1; $j += $posts_per_page )
		{
			$goto_page .= '<a href="'. append_sid("viewtopic.$phpEx?". POST_TOPIC_URL ."=". $topic_id ."&amp;start=$j") .'">'. $times .'</a>';
			if( $times == '1' && $total_pages > '4' )
			{
				$goto_page .= ' ... ';
				$times = $total_pages - 3;
				$j += ( $total_pages - 4 ) * $posts_per_page;
			}
			else if( $times < $total_pages )
			{
				$goto_page .= ', ';
			}
			$times++;
		}
		$goto_page .= ' ] ';
	}
	else
	{
		$goto_page = '';
	}

	if( $line[$i]['topic_status'] == TOPIC_LOCKED )
	{
		$folder = $images['folder_locked'];
		$folder_new = $images['folder_locked_new'];
	}
	else if( $line[$i]['topic_type'] == POST_ANNOUNCE )
	{
		$folder = $images['folder_announce'];
		$folder_new = $images['folder_announce_new'];
	}
	else if( $line[$i]['topic_type'] == POST_GLOBAL_ANNOUNCE )
	{
		$folder = $images['folder_global_announce'];
		$folder_new = $images['folder_global_announce_new'];
	}
	else if( $line[$i]['topic_type'] == POST_STICKY )
	{
		$folder = $images['folder_sticky'];
		$folder_new = $images['folder_sticky_new'];
	}
	else
	{
		if( $replies >= $board_config['hot_threshold'] )
		{
			$folder = $images['folder_hot'];
			$folder_new = $images['folder_hot_new'];
		}
		else
		{
			$folder = $images['folder'];
			$folder_new = $images['folder_new'];
		}
	}

	$newest_img = '';
	if( $userdata['session_logged_in'] )
	{
		if( $line[$i]['post_time'] > $userdata['user_lastvisit'] ) 
		{
			if( !empty($tracking_topics) || !empty($tracking_forums) || isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_f_all']) )
			{
				$unread_topics = true;
				if( !empty($tracking_topics[$topic_id]) )
				{
					if( $tracking_topics[$topic_id] >= $line[$i]['post_time'] )
					{
						$unread_topics = false;
					}
				}
				if( !empty($tracking_forums[$forum_id]) )
				{
					if( $tracking_forums[$forum_id] >= $line[$i]['post_time'] )
					{
						$unread_topics = false;
					}
				}
				if( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_f_all']) )
				{
					if( $HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_f_all'] >= $line[$i]['post_time'] )
					{
						$unread_topics = false;
					}
				}

				if( $unread_topics )
				{
					$folder_image = $folder_new;
					$folder_alt = $lang['New_posts'];
					$newest_img = '<a href="'. append_sid("viewtopic.$phpEx?". POST_TOPIC_URL ."=$topic_id&amp;view=newest") .'"><img src="'. $images['icon_newest_reply'] .'" alt="'. $lang['View_newest_post'] .'" title="'. $lang['View_newest_post'] .'" border="0" /></a> ';
				}
				else
				{
					$folder_image = $folder;
					$folder_alt = ( $line[$i]['topic_status'] == TOPIC_LOCKED ) ? $lang['Topic_locked'] : $lang['No_new_posts'];
					$newest_img = '';
				}
			}
			else
			{
				$folder_image = $folder_new;
				$folder_alt = ( $line[$i]['topic_status'] == TOPIC_LOCKED ) ? $lang['Topic_locked'] : $lang['New_posts'];
				$newest_img = '<a href="'. append_sid("viewtopic.$phpEx?". POST_TOPIC_URL ."=$topic_id&amp;view=newest") .'"><img src="'. $images['icon_newest_reply'] .'" alt="'. $lang['View_newest_post'] .'" title="'. $lang['View_newest_post'] .'" border="0" /></a> ';
			}
		}
		else 
		{
			$folder_image = $folder;
			$folder_alt = ( $line[$i]['topic_status'] == TOPIC_LOCKED ) ? $lang['Topic_locked'] : $lang['No_new_posts'];
			$newest_img = '';
		}
	}
	else
	{
		$folder_image = $folder;
		$folder_alt = ( $line[$i]['topic_status'] == TOPIC_LOCKED ) ? $lang['Topic_locked'] : $lang['No_new_posts'];
		$newest_img = '';
	}
			
    $first_deleted=!empty($line[$i]['first_post_deleted']);
    $first_time=!$first_deleted&&!empty($line[$i]['first_post_id'])?create_date($board_config['default_dateformat'],$line[$i]['first_post_time'],$board_config['board_timezone']):'';
    if($first_deleted){$first_author=htmlspecialchars($lang['Post_deleted_placeholder'],ENT_QUOTES,'UTF-8');}
    elseif((int)$line[$i]['first_poster_id']>0){$first_author='<a href="'.append_sid("profile.$phpEx?mode=viewprofile&amp;".POST_USERS_URL.'='.(int)$line[$i]['first_poster_id']).'" class="gensmall">'.color_group_colorize_name((int)$line[$i]['first_poster_id'],true).'</a>';}
    elseif((int)$line[$i]['first_actor_id']>0){$first_author=htmlspecialchars($lang['Former_member'],ENT_QUOTES,'UTF-8');}
    else{$first_author=phpbb_post_author_name($line[$i],'first_poster_name','first_poster_id','first_poster_removed');}
    $last_time=create_date($board_config['default_dateformat'],$line[$i]['post_time'],$board_config['board_timezone']);
    if((int)$line[$i]['last_poster_id']>0){$last_author='<a href="'.append_sid("profile.$phpEx?mode=viewprofile&amp;".POST_USERS_URL.'='.(int)$line[$i]['last_poster_id']).'" class="gensmall">'.color_group_colorize_name((int)$line[$i]['last_poster_id'],true).'</a>';}
    elseif((int)$line[$i]['poster_id']>0){$last_author=htmlspecialchars($lang['Former_member'],ENT_QUOTES,'UTF-8');}
    else{$last_author=phpbb_post_author_name($line[$i],'last_poster_name','last_poster_id','last_poster_removed');}
	$last_url = '<a href="'. append_sid("viewtopic.$phpEx?". POST_POST_URL .'='. $line[$i]['topic_last_post_id']) .'#'. $line[$i]['topic_last_post_id'] .'"><img src="'. $images['icon_latest_reply'] .'" alt="'. $lang['View_latest_post'] .'" title="'. $lang['View_latest_post'] .'" border="0" /></a>';
	$forum_name = phpbb_profile_text(phpbb_text_excerpt($line[$i]['forum_name'], intval($board_config['last_topic_title_length'])));

	$template->assign_block_vars('recent', array(
		'ROW_CLASS' => ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'],
		'TOPIC_TITLE' => phpbb_profile_text($topic_title),
		'TOPIC_TYPE' => $topic_type,
		'GOTO_PAGE' => $goto_page,
		'L_VIEWS' => $lang['Views'],
		'VIEWS' => $views,
		'L_REPLIES' => $lang['Replies'],
		'REPLIES' => $replies,
		'NEWEST_IMG' => $newest_img,
		'TOPIC_FOLDER_IMG' => $folder_image,
		'TOPIC_FOLDER_ALT' => $folder_alt,
		'FIRST_TIME' => $first_time!==''?sprintf($lang['Recent_first'], $first_time):'',
		'FIRST_AUTHOR' => $first_deleted?$first_author:sprintf($lang['Recent_first_poster'], $first_author),
		'LAST_TIME' => $last_time,
		'LAST_AUTHOR' => $last_author,
		'LAST_URL' => $last_url,
		'FORUM_NAME' => $forum_name,
		'U_VIEW_FORUM' => $forum_url,
		'U_VIEW_TOPIC' => $topic_url,
	));
}

$pagination=generate_pagination("recent.$phpEx?amount_days=".intval($amount_days)."&amp;mode=".urlencode($mode),$total_topics,$topic_limit,$start).'&nbsp;';

if( $total_topics == '0' )
{
	$template->assign_block_vars('switch_no_topics', array());
}

$template->assign_vars(array(
	'L_RECENT_TITLE' => ( $total_topics == '1' ) ? sprintf($lang['Recent_title_one'], $total_topics, $l_mode) : sprintf($lang['Recent_title_more'], $total_topics, $l_mode),
	'L_TODAY' => $lang['Recent_today'],
	'L_YESTERDAY' => $lang['Recent_yesterday'],
	'L_LAST24' => $lang['Recent_last24'],
	'L_LASTWEEK' => $lang['Recent_lastweek'],
	'L_LAST' => $lang['Recent_last'],
	'L_DAYS' => $lang['Recent_days'],
	'L_SELECT_MODE' => $lang['Recent_select_mode'],
	'L_SHOWING_POSTS' => $lang['Recent_showing_posts'],
	'L_NO_TOPICS' => $lang['Recent_no_topics'],
	'AMOUNT_DAYS' => $amount_days,
	'FORM_ACTION' => append_sid("recent.$phpEx"),
	'RECENT_SESSION_FIELD' => isset($userdata['session_id'])?'<input type="hidden" name="sid" value="'.htmlspecialchars($userdata['session_id'],ENT_QUOTES,'UTF-8').'" />':'',
	'U_RECENT_TODAY' => append_sid("recent.$phpEx?mode=today"),
	'U_RECENT_YESTERDAY' => append_sid("recent.$phpEx?mode=yesterday"),
	'U_RECENT_LAST24' => append_sid("recent.$phpEx?mode=last24"),
	'U_RECENT_LASTWEEK' => append_sid("recent.$phpEx?mode=lastweek"),
	'PAGINATION' => ( $total_topics != '0' ) ? $pagination : '',
	'PAGE_NUMBER' => ( $total_topics != '0' ) ? sprintf($lang['Page_of'], ( floor( $start / $topic_limit ) + 1 ), ceil( $total_topics / $topic_limit )) : '',
));

$template->pparse('body');
include($phpbb_root_path .'includes/page_tail.'.$phpEx);
?>
