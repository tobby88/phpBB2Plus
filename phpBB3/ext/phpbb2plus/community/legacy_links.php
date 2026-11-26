<?php
// These old public entry points also occur in BBCode and external bookmarks.
// Only send read requests to local destinations. phpBB checks access at the destination.
function phpbb2plus_redirect_legacy($entry, array $query)
{
	$method = $_SERVER['REQUEST_METHOD'] ?? '';
	$read_form = in_array($entry, array('album_search', 'album_personal_index', 'kb_search', 'arcade_highscore'), true);
	$sort_redirect = $entry === 'album_personal_index' || $entry === 'arcade_highscore';
	if ($entry === 'uacp' && $method === 'POST' && !array_diff_key($_POST, array_flip(array('u','mode','order','start','sid','submit'))))
	{
		$read_form = $sort_redirect = true;
		$query = array_intersect_key($_POST, array_flip(array('u','mode','order','start'))) + $query;
	}
	if ($entry === 'topic_view_users' && $method === 'POST')
	{
		$read_form = $sort_redirect = true;
		$query = array_intersect_key($_POST, array_flip(array('t', 'mode', 'order'))) + $query;
	}
	if ($entry === 'links' && $method === 'POST' && !isset($query['action']))
	{
		// Directory searches and sorting use the original read-only form body.
		$read_form = true;
	}
	if ($entry === 'recent' && $method === 'POST')
	{
		$read_form = $sort_redirect = true;
		$query += array_intersect_key($_POST, array_flip(array('mode', 'amount_days')));
	}
	if ($entry === 'arcade_modcp' && $method === 'POST')
	{
		// Old selector forms can open a list. A score change needs a new native confirmation.
		$read_form = $sort_redirect = in_array($_POST['action'] ?? '', array('', 'scores', 'at_scores'), true) &&
			!isset($_POST['confirm']) && !isset($_POST['player_id']);
		$query = array_intersect_key($_POST, array_flip(array('mode','cat_id','game_id','action','start'))) + $query;
	}
	if ($entry === 'arcade_highscore' && $method === 'POST')
	{
		$query = array_intersect_key($_POST, array_flip(array('month', 'date'))) + $query;
	}
	if ($entry === 'activity' && $method === 'POST')
	{
		$mode = $_POST['mode'] ?? $query['mode'] ?? '';
		$filters = array_intersect_key($_POST, array_flip(array('search_word', 'search_type', 'sort_mode', 'order')));
		if ($filters && in_array($mode, array('', 'cat', 'search', 'fav'), true) && !isset($_POST['game']) && !isset($_POST['stats']))
		{
			$read_form = $sort_redirect = true;
			$query = $filters + array_intersect_key($_POST, array_flip(array('cat_id', 'mode', 'start'))) + $query;
		}
	}
	if ($entry === 'kb_search' && $method === 'POST')
	{
		$query = array_intersect_key($_POST, array_flip(array('search_keywords', 'search_terms'))) + $query;
	}
	if ($entry === 'arcade_comment' && $method === 'POST' && isset($_POST['sort_order']) &&
		!array_diff_key($_POST, array_flip(array('sort_order', 'game_id', 'comment_id', 'start', 'submit'))) && empty($query['mode']))
	{
		$read_form = $sort_redirect = true;
		$query += array_intersect_key($_POST, array_flip(array('sort_order', 'game_id', 'comment_id', 'start')));
	}
	if ($method === 'POST' && in_array($entry, array('album', 'album_cat'), true))
	{
		// The original gallery took the album identity from the form first and
		// kept sorting and pagination from the URL when both were supplied.
		$identity = array_intersect_key($_POST, array_flip(array('user_id', 'cat_id', 'mode', 'type')));
		$sort = array_intersect_key($_POST, array_flip(array('sort_method', 'sort_order', 'start')));
		$read_form = $sort_redirect = (bool) ($identity || $sort);
		$query = $identity + $query + $sort;
	}
	if ($entry === 'dload')
	{
		// The original form posted its action to the bare dload.php address.
		$action = $query['action'] ?? '';
		if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] !== '') { $action = $_POST['action']; }
		$sort_redirect = in_array($action, array('viewall', 'category'), true);
		$read_form = $action === 'search' || $sort_redirect;
		if ($read_form) { $query['action'] = $action; }
		if ($sort_redirect && $method === 'POST')
		{
			$query = array_intersect_key($_POST, array_flip(array('cat_id', 'sort_method', 'sort_order', 'start'))) + $query;
		}
		if ($action === 'mcp' && $method === 'POST')
		{
			// The separate pa_mcp.tpl selector posted its view and category. Approval forms use the native review.
			$fields = array_flip(array('action','mode','mode_js','cat_id','cat_js_id','sort_method','sort_order','start','go','sid'));
			$read_form = $sort_redirect = isset($_POST['mode_js']) && !array_diff_key($_POST, $fields);
			if ($read_form) { $query = array_intersect_key($_POST, $fields) + $query; }
		}
	}
	if (!in_array($method, array('GET', 'HEAD'), true) && !($read_form && $method === 'POST'))
	{
		header($read_form ? 'Allow: GET, HEAD, POST' : 'Allow: GET, HEAD');
		http_response_code(405);
		return;
	}
	$positive = static function ($key) use ($query) {
		$value = $query[$key] ?? '';
		return is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) ? (int) $value : 0;
	};
	$extra = array();
	$anchor = '';
	$path = '';
	$base = './app.php/';
	switch ($entry)
	{
		case 'birthday_popup':
			$path = 'birthday';
			break;
		case 'topic_view_users':
			if (!($id = $positive('t'))) { break; }
			$path = 'topic/' . $id . '/readers';
			if (in_array($query['mode'] ?? '', array('username','email','joined','topic_time','topic_count','website','topten'), true)) { $extra['mode'] = $query['mode']; }
			if (in_array($query['order'] ?? '', array('ASC','DESC'), true)) { $extra['order'] = $query['order']; }
			header('Cache-Control: private, no-store');
			break;
		case 'topic_watch':
			// Old notification emails contain only the topic number. The native
			// form identifies the current member when they confirm unsubscribing.
			$topic = $query['t'] ?? '';
			if (!is_string($topic) || !preg_match('/^0*([1-9][0-9]{0,7})$/D', $topic, $number) || (int) $number[1] > 16777215) { break; }
			$base = './';
			$path = 'viewtopic.php';
			$extra = array('t' => (int) $number[1], 'unwatch' => 'topic');
			header('Cache-Control: private, no-store');
			break;
		case 'link_register':
			$path = 'links/submit';
			break;
		case 'link_logos':
			$path = 'links/logos';
			break;
		case 'links':
			$path = 'links';
			if (($query['action'] ?? '') === 'go' && ($id = $positive('link_id'))) { $path .= '/visit/' . $id; break; }
			if (in_array($query['t'] ?? '',array('index','new','pop','sub_pages','search'),true)) { $extra['t'] = $query['t']; }
			if ($category = $positive('cat')) { $extra['cat'] = $category; }
			if (in_array($query['mode'] ?? '',array('link_hits','link_joined','link_title','link_desc'),true)) { $extra['mode'] = $query['mode']; }
			if (in_array($query['order'] ?? '',array('ASC','DESC'),true)) { $extra['order'] = $query['order']; }
			if (isset($query['search_keywords']) && is_string($query['search_keywords']) && preg_match('/^.{0,100}$/usD',$query['search_keywords']))
			{ $extra['search_keywords'] = $query['search_keywords']; }
			header('Cache-Control: private, no-store');
			break;
		case 'arcade_modcp':
			$category = $query['cat_id'] ?? '0';
			$action = $query['action'] ?? '';
			if (($query['mode'] ?? '') !== 'mod' || !is_string($category) || !preg_match('/^(?:-1|0|[1-9][0-9]{0,8})$/D', $category) ||
				!in_array($action, array('', 'scores', 'at_scores', 'delete_score', 'delete_at_score'), true)) { break; }
			$extra['category'] = (int) $category;
			if ($action !== '')
			{
				if (!($extra['game'] = $positive('game_id'))) { break; }
				$extra['board'] = in_array($action, array('at_scores', 'delete_at_score'), true) ? 'all' : 'current';
			}
			if ($start = $positive('start')) { $extra['start'] = $start; }
			header('Cache-Control: private, no-store');
			$path = 'arcade/moderation';
			break;
		case 'absence_notify_popup':
			$path = 'absence';
			break;
		case 'ct_login_history':
			$base = './';
			$path = 'ucp.php';
			$extra = array('i' => '-phpbb2plus-community-ucp-logins_module', 'mode' => 'history');
			break;
		case 'arcade_tournament':
			$mode = $query['mode'] ?? '';
			if ($mode === '') { $path = 'arcade/tournaments'; }
			elseif ($mode === 'champions') { $path = 'arcade/tournaments/champions'; }
			elseif ($mode === 'tour' && ($id = $positive('id'))) { $path = 'arcade/tournament/' . $id; }
			elseif ($mode === 'game' && ($id = $positive('id')) && ($game = $positive('game_id')))
			{
				$path = 'arcade/tournament/' . $id;
				$anchor = '#game-' . $game;
			}
			break;
		case 'arcade_point_scores':
			$path = 'arcade/points';
			break;
		case 'arcade_rate':
			if ($id = $positive('game_id')) { $path = 'arcade/legacy/rating/' . $id; }
			break;
		case 'arcade_comment':
			$mode = $query['mode'] ?? '';
			if (!in_array($mode, array('', 'add_comment', 'edit', 'delete'), true)) { break; }
			foreach (array('game_id' => 'game', 'comment_id' => 'comment') as $old => $key)
			{
				if ($number = $positive($old)) { $extra[$key] = $number; }
				elseif (isset($query[$old]) && $query[$old] !== '0') { break 2; }
			}
			if (!$extra || (in_array($mode, array('edit','delete'), true) && empty($extra['comment']))) { break; }
			$path = 'arcade/legacy/comments';
			if ($mode !== '') { $extra['view'] = $mode === 'add_comment' ? 'add' : $mode; }
			if (($query['sort_order'] ?? '') === 'DESC') { $extra['order'] = 'desc'; }
			break;
		case 'arcade_highscore':
			$path = 'arcade/monthly';
			if (is_string($query['month'] ?? null) && preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D', $query['month'])) { $extra['month'] = $query['month']; }
			elseif ($months_ago = $positive('date')) { $extra['date'] = min(120, $months_ago); }
			break;
		case 'activity':
			$mode = $query['mode'] ?? '';
			$id = $positive('id');
			if (in_array($mode, array('', 'cat', 'fav', 'search'), true))
			{
				$cat = $positive('cat_id');
				if (!$cat && isset($query['cat_id']) && $query['cat_id'] !== '0' && $query['cat_id'] !== '') { break; }
				$path = $mode === 'fav' ? 'arcade/favourites' : ($cat ? 'arcade/category/' . $cat : 'arcade');
				$word = $query['search_word'] ?? '';
				if (is_string($word) && trim($word) !== '' && strlen($word) <= 1020)
				{
					$extra['search_word'] = trim($word);
					header('Cache-Control: private, no-store');
				}
				if (in_array($query['search_type'] ?? '', array('1', '3'), true)) { $extra['search_type'] = $query['search_type']; }
				if (in_array($query['sort_mode'] ?? '', array('alphabetical','date_added','game_played','default','allow_guest',
					'game_instructions','game_charge','game_bonus','rating','comments'), true)) { $extra['sort_mode'] = $query['sort_mode']; }
				$order = $query['order'] ?? '';
				if (is_string($order) && in_array(strtoupper($order), array('ASC','DESC'), true)) { $extra['order'] = strtoupper($order); }
			}
			elseif ($id && in_array($mode,array('game','stats','highscore','at_highscore'),true))
			{
				$path = 'arcade/game/' . $id;
				if ($mode === 'highscore' || $mode === 'at_highscore') { $path .= '/scores/' . ($mode === 'highscore' ? 'current' : 'all'); }
			}
			elseif ($mode === 'game_stats' && $id && $id <= 6)
			{
				$source = $positive('user_id');
				if (!$source && isset($query['user_id'])) { break; }
				$path = 'arcade/legacy/player/' . $source . '/awards/' . ($id <= 3 ? 'all' : 'current');
				$extra['place'] = $id <= 3 ? $id : $id-3;
			}
			break;
		case 'banner':
			if ($id = $positive('banner_id')) { $path = 'banners/' . $id . '/visit'; }
			break;
		case 'groupcp':
			if (!isset($query['g']))
			{
				$base = './'; $path = 'ucp.php'; $extra = array('i' => 'ucp_groups', 'mode' => 'membership');
			}
			elseif ($group = $positive('g'))
			{
				$path = 'legacy/group/' . $group . '/' . (isset($query['validate']) ? 'requests' : 'members') . '/' . $positive('start');
			}
			break;
		case 'download':
			if ($id = $positive('id')) { $path = 'legacy/attachment/' . $id . '/' . ($positive('thumb') ? 'thumb' : 'file'); }
			break;
		case 'ranks':
			$path = 'ranks';
			break;
		case 'attach_rules':
			$forum = $query['f'] ?? '0';
			if (is_string($forum) && preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $forum))
			{
				$path = 'attachment/rules/' . $forum;
			}
			break;
		case 'uacp':
			$id = $positive('u');
			if (!$id || $id > 8388607) { break; }
			$sorts = array('real_filename'=>'a', 'filename'=>'a', 'a.real_filename'=>'a', 'comment'=>'b', 'extension'=>'c',
				'filesize'=>'d', 'downloads'=>'e', 'download_count'=>'e', 'post_time'=>'f', 'filetime'=>'f');
			$mode = is_string($query['mode'] ?? null) ? $query['mode'] : '';
			$direction = ($query['order'] ?? '') === 'DESC' ? 'd' : 'a';
			$path = 'legacy/member/' . $id . '/attachments/' . ($sorts[$mode] ?? 'a') . '/' . $direction . '/' . $positive('start');
			unset($query['start']);
			header('Cache-Control: private, no-store');
			break;
		case 'smilie_creator':
			$path = 'smilie-creator';
			break;
		case 'recent':
			$path = 'topics/recent';
			if (in_array($query['mode'] ?? '', array('today','yesterday','last24','lastweek','lastXdays'), true))
			{ $extra['mode'] = $query['mode']; }
			if (isset($query['amount_days']) && is_string($query['amount_days']))
			{ $extra['amount_days'] = max(1, min(365, (int) $query['amount_days'])); }
			header('Cache-Control: private, no-store');
			break;
		case 'profile':
			$mode = $query['mode'] ?? '';
			if (in_array($mode, array('viewprofile','email'), true))
			{
				if ($id = $positive('u')) { $path = 'legacy/member/' . $id . '/' . $mode; }
			}
			elseif (in_array($mode, array('editprofile','signature','register','sendpassword','activate'), true))
			{
				$base = './';
				$path = 'ucp.php';
				$extra = in_array($mode, array('editprofile','signature'), true)
					? array('i'=>'ucp_profile', 'mode'=>$mode === 'editprofile' ? 'profile_info' : 'signature')
					: array('mode'=>$mode === 'activate' ? 'resend_act' : $mode);
			}
			break;
		case 'news_cats': $path = 'news/categories'; break;
		case 'hacks_list': $path = 'credits'; break;
		case 'kontakt': $base = './'; $path = 'memberlist.php'; $extra = array('mode' => 'contactadmin'); break;
		case 'statistics': $path = 'statistics'; break;
		case 'staff': $base = './'; $path = 'memberlist.php'; $extra = array('mode' => 'team'); break;
		case 'profile_options': $base = './'; $path = 'ucp.php'; $extra = array('i' => 'ucp_prefs', 'mode' => 'personal'); break;
		case 'change_password': $base = './'; $path = 'ucp.php'; $extra = array('i' => 'ucp_profile', 'mode' => 'reg_details'); break;
		case 'privmsg':
			$mode = $query['mode'] ?? '';
			$folder = $query['folder'] ?? 'inbox';
			if ($mode === '' || $mode === 'newpm')
			{
				if ($mode === 'newpm') { $folder = 'inbox'; }
				if ($folder === 'savebox') { $path = 'legacy/pm/savebox/' . $positive('start'); }
				elseif (in_array($folder, array('inbox', 'outbox', 'sentbox'), true))
				{
					$base = './'; $path = 'ucp.php'; $extra = array('i' => 'pm', 'mode' => 'view', 'folder' => $folder);
					if ($start = $positive('start')) { $extra['start'] = $start; }
				}
			}
			elseif ($mode === 'post')
			{
				if (!isset($query['u'])) { $base = './'; $path = 'ucp.php'; $extra = array('i' => 'pm', 'mode' => 'compose'); }
				elseif ($id = $positive('u')) { $path = 'legacy/pm/post/' . $id; }
			}
			elseif (in_array($mode, array('read', 'reply', 'quote', 'edit'), true) && ($id = $positive('p')))
			{
				$path = 'legacy/pm/' . $mode . '/' . $id;
			}
			break;
		case 'news_rss':
			$path = 'news/feed';
			if ($cat = $positive('cat_id')) { $extra['category'] = $cat; }
			break;
		case 'portal': case 'news_index':
			if (($query['news'] ?? '') === 'categories') { $path = 'news/categories'; }
			elseif (($query['news'] ?? '') === 'archives')
			{
				$path = 'news';
				foreach (array('year','month','day') as $unit) { if ($n = $positive($unit)) { $extra[$unit] = $n; } }
				if (isset($query['key']) && is_string($query['key'])) { $extra['q'] = $query['key']; }
			}
			elseif ($topic = $positive('topic_id') ?: $positive('news_id')) { $base = './'; $path = 'viewtopic.php'; $extra['t'] = $topic; }
			elseif ($cat = $positive('cat_id')) { $path = 'news/category/' . $cat; }
			else
			{
				$path = $entry === 'portal' ? 'portal' : 'news';
				if ($entry === 'portal' && isset($query['month']) && is_string($query['month']) && preg_match('/^-?[0-9]{1,4}$/D', $query['month']))
				{ $extra['month'] = max(-1200, min(1200, (int) $query['month'])); }
			}
			break;
		case 'calendar': case 'calendar_scheduler':
			$path = 'calendar';
			$fid = $query['fid'] ?? 'Root';
			if ($fid !== '' && $fid !== 'Root')
			{
				if (!is_string($fid) || !preg_match('/^([cf])([1-9][0-9]{0,8})$/D',$fid,$m)) { $path = ''; break; }
				$extra[$m[1] === 'c' ? 'old_cat' : 'forum'] = (int) $m[2];
			}
			if ($entry === 'calendar_scheduler')
			{
				$extra['view'] = ($query['mode'] ?? '') === 'hour' ? 'hour' : 'day';
				if ($d = $positive('d')) { $extra['moment'] = $d; }
			}
			elseif (isset($query['start']) && is_string($query['start']) && preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/D',$query['start'],$m))
			{
				$extra['date'] = $m[1] . '-' . $m[2] . '-' . $m[3];
			}
			break;
		case 'shoutbox': case 'shoutbox_view': case 'shoutbox_max': $path = 'shoutbox'; break;
		case 'album': case 'album_cat': case 'album_personal':
			$source = $query['user_id'] ?? '0';
			$cat = $positive('cat_id');
			$mode = is_string($query['mode'] ?? null) ? strtolower($query['mode']) : '';
			if ($entry !== 'album_personal' && ($entry === 'album' || $cat) && ($source === '-1' || $positive('user_id'))
				&& in_array($mode, array('all', 'list'), true))
			{
				$path = 'album/legacy/member/' . ($source === '-1' ? '0' : $source);
				$extra['mode'] = $mode;
				$type = is_string($query['type'] ?? null) ? strtolower($query['type']) : '';
				if ($mode === 'list' && in_array($type, array('pic', 'rating', 'comment'), true)) { $extra['type'] = $type; }
				if ($cat) { $extra['cat_id'] = $cat; }
			}
			elseif ($cat && $entry !== 'album_personal') { $path = 'album/category/' . $cat; }
			elseif ($entry === 'album_cat') { break; }
			elseif ($entry === 'album' && $source === '0') { $path = 'album'; }
			elseif ($source === '-1' || $source === '0' || $positive('user_id'))
			{
				$path = 'album/legacy/member/' . ($entry === 'album' && $source === '-1' ? '0' : $source);
			}
			break;
		case 'album_upload':
			if ($id = $positive('cat_id')) { $path = 'album/category/' . $id . '/upload'; }
			break;
		case 'album_hotornot': $path = 'album/hot-or-not'; break;
		case 'album_personal_cat_admin':
			$owner = $positive('user_id');
			if (isset($query['user_id']) && !$owner && $query['user_id'] !== '0') { break; }
			$action = $query['action'] ?? '';
			if (!in_array($action, array('', 'edit', 'delete'), true)) { break; }
			$category = $positive('cat_id');
			if (isset($query['cat_id']) && !$category && $query['cat_id'] !== '0') { break; }
			$path = 'album/legacy/member/' . $owner . '/manage';
			if ($action !== '') { $extra['action'] = $action; }
			if ($category) { $extra['cat_id'] = $category; }
			break;
		case 'album_comment_edit': case 'album_comment_delete':
			if ($comment = $positive('comment_id'))
			{
				$path = 'album/legacy/comment/' . $comment . ($entry === 'album_comment_edit' ? '/edit' : '/delete');
				if (($query['sort_order'] ?? '') === 'DESC') { $extra['sort_order'] = 'DESC'; }
			}
			break;
		case 'album_modcp':
			$picture = $positive('pic_id');
			$tasks = array('move' => 'move', 'lock' => 'lock', 'unlock' => 'unlock',
				'approval' => 'approve', 'unapproval' => 'unapprove', 'delete' => 'delete');
			$mode = $query['mode'] ?? '';
			if ($picture && is_string($mode) && isset($tasks[$mode]))
			{
				$path = 'album/picture/' . $picture . '/manage/' . $tasks[$mode];
			}
			elseif (!isset($query['pic_id']) && ($cat = $positive('cat_id')) && ($mode === '' || (is_string($mode) && isset($tasks[$mode]))))
			{
				$path = 'album/category/' . $cat . '/moderate';
				if (($query['sort_method'] ?? '') === 'pic_user_id') { $extra['sort_method'] = 'pic_user_id'; }
			}
			break;
		case 'album_personal_index':
			$path = 'album/members';
			$sort = $method === 'POST' ? $_POST + $query : $query;
			if (in_array($sort['mode'] ?? '', array('joindate', 'username', 'pics', 'last_pic'), true)) { $extra['mode'] = $sort['mode']; }
			if (in_array($sort['order'] ?? '', array('ASC', 'DESC'), true)) { $extra['order'] = $sort['order']; }
			break;
		case 'album_search':
			$path = 'album/search';
			foreach (array('mode','search') as $key)
			{
				if (isset($query[$key]) && is_string($query[$key])) { $extra[$key] = $query[$key]; }
			}
			if (isset($query['sid']) && is_string($query['sid']) && preg_match('/^[a-f0-9]{32}$/D', $query['sid'])) { $extra['sid'] = $query['sid']; }
			header('Cache-Control: private, no-store');
			break;
		case 'album_showpage': case 'album_pic': case 'album_picm': case 'album_thumbnail': case 'album_edit': case 'album_delete':
			$suffix = array('album_showpage' => '', 'album_pic' => '/full', 'album_picm' => '/medium', 'album_thumbnail' => '/small',
				'album_edit' => '/manage/edit', 'album_delete' => '/manage/delete');
			if ($id = $positive('pic_id')) { $path = 'album/picture/' . $id . $suffix[$entry]; }
			if ($entry === 'album_showpage')
			{
				if (isset($query['full'])) { $extra['full'] = 1; }
				if ($comment = $positive('comment_id'))
				{
					if (!$id) { $path = 'album/legacy/comment/' . $comment; }
					$anchor = '#comment-' . $comment;
				}
				if ($id && in_array($query['mode'] ?? '', array('next','previous'), true)) { $extra['mode'] = $query['mode']; }
				if (in_array($query['sort_order'] ?? '', array('ASC','DESC'), true)) { $extra['sort_order'] = $query['sort_order']; }
			}
			break;
		case 'dload':
			$action = $query['action'] ?? 'main';
			if (!is_string($action)) { break; }
			$parts = explode('?', $action, 2);
			$nested = isset($parts[1]) && preg_match('/^(?:cat_id|file_id)=([1-9][0-9]{0,9})$/D',$parts[1],$m) ? (int) $m[1] : 0;
			$id = $positive('file_id') ?: $nested;
			switch ($parts[0])
			{
				case 'main': $path = 'downloads'; break;
				case 'viewall': $path = 'downloads/all'; break;
				case 'stats': $path = 'downloads/stats'; break;
				case 'email':
					if ($file = $positive('file_id')) { $path = 'downloads/file/' . $file . '/recommend'; }
					break;
				case 'toplist':
					$path = 'downloads/toplist';
					if (in_array($query['mode'] ?? '', array('newest', 'downloads', 'rating'), true)) { $extra['mode'] = $query['mode']; }
					if (in_array($query['most_type'] ?? '', array('num', 'per'), true)) { $extra['most_type'] = $query['most_type']; }
					foreach (array('days', 'selected_date', 'most_num') as $key)
					{
						if ($value = $positive($key)) { $extra[$key] = $value; }
					}
					break;
				case 'user_upload':
					$file = $positive('file_id');
					$cat = $positive('cat_id');
					if ($file)
					{
						$path = 'downloads/file/' . $file . (($query['do'] ?? '') === 'delete' ? '/delete' : '/edit');
					}
					elseif (!isset($query['file_id']) && (!isset($query['cat_id']) || $cat) && empty($query['do']) && empty($query['mode']) && !isset($parts[1]))
					{ $path = $cat ? 'downloads/category/' . $cat . '/submit' : 'downloads/submit'; }
					break;
				case 'mcp':
					$views = array(''=>'overview', 'approved'=>'pending', 'broken'=>'broken', 'file_cat'=>'published', 'all_file'=>'published');
					$mode = $query['mode'] ?? '';
					$category_key = $mode === '' && isset($query['cat_js_id']) ? 'cat_js_id' : 'cat_id';
					if ($mode === '') { $mode = $query['mode_js'] ?? ''; }
					if (!is_string($mode) || !isset($views[$mode]) || (isset($query[$category_key]) && !($cat = $positive($category_key)))) { break; }
					$path = 'downloads/moderation';
					header('Cache-Control: private, no-store');
					if (isset($query[$category_key])) { $extra['cat_id'] = $cat; }
					if ($mode !== '') { $extra['status'] = $views[$mode]; }
					if (in_array($query['sort_method'] ?? '', array('file_name','file_time','file_dls','file_rating','rating','file_update_time'),true))
					{ $extra['sort_method'] = $query['sort_method']; }
					if (in_array($query['sort_order'] ?? '', array('ASC','DESC'),true)) { $extra['sort_order'] = $query['sort_order']; }
					if ($start = $positive('start')) { $extra['start'] = $start; }
					break;
				case 'search':
					$path = 'downloads/search';
					foreach (array('search_keywords', 'search_author') as $key)
					{
						if (isset($query[$key]) && is_string($query[$key])) { $extra[$key] = $query[$key]; }
					}
					$filters = array('search_terms' => array('all', 'any'), 'comments_search' => array('YES', 'NO'),
						'sort_method' => array('file_name', 'file_time', 'file_dls', 'file_rating', 'rating', 'file_update_time'),
						'sort_order' => array('ASC', 'DESC'));
					foreach ($filters as $key => $values)
					{
						if (in_array($query[$key] ?? '', $values, true)) { $extra[$key] = $query[$key]; }
					}
					foreach (array('cat_id', 'search_id') as $key) { if ($value = $positive($key)) { $extra[$key] = $value; } }
					if (isset($query['sid']) && is_string($query['sid']) && preg_match('/^[a-f0-9]{32}$/D', $query['sid'])) { $extra['sid'] = $query['sid']; }
					header('Cache-Control: private, no-store');
					header('Referrer-Policy: no-referrer');
					break;
				case 'category':
					if ($cat = $positive('cat_id') ?: $nested) { $path = 'downloads/category/' . $cat; }
					break;
				case 'file':
					if ($id) { $path = 'downloads/file/' . $id; }
					break;
				case 'rate':
					if ($id) { $path = 'downloads/file/' . $id; $anchor = '#dl-rate'; }
					break;
				case 'post_comment':
					if (!$id) { break; }
					if (($query['delete'] ?? '') === 'do')
					{
						if ($comment = $positive('cid')) { $path = 'downloads/file/' . $id . '/comment/' . $comment . '/delete'; }
					}
					else { $path = 'downloads/file/' . $id; $anchor = '#dl-comment'; }
					break;
				case 'download': case 'license':
					if (!$id) { break; }
					$m = $query['mirror_id'] ?? '';
					$path = 'downloads/file/' . $id . ($parts[0] === 'license' || $m === '-1' ? '/get/0' : ($positive('mirror_id') ? '/get/' . $positive('mirror_id') : '/download'));
					break;
			}
			if (in_array($parts[0], array('viewall', 'category'), true))
			{
				if (in_array($query['sort_method'] ?? '', array('file_name', 'file_time', 'file_dls', 'file_rating', 'rating', 'file_update_time'), true))
				{ $extra['sort_method'] = $query['sort_method']; }
				if (in_array($query['sort_order'] ?? '', array('ASC', 'DESC'), true)) { $extra['sort_order'] = $query['sort_order']; }
				header('Cache-Control: private, no-store');
			}
			break;
		case 'kb': case 'kb_search':
			$mode = $entry === 'kb_search' ? 'search' : ($query['mode'] ?? '');
			if ($mode === '' || $mode === 'search')
			{
				$path = 'knowledge';
				if (isset($query['search_keywords']) && is_string($query['search_keywords']) && $query['search_keywords'] !== '')
				{
					if (strlen($query['search_keywords']) > 960) { http_response_code(400); return; }
					$extra['q'] = $query['search_keywords'];
					$extra['match'] = ($query['search_terms'] ?? '') === 'all' ? 'all' : 'any';
				}
				elseif ($mode === 'search') { $anchor = '#kb-query'; }
				if ($mode === 'search') { header('Cache-Control: private, no-store'); }
			}
			elseif ($mode === 'add') { $path = 'knowledge/new'; }
			elseif ($mode === 'stats')
			{
				$list = $query['stats'] ?? 'latest';
				$path = 'knowledge/statistics/' . (in_array($list, array('latest','mostpopular','toprated'), true) ? $list : 'latest');
			}
			elseif ($mode === 'cat' && ($id = $positive('cat'))) { $path = 'knowledge/category/' . $id; }
			elseif (in_array($mode, array('article','edit','rate'), true) && ($id = $positive('k')))
			{
				$path = 'knowledge/article/' . $id . ($mode === 'edit' ? '/edit' : '');
				if ($page = $positive('page_num')) { $extra['page'] = $page; }
				if (!empty($query['print']) && is_string($query['print'])) { $extra['print'] = 1; }
				if ($mode === 'rate') { $anchor = '#kb-rate'; }
			}
			break;
	}
	if ($path === '') { http_response_code(404); return; }
	if ($entry === 'album_cat' || $entry === 'album_personal' || ($entry === 'album' && $path !== 'album') ||
		($entry === 'album_modcp' && !$picture))
	{
		if (in_array($query['sort_method'] ?? '', array('pic_time','pic_title','username','pic_view_count','rating','comments','new_comment'), true))
		{
			$extra['sort_method'] = $query['sort_method'];
		}
		if (in_array($query['sort_order'] ?? '', array('ASC','DESC'), true)) { $extra['sort_order'] = $query['sort_order']; }
	}
	if (!in_array($entry, array('calendar','groupcp','profile','profile_options','change_password','staff','privmsg','ct_login_history','topic_watch'), true) && ($start = $positive('start'))) { $extra['start'] = $start; }
	$search = $extra ? '?' . http_build_query($extra, '', '&', PHP_QUERY_RFC3986) : '';
	// The album and download search handlers accept the original POST bodies.
	$status = $method === 'POST' ? ($sort_redirect || $entry === 'kb_search' ? 303 : 307) : 302;
	header('Location: ' . $base . $path . $search . $anchor, true, $status);
}
