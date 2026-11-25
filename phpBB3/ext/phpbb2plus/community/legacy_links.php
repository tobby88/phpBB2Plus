<?php
// These old public entry points also occur in BBCode and external bookmarks.
// Only send read requests to local destinations. phpBB checks access at the destination.
function phpbb2plus_redirect_legacy($entry, array $query)
{
	$method = $_SERVER['REQUEST_METHOD'] ?? '';
	$read_form = in_array($entry, array('album_search', 'album_personal_index'), true);
	$sort_redirect = $entry === 'album_personal_index';
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
		case 'activity':
			$mode = $query['mode'] ?? '';
			$id = $positive('id');
			if ($mode === '') { $path = ($cat = $positive('cat_id')) ? 'arcade/category/' . $cat : 'arcade'; }
			elseif ($mode === 'fav') { $path = 'arcade/favourites'; }
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
		case 'statistics': $path = 'statistics'; break;
		case 'staff': $base = './'; $path = 'memberlist.php'; $extra = array('mode' => 'team'); break;
		case 'profile_options': $base = './'; $path = 'ucp.php'; $extra = array('i' => 'ucp_prefs', 'mode' => 'personal'); break;
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
			else { $path = $entry === 'portal' ? 'portal' : 'news'; }
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
					if ($cat = $positive('cat_id')) { $path = 'downloads/category/' . $cat . '/review'; }
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
				case 'file': case 'rate': case 'post_comment':
					if ($id) { $path = 'downloads/file/' . $id; }
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
				if (isset($query['search_keywords']) && is_string($query['search_keywords'])) { $extra['q'] = $query['search_keywords']; }
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
	if (!in_array($entry, array('calendar','groupcp','profile','profile_options','staff','privmsg','ct_login_history'), true) && ($start = $positive('start'))) { $extra['start'] = $start; }
	$search = $extra ? '?' . http_build_query($extra, '', '&', PHP_QUERY_RFC3986) : '';
	// Only search forms need to retain their body at the destination.
	$status = $method === 'POST' ? ($sort_redirect ? 303 : 307) : 302;
	header('Location: ' . $base . $path . $search . $anchor, true, $status);
}
