<?php
// ############         Edit below         ########################################
$topic_length = '40';	// length of topic title
$topic_limit = '10';	// limit of displayed topics per page
$special_forums = '0';	// specify forums ('0' = no; '1' = yes)
$forum_ids = '';		// IDs of forums; separate them with a comma
$set_mode = 'today';	// set default mode ('today', 'yesterday', 'last24', 'lastweek', 'lastXdays')
$set_days = '3';		// set default days (used for lastXdays mode)
// ############         Edit above         ########################################

define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path .'extension.inc');
include($phpbb_root_path .'common.'.$phpEx);
include_once($phpbb_root_path.'includes/functions_color_groups.'.$phpEx);

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
$topic_limit = max(1, intval($topic_limit));

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

$except_forums = array();
for( $f = 0; $f < count($forums); $f++ )
{
	$current_forum_id = intval($forums[$f]['forum_id']);
	if (!isset($is_auth_ary[$current_forum_id]) || !$is_auth_ary[$current_forum_id]['auth_read'] || !$is_auth_ary[$current_forum_id]['auth_view'])
	{
		$except_forums[] = $current_forum_id;
	}
}

$except_forums_sql = count($except_forums) ? implode(',', array_unique($except_forums)) : '0';
$where_forums = 't.forum_id NOT IN (' . $except_forums_sql . ')';
if ($special_forums != '0')
{
	$selected_forums = array();
	foreach (preg_split('/[^0-9]+/', (string) $forum_ids, -1, PREG_SPLIT_NO_EMPTY) as $selected_forum_id)
	{
		$selected_forums[] = intval($selected_forum_id);
	}
	$where_forums .= count($selected_forums) ? ' AND t.forum_id IN (' . implode(',', array_unique($selected_forums)) . ')' : ' AND 1 = 0';
}
$sql_start = "SELECT t.*, p.poster_id, p.post_username AS last_poster_name, p.post_id, p.post_time, f.forum_name, f.forum_id, u.username AS last_poster, u.user_id AS last_poster_id, u2.username AS first_poster, u2.user_id AS first_poster_id, p2.post_username AS first_poster_name
	        FROM (". TOPICS_TABLE ." t, ". POSTS_TABLE ." p)
		LEFT OUTER JOIN ". POSTS_TABLE ." p2 ON (p2.post_id = t.topic_first_post_id)
		LEFT OUTER JOIN ". FORUMS_TABLE ." f ON (f.forum_id = p.forum_id )
		LEFT OUTER JOIN ". USERS_TABLE ." u ON (u.user_id = p.poster_id)
		LEFT OUTER JOIN ". USERS_TABLE ." u2 ON (u2.user_id = t.topic_poster)
	        WHERE $where_forums AND p.post_id = t.topic_last_post_id AND ";
$sql_end = "  ORDER BY t.topic_last_post_id DESC LIMIT " . intval($start) . ", " . intval($topic_limit);

