<?php
namespace phpbb2plus\gallery\controller;

class board
{
	private $access;
	private $db;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $pagination;
	private $prefix;
	private $byline;
	private $discussion;
	private $pictures;
	private $personal;

	public function __construct($access, $db, $user, $language, $template, $helper, $request, $pagination, $prefix, $discussion, $pictures, $byline, $personal)
	{
		$this->access = $access;
		$this->db = $db;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->request = $request;
		$this->pagination = $pagination;
		$this->prefix = $prefix;
		$this->byline = $byline;
		$this->discussion = $discussion;
		$this->pictures = $pictures;
		$this->personal = $personal;
	}

	private function breadcrumbs($cat = null)
	{
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_ALBUM'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_gallery_index')));
		$parents = array();
		while ($cat)
		{
			$parents[] = $cat;
			$cat = $cat['parent_id'] ? $this->access->category($cat['parent_id']) : null;
		}
		foreach (array_reverse($parents) as $category)
		{
			$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => utf8_htmlspecialchars($category['title']),
				'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_gallery_category', array('category' => $category['cat_id']))));
		}
	}

	private function page($url, $total, $limit)
	{
		$start = $this->pagination->validate_start(max(0, $this->request->variable('start', 0)), $limit, $total);
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $total, $limit, $start);
		return $start;
	}

	private function picture_grid($heading, $columns, $where, $order, $limit, $start = 0)
	{
		$this->template->assign_block_vars('album_grids', array('HEADING' => $this->language->lang($heading),
			'COLUMNS' => $columns, 'MOBILE_COLUMNS' => min(2, $columns)));
		if ($where === '') { return; }
		$rate = (int) $this->access->setting('rate', 1) === 1;
		$rating = $rate || $heading === 'PLUS_ALBUM_HIGHEST' ? ',(SELECT AVG(r.points) FROM ' . $this->prefix .
			'ratings r WHERE r.pic_id=p.pic_id AND r.points>0) AS rating' : '';
		$result = $this->db->sql_query_limit('SELECT p.pic_id,p.title,p.created_at,p.views,p.author_id,p.author_name,p.author_removed,p.storage_name,
			u.username,u.user_colour' . $rating . ' FROM ' . $this->prefix . 'pics p LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.author_id
			WHERE ' . $where . ' ORDER BY ' . $order, $limit, $start);
		while ($pic = $this->db->sql_fetchrow($result))
		{
			$params = array('picture' => (int) $pic['pic_id']);
			$this->template->assign_block_vars('album_grids.pictures', array('TITLE' => $pic['title'],
				'TIME' => $this->user->format_date($pic['created_at']), 'DATETIME' => gmdate('c', $pic['created_at']),
				'AUTHOR' => $this->byline->display($pic), 'VIEWS' => (int) $pic['views'], 'S_RATE' => $rate,
				'RATING' => $rate && $pic['rating'] !== null ? number_format((float) $pic['rating'], 1, $this->language->lang('PLUS_ALBUM_DECIMAL'), '') : '',
				'U_PICTURE' => $this->helper->route('phpbb2plus_gallery_picture', $params),
				'U_THUMB' => $pic['storage_name'] !== '' ? $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => 'small')) : ''));
		}
		$this->db->sql_freeresult($result);
	}

	private function home_grids()
	{
		$enabled = array();
		foreach (array('disp_late' => 'PLUS_ALBUM_RECENT', 'disp_high' => 'PLUS_ALBUM_HIGHEST', 'disp_rand' => 'PLUS_ALBUM_RANDOM') as $setting => $heading)
		{
			if ((int) $this->access->setting($setting, 1, 'sp') === 1) { $enabled[$setting] = $heading; }
		}
		if (!$enabled) { return; }
		$columns = max(1, min(20, (int) $this->access->setting('img_cols', 4, 'sp')));
		$rows = max(1, min(100, (int) $this->access->setting('img_rows', 2, 'sp')));
		$categories = $this->access->portal_categories(false, array());
		$where = '';
		if ($categories)
		{
			$pending = array();
			foreach ($categories as $id)
			{
				if ($this->access->pending_visible($this->access->category_row($id))) { $pending[] = $id; }
			}
			$where = $this->db->sql_in_set('p.cat_id', $categories) . " AND p.storage_name<>'' AND (p.approved=1" .
				($pending ? ' OR ' . $this->db->sql_in_set('p.cat_id', $pending) : '') . ')';
		}
		$random = in_array($this->db->get_sql_layer(), array('postgres', 'sqlite3'), true) ? 'RANDOM()' : 'RAND()';
		$sort = array('disp_late' => 'p.created_at DESC,p.pic_id DESC', 'disp_high' => 'rating DESC,' . $random, 'disp_rand' => $random);
		foreach ($enabled as $setting => $heading)
		{
			$this->picture_grid($heading, $columns, $where, $sort[$setting], min(100, $rows * $columns));
		}
	}

	public function index($category = 0)
	{
		$id = (int) $category;
		$cat = $id ? $this->access->category($id) : null;
		$this->breadcrumbs($cat);
		$result = $this->db->sql_query('SELECT c.*,u.username AS owner_name FROM ' . $this->prefix . 'cats c
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=c.owner_id AND ' . $this->db->sql_in_set('u.user_type', array(USER_NORMAL, USER_FOUNDER)) .
			' WHERE c.parent_id=' . $id . ' ORDER BY c.sort_order,c.cat_id');
		$children = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		foreach ($children as $child)
		{
			if (!$this->access->visible($child)) { continue; }
			$child = $this->access->category_labels($child);
			$this->template->assign_block_vars('album_categories', array('TITLE' => $child['title'], 'DESCRIPTION' => $child['description'],
				'OWNER' => !$id && (int) $child['owner_id'] > ANONYMOUS && $child['owner_name'] !== null ?
					html_entity_decode($child['owner_name'], ENT_QUOTES, 'UTF-8') : '',
				'U_CATEGORY' => $this->helper->route('phpbb2plus_gallery_category', array('category' => $child['cat_id']))));
		}
		if ($id)
		{
			$where = 'p.cat_id=' . $id . ($this->access->pending_visible($cat) ? '' : ' AND p.approved=1');
			$result = $this->db->sql_query('SELECT COUNT(*) AS count FROM ' . $this->prefix . 'pics p WHERE ' . $where);
			$total = (int) $this->db->sql_fetchfield('count');
			$this->db->sql_freeresult($result);
			$columns = max(1, min(20, (int) $this->access->setting('cols_per_page', 4)));
			$limit = max(1, min(60, (int) $this->access->setting('rows_per_page', 3) * $columns));
			$url = $this->helper->route('phpbb2plus_gallery_category', array('category' => $id));
			$start = $this->page($url, $total, $limit);
			$sort = array('pic_time' => 'created_at','pic_title' => 'title','pic_view_count' => 'views','pic_id' => 'pic_id');
			$order = $sort[$this->access->setting('sort_method', 'pic_time')] ?? 'created_at';
			$direction = strtoupper($this->access->setting('sort_order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
			if ($total) { $this->picture_grid('PLUS_ALBUM_PICTURES', $columns, $where, "p.$order $direction,p.pic_id $direction", $limit, $start); }
		}
		else { $this->home_grids(); }
		$title = $cat ? $cat['title'] : $this->language->lang('PLUS_ALBUM');
		$owner = $cat ? (int) $cat['owner_id'] : (int) $this->user->data['user_id'];
		if ($owner > ANONYMOUS && $this->personal->available($owner))
		{ $this->template->assign_var('U_ALBUM_PERSONAL', $this->helper->route('phpbb2plus_gallery_personal', array('owner' => $owner))); }
		if ($cat && empty($this->user->data['is_bot']) && $this->access->allows($cat, 'upload'))
		{
			$this->template->assign_var('U_ALBUM_UPLOAD', $this->helper->route('phpbb2plus_gallery_upload', array('category' => $id)));
		}
		$this->template->assign_vars(array('ALBUM_TITLE' => $title, 'ALBUM_DESCRIPTION' => $cat ? $cat['description'] : '', 'ALBUM_CATEGORY_ID' => $id));
		return $this->helper->render('@phpbb2plus_gallery/index.html', utf8_htmlspecialchars($title));
	}

	public function picture($picture)
	{
		list($pic, $cat) = $this->access->picture($picture);
		$this->breadcrumbs($cat);
		$this->language->add_lang('viewtopic');
		$id = (int) $pic['pic_id'];
		$params = array('picture' => $id);
		$link = $this->helper->route('phpbb2plus_gallery_picture', $params);
		$rate = (int) $this->access->setting('rate', 1) === 1;
		$comments = (int) $this->access->setting('comment', 1) === 1;
		if ($rate)
		{
			$result = $this->db->sql_query('SELECT COUNT(*) AS votes,AVG(points) AS rating FROM ' . $this->prefix . 'ratings WHERE points>0 AND pic_id=' . $id);
			$rating = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($rating['votes']) { $this->template->assign_var('ALBUM_RATING', $this->language->lang('PLUS_ALBUM_RATING',
				number_format((float) $rating['rating'], 1, $this->language->lang('PLUS_ALBUM_DECIMAL'), ''),
				(int) $this->access->setting('rate_scale', 10), $this->language->lang('PLUS_ALBUM_VOTES', (int) $rating['votes']))); }
		}
		if ($comments)
		{
			$result = $this->db->sql_query('SELECT COUNT(*) AS count FROM ' . $this->prefix . 'comments WHERE pic_id=' . $id);
			$total = (int) $this->db->sql_fetchfield('count');
			$this->db->sql_freeresult($result);
			$start = $this->page($link, $total, 20);
			$result = $this->db->sql_query_limit('SELECT c.*,u.username,u.user_colour,e.username AS editor_name,e.user_colour AS editor_colour
				FROM ' . $this->prefix . 'comments c LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=c.author_id
				LEFT JOIN ' . USERS_TABLE . ' e ON e.user_id=c.editor_id WHERE c.pic_id=' . $id . ' ORDER BY c.created_at,c.comment_id', 20, $start);
			while ($comment = $this->db->sql_fetchrow($result))
			{
				$edit = '';
				if ($comment['edit_count'])
				{
					$editor = (int) $comment['editor_id'] > ANONYMOUS && $comment['editor_name'] !== null ?
						get_username_string('full', $comment['editor_id'], $comment['editor_name'], $comment['editor_colour']) : $this->language->lang('GUEST');
					if ($comment['retired_editor'] !== '')
					{
						$editor = $this->byline->display(array('author_id' => ANONYMOUS, 'author_name' => $comment['retired_editor'], 'author_removed' => 1));
					}
					$edit = $this->language->lang('EDITED_TIMES_TOTAL', (int) $comment['edit_count'], $editor, $this->user->format_date($comment['edited_at']));
				}
				$links = array();
				foreach (array('edit','delete') as $mode)
				{
					if ($this->access->allows($cat, 'comment') && $this->access->may_change($cat, $comment['author_id'], $mode))
					{
						$links['U_' . strtoupper($mode)] = $this->helper->route('phpbb2plus_gallery_comment', $params + array('comment' => $comment['comment_id'], 'mode' => $mode));
					}
				}
				$this->template->assign_block_vars('album_comments', $links + array('ID' => $comment['comment_id'], 'AUTHOR' => $this->byline->display($comment),
					'EDITED' => $edit, 'TIME' => $this->user->format_date($comment['created_at']),
					'DATETIME' => gmdate('c', $comment['created_at']), 'BODY' => censor_text($comment['body'])));
			}
			$this->db->sql_freeresult($result);
		}
		foreach (array('edit','move','approve','unapprove','lock','unlock','delete') as $action)
		{
			if ($this->pictures->allowed($pic, $cat, $action))
			{
				$this->template->assign_block_vars('album_actions', array('LABEL' => $this->language->lang('PLUS_ALBUM_ACTION_' . strtoupper($action)),
					'URL' => $this->helper->route('phpbb2plus_gallery_manage', $params + array('mode' => $action))));
			}
		}
		$may_rate = $this->access->may_discuss($pic, $cat, 'rate');
		$may_comment = $this->access->may_discuss($pic, $cat, 'comment');
		if ($may_rate || $may_comment)
		{
			add_form_key('plus_discussion_' . $id);
			$this->template->assign_vars(array('U_DISCUSS' => $this->helper->route('phpbb2plus_gallery_discuss', $params),
				'S_ALBUM_COMMENT' => $may_comment, 'S_ALBUM_RATE' => $may_rate, 'S_ALBUM_HONOUR' => $this->discussion->honours($pic, $cat),
				'ALBUM_SCALE' => $this->discussion->scale(), 'ALBUM_LIMIT' => $this->discussion->text_limit(),
				'S_ALBUM_GUEST' => empty($this->user->data['is_registered'])));
		}
		if ($this->request->server('REQUEST_METHOD') === 'GET' && !$this->request->variable('start', 0) && empty($this->user->data['is_bot']))
		{
			$this->db->sql_query('UPDATE ' . $this->prefix . 'pics SET views=views+1 WHERE pic_id=' . $id);
			$pic['views']++;
		}
		$this->template->assign_vars(array('ALBUM_TITLE' => $pic['title'], 'ALBUM_DESCRIPTION' => $pic['description'], 'ALBUM_AUTHOR' => $this->byline->display($pic),
			'ALBUM_DATE' => $this->user->format_date($pic['created_at']), 'ALBUM_DATETIME' => gmdate('c', $pic['created_at']),
			'ALBUM_VIEWS' => (int) $pic['views'], 'S_ALBUM_RATE_DISPLAY' => $rate, 'S_ALBUM_COMMENTS' => $comments,
			'S_ALBUM_MEDIA' => $pic['storage_name'] !== '', 'U_FULL' => $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => 'full')),
			'U_PREVIEW' => $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => $this->request->variable('full', 0) ? 'full' : 'medium'))));
		return $this->helper->render('@phpbb2plus_gallery/picture.html', utf8_htmlspecialchars($pic['title']));
	}
}
