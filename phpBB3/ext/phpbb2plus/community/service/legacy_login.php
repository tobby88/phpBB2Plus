<?php
namespace phpbb2plus\community\service;

/** Rebuild a local return bookmark before phpBB opens a session. */
class legacy_login
{
	private const PAGES = array(
		'portal' => '', 'index' => '', 'memberlist' => '', 'statistics' => '', 'staff' => '', 'ranks' => '', 'hacks_list' => '',
		'viewforum' => 'f start', 'viewtopic' => 'f t p start', 'posting' => 'f t p', 'printview' => 't p', 'topic_view_users' => 't start',
		'search' => 'start', 'privmsg' => 'u p start', 'profile' => 'u', 'profile_options' => '', 'groupcp' => 'g start',
		'album' => 'cat_id user_id', 'album_cat' => 'cat_id user_id start', 'album_showpage' => 'pic_id', 'album_edit' => 'pic_id',
		'album_upload' => 'cat_id', 'album_personal' => 'user_id start', 'album_personal_cat_admin' => 'cat_id user_id',
		'album_modcp' => 'cat_id start', 'album_comment_edit' => 'comment_id', 'album_comment_delete' => 'comment_id',
		'album_hotornot' => '', 'album_personal_index' => 'start', 'arcade' => '', 'arcade_tournament' => 'id game_id',
		'arcade_comment' => 'game_id start', 'arcade_rate' => 'game_id', 'arcade_point_scores' => '', 'activity' => 'id cat_id',
		'dload' => 'file_id cat_id start', 'kb' => 'k cat page_num start', 'calendar' => '', 'shoutbox' => '',
		'smilie_creator' => '', 'ct_login_history' => '', 'uacp' => '', 'links' => 'cat start',
	);

	private const CHOICES = array(
		'viewtopic' => array('view' => array('unread','next','previous','print')),
		'posting' => array('mode' => array('post','reply','quote','edit')),
		'search' => array('search_id' => array('newposts','egosearch','unanswered')),
		'privmsg' => array('mode' => array('newpm','post','read','reply','quote','edit'), 'folder' => array('inbox','outbox','sentbox','savebox')),
		'profile' => array('mode' => array('viewprofile','editprofile','signature','email')),
		'arcade_tournament' => array('mode' => array('tour','game','champions')),
		'activity' => array('mode' => array('cat','game','fav','stats','highscore','at_highscore')),
		'dload' => array('action' => array('main','viewall','category','file','user_upload','mcp','toplist','stats')),
		'kb' => array('mode' => array('cat','article','add','edit','stats')),
	);

	public static function target($value, array $outer, $script)
	{
		if (!is_string($value) || $value === '' || strlen($value) > 1024 || preg_match('~[\x00-\x20\x7f\\\\]~', $value)) { return 'portal.php'; }
		$value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
		$value = preg_replace('/^([^?&#]+\.php)&(?=[a-z_]+=)/D', '$1?', $value, 1);
		$parts = parse_url($value);
		if (!$parts || isset($parts['scheme']) || isset($parts['host']) || empty($parts['path'])) { return 'portal.php'; }
		$path = $parts['path'];
		if ($path[0] === '/')
		{
			$prefix = rtrim(str_replace('\\', '/', dirname($script)), '/') . '/';
			if (strncmp($path, $prefix, strlen($prefix)) !== 0) { return 'portal.php'; }
			$path = substr($path, strlen($prefix));
		}
		if (strncmp($path, './', 2) === 0) { $path = substr($path, 2); }
		if ($path === 'admin/index.php' || $path === 'adm/index.php') { return 'adm/index.php'; }
		// Use the existing SEO resolver, including its checks for old category IDs.
		if (preg_match('~^(?:app\.php/)?(forums|(?:forumc?|viewforum|[fpn]topic|fpost)[1-9][a-z0-9,-]{0,96})\.html$~D', $path, $match))
		{
			return 'app.php/' . $match[1] . '.html';
		}
		if (!preg_match('/^([a-z_]+)\.php$/D', $path, $match)) { return 'portal.php'; }
		$page = $match[1];
		parse_str($parts['query'] ?? '', $params);
		// The original also put arguments after redirect=foo.php as outer GET controls.
		foreach ($outer as $name => $item)
		{
			if (is_string($name)) { $params += array(preg_replace('/^amp;/', '', $name) => $item); }
		}
		if (($page === 'search' && ($params['search_id'] ?? '') === 'bookmarks') ||
			($page === 'ucp' && ($params['i'] ?? '') === 'ucp_main' && ($params['mode'] ?? '') === 'bookmarks'))
		{
			return 'ucp.php?i=ucp_main&mode=bookmarks';
		}
		if ($page === 'album_delete') { $page = 'album_showpage'; }
		if ($page === 'topic_watch') { $page = 'viewtopic'; }
		if (!isset(self::PAGES[$page])) { return 'portal.php'; }
		if ($page === 'posting')
		{
			$mode = is_string($params['mode'] ?? null) ? $params['mode'] : '';
			$params['mode'] = array('newtopic' => 'post', 'editpost' => 'edit', 'topicreview' => 'reply')[$mode] ?? $mode;
			if (!in_array($params['mode'], self::CHOICES['posting']['mode'], true)) { $page = 'viewtopic'; }
		}
		if ($page === 'dload' && in_array($params['action'] ?? '', array('download','license','rate'), true)) { $params['action'] = 'file'; }
		$query = array();
		foreach (explode(' ', self::PAGES[$page]) as $key)
		{
			if ($key !== '' && is_string($params[$key] ?? null) && preg_match('/^0*([1-9][0-9]{0,8})$/D', $params[$key], $number)) { $query[$key] = $number[1]; }
		}
		foreach (self::CHOICES[$page] ?? array() as $key => $choices)
		{
			if (isset($params[$key]) && in_array($params[$key], $choices, true)) { $query[$key] = $params[$key]; }
		}
		if (($page === 'viewforum' && empty($query['f'])) || ($page === 'viewtopic' && empty($query['p']) && empty($query['t']))) { return 'portal.php'; }
		$anchor = '';
		if ($page === 'viewtopic')
		{
			if (preg_match('/^p?([1-9][0-9]{0,8})$/D', $parts['fragment'] ?? '', $id)) { $anchor = '#p' . $id[1]; }
			elseif (isset($query['p'])) { $anchor = '#p' . $query['p']; }
		}
		return $page . '.php' . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '') . $anchor;
	}
}