switch( $mode )
{
	case 'today':
		$sql = $sql_start ."FROM_UNIXTIME(p.post_time,'%Y%m%d') - FROM_UNIXTIME(unix_timestamp(NOW()),'%Y%m%d') = 0". $sql_end;
		$template->assign_vars(array('STATUS' => $lang['Recent_today']));
		$where_count = "$where_forums AND FROM_UNIXTIME(p.post_time,'%Y%m%d') - FROM_UNIXTIME(unix_timestamp(NOW()),'%Y%m%d') = 0";
		$l_mode = $lang['Recent_title_today'];
		break;

	case 'yesterday':
		$sql = $sql_start ."FROM_UNIXTIME(p.post_time,'%Y%m%d') - FROM_UNIXTIME(unix_timestamp(NOW()),'%Y%m%d') = -1". $sql_end;
		$template->assign_vars(array('STATUS' => $lang['Recent_yesterday']));
		$where_count = "$where_forums AND FROM_UNIXTIME(p.post_time,'%Y%m%d') - FROM_UNIXTIME(unix_timestamp(NOW()),'%Y%m%d') = -1";
		$l_mode = $lang['Recent_title_yesterday'];
		break;

	case 'last24':
		$sql   = $sql_start ."UNIX_TIMESTAMP(NOW()) - p.post_time < 86400". $sql_end;
		$template->assign_vars(array('STATUS' => $lang['Recent_last24']));
		$where_count = "$where_forums AND UNIX_TIMESTAMP(NOW()) - p.post_time < 86400";
		$l_mode = $lang['Recent_title_last24'];
		break;

	case 'lastweek':
		$sql  = $sql_start ."UNIX_TIMESTAMP(NOW()) - p.post_time < 691200". $sql_end;
		$template->assign_vars(array('STATUS' => $lang['Recent_lastweek']));
		$where_count = "$where_forums AND UNIX_TIMESTAMP(NOW()) - p.post_time < 691200";
		$l_mode = $lang['Recent_title_lastweek'];
		break;

	case 'lastXdays':
		$sql    = $sql_start ."UNIX_TIMESTAMP(NOW()) - p.post_time < 86400 * " . intval($amount_days) . $sql_end;
		$template->assign_vars(array('STATUS' => sprintf($lang['Recent_lastXdays'], $amount_days)));
		$where_count = "$where_forums AND UNIX_TIMESTAMP(NOW()) - p.post_time < 86400 * " . intval($amount_days);
		$l_mode = sprintf($lang['Recent_title_lastXdays'], $amount_days);
		break;

	default:
		$message = $lang['Recent_wrong_mode'] .'<br /><br />'. sprintf($lang['Recent_click_return'], '<a href="'. append_sid("recent.$phpEx") .'">', '</a>') .'<br />'. sprintf($lang['Click_return_index'], '<a href="'. append_sid("index.$phpEx") .'">', '</a>');
		message_die(GENERAL_MESSAGE, $message);
		break;
}
if( !$result = $db->sql_query($sql) )
{
	message_die(GENERAL_ERROR, 'could not obtain main information.', '', __LINE__, __FILE__, $sql);
}
$line = array();
while( $row = $db->sql_fetchrow($result) )
{
	$line[] = $row;
}
$db->sql_freeresult($result);
		
$template->set_filenames(array('body' => 'recent_body.tpl'));

$orig_word = array();
$replacement_word = array();
obtain_word_list($orig_word, $replacement_word);

