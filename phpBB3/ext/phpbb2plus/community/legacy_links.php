<?php
// These old public entry points also occur in BBCode and external bookmarks.
// Only send read requests to local destinations. phpBB checks access at the destination.
function phpbb2plus_redirect_legacy($entry, array $query)
{
	if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', array('GET', 'HEAD'), true))
	{
		header('Allow: GET, HEAD');
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
		case 'album': $path = 'album'; break;
		case 'album_cat': case 'album_upload':
			if ($id = $positive('cat_id')) { $path = 'album/category/' . $id . ($entry === 'album_upload' ? '/upload' : ''); }
			break;
		case 'album_showpage': case 'album_pic': case 'album_picm': case 'album_thumbnail': case 'album_edit': case 'album_delete':
			$suffix = array('album_showpage' => '', 'album_pic' => '/full', 'album_picm' => '/medium', 'album_thumbnail' => '/small',
				'album_edit' => '/manage/edit', 'album_delete' => '/manage/delete');
			if ($id = $positive('pic_id')) { $path = 'album/picture/' . $id . $suffix[$entry]; }
			if ($entry === 'album_showpage')
			{
				if (isset($query['full'])) { $extra['full'] = 1; }
				if ($comment = $positive('comment_id')) { $anchor = '#comment-' . $comment; }
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
				case 'main': case 'viewall': $path = 'downloads'; break;
				case 'search':
					$path = 'downloads';
					if (isset($query['search_keywords']) && is_string($query['search_keywords'])) { $extra['q'] = $query['search_keywords']; }
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
			break;
		case 'kb': case 'kb_search':
			$mode = $entry === 'kb_search' ? 'search' : ($query['mode'] ?? '');
			if ($mode === '' || $mode === 'search')
			{
				$path = 'knowledge';
				if (isset($query['search_keywords']) && is_string($query['search_keywords'])) { $extra['q'] = $query['search_keywords']; }
			}
			elseif ($mode === 'add') { $path = 'knowledge/new'; }
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
	if (!in_array($entry, array('calendar','profile'), true) && ($start = $positive('start'))) { $extra['start'] = $start; }
	$search = $extra ? '?' . http_build_query($extra, '', '&', PHP_QUERY_RFC3986) : '';
	header('Location: ' . $base . $path . $search . $anchor, true, 302);
}
