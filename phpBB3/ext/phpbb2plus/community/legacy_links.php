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
	switch ($entry)
	{
		case 'portal': $path = 'portal'; break;
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
	if ($start = $positive('start')) { $extra['start'] = $start; }
	$search = $extra ? '?' . http_build_query($extra, '', '&', PHP_QUERY_RFC3986) : '';
	header('Location: ./app.php/' . $path . $search . $anchor, true, 302);
}