$tracking_topics = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_t']) ) ? phpbb_safe_unserialize($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_t']) : array();
$tracking_forums = ( isset($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_f']) ) ? phpbb_safe_unserialize($HTTP_COOKIE_VARS[$board_config['cookie_name'] .'_f']) : array();
$tracking_topics = is_array($tracking_topics) ? $tracking_topics : array();
$tracking_forums = is_array($tracking_forums) ? $tracking_forums : array();
$posts_per_page = max(1, intval($board_config['posts_per_page']));
for( $i = 0; $i < count($line); $i++ )
{
	$forum_id = $line[$i]['forum_id'];
	$forum_url = append_sid("viewforum.$phpEx?". POST_FORUM_URL ."=$forum_id");
	$topic_id = $line[$i]['topic_id'];
	$topic_url = append_sid("viewtopic.$phpEx?". POST_TOPIC_URL ."=$topic_id");

	$word_censor = ( count($orig_word) ) ? preg_replace($orig_word, $replacement_word, $line[$i]['topic_title']) : $line[$i]['topic_title'];
	$topic_title = ( strlen($line[$i]['topic_title']) < $topic_length ) ? $word_censor : substr(stripslashes($word_censor), 0, $topic_length) .'...';

	$topic_type =  ( $line[$i]['topic_type'] == POST_ANNOUNCE ) ? $lang['Topic_Announcement'] .' ': '';
	$topic_type .= ( $line[$i]['topic_type'] == POST_GLOBAL_ANNOUNCE ) ? $lang['Topic_global_announcement'] .' ': '';
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
			
	$first_time = create_date($board_config['default_dateformat'], $line[$i]['topic_time'], $board_config['board_timezone']);
    $first_style_color = color_group_colorize_name($line[$i]['first_poster_id'], true);
	$first_author = ( $line[$i]['first_poster_id'] != ANONYMOUS ) ? '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. $line[$i]['first_poster_id']) .'" class="gensmall">' . $first_style_color . ' </a>' : ( ($line[$i]['first_poster_name'] != '' ) ? htmlspecialchars($line[$i]['first_poster_name'], ENT_QUOTES, 'UTF-8') : $lang['Guest'] );
	$last_time = create_date($board_config['default_dateformat'], $line[$i]['post_time'], $board_config['board_timezone']);
    $last_style_color = color_group_colorize_name($line[$i]['last_poster_id'], true);
	$last_author = ( $line[$i]['last_poster_id'] != ANONYMOUS ) ? '<a href="'. append_sid("profile.$phpEx?mode=viewprofile&amp;". POST_USERS_URL .'='. $line[$i]['last_poster_id']) .'" class="gensmall">' . $last_style_color . ' </a>' : ( ($line[$i]['last_poster_name'] != '' ) ? htmlspecialchars($line[$i]['last_poster_name'], ENT_QUOTES, 'UTF-8') : $lang['Guest'] );
	$last_url = '<a href="'. append_sid("viewtopic.$phpEx?". POST_POST_URL .'='. $line[$i]['topic_last_post_id']) .'#'. $line[$i]['topic_last_post_id'] .'"><img src="'. $images['icon_latest_reply'] .'" alt="'. $lang['View_latest_post'] .'" title="'. $lang['View_latest_post'] .'" border="0" /></a>';
	$forum_name = htmlspecialchars($line[$i]['forum_name'], ENT_QUOTES, 'UTF-8');
	if ( strlen($forum_name) > (intval($board_config['last_topic_title_length'])-3) ) $forum_name = substr($forum_name, 0, intval($board_config['last_topic_title_length'])) . '...';

	$template->assign_block_vars('recent', array(
		'ROW_CLASS' => ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'],
		'TOPIC_TITLE' => htmlspecialchars($topic_title, ENT_QUOTES, 'UTF-8'),
		'TOPIC_TYPE' => $topic_type,
		'GOTO_PAGE' => $goto_page,
		'L_VIEWS' => $lang['Views'],
		'VIEWS' => $views,
		'L_REPLIES' => $lang['Replies'],
		'REPLIES' => $replies,
		'NEWEST_IMG' => $newest_img,
		'TOPIC_FOLDER_IMG' => $folder_image,
		'TOPIC_FOLDER_ALT' => $folder_alt,
		'FIRST_TIME' => sprintf($lang['Recent_first'], $first_time),
		'FIRST_AUTHOR' => sprintf($lang['Recent_first_poster'], $first_author),
		'LAST_TIME' => $last_time,
		'LAST_AUTHOR' => $last_author,
		'LAST_URL' => $last_url,
		'FORUM_NAME' => $forum_name,
		'U_VIEW_FORUM' => $forum_url,
		'U_VIEW_TOPIC' => $topic_url,
	));
}

$sql = "SELECT count(t.topic_id) AS total_topics FROM ". TOPICS_TABLE ." t , ". POSTS_TABLE ." p
           WHERE $where_count AND p.post_id = t.topic_last_post_id";
if( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, 'error getting total topics.', '', __LINE__, __FILE__, $sql);
}
$total_topics = 0;
$pagination = '';
if( $total = $db->sql_fetchrow($result) )
{
	$total_topics = intval($total['total_topics']);
	$pagination = generate_pagination("recent.$phpEx?amount_days=" . intval($amount_days) . "&amp;mode=" . urlencode($mode), $total_topics, $topic_limit, $start) .'&nbsp;';
}

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
	'PAGINATION' => ( $total_topics != '0' ) ? $pagination : '',
	'PAGE_NUMBER' => ( $total_topics != '0' ) ? sprintf($lang['Page_of'], ( floor( $start / $topic_limit ) + 1 ), ceil( $total_topics / $topic_limit )) : '',
));

$template->pparse('body');
include($phpbb_root_path .'includes/page_tail.'.$phpEx);
?>
