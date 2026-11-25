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
			$where = 'cat_id=' . $id . ($this->access->pending_visible($cat) ? '' : ' AND approved=1');
			$result = $this->db->sql_query('SELECT COUNT(*) AS count FROM ' . $this->prefix . 'pics WHERE ' . $where);
			$total = (int) $this->db->sql_fetchfield('count');
			$this->db->sql_freeresult($result);
			$limit = max(1, min(60, (int) $this->access->setting('rows_per_page', 3) * (int) $this->access->setting('cols_per_page', 4)));
			$url = $this->helper->route('phpbb2plus_gallery_category', array('category' => $id));
			$start = $this->page($url, $total, $limit);
			$sort = array('pic_time' => 'created_at','pic_title' => 'title','pic_view_count' => 'views','pic_id' => 'pic_id');
			$order = $sort[$this->access->setting('sort_method', 'pic_time')] ?? 'created_at';
			$direction = strtoupper($this->access->setting('sort_order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
			$result = $this->db->sql_query_limit('SELECT pic_id,title,created_at FROM ' . $this->prefix . "pics
				WHERE $where ORDER BY $order $direction,pic_id $direction", $limit, $start);
			while ($pic = $this->db->sql_fetchrow($result))
			{
				$params = array('picture' => $pic['pic_id']);
				$this->template->assign_block_vars('album_pictures', array('TITLE' => $pic['title'], 'TIME' => $this->user->format_date($pic['created_at']),
					'U_PICTURE' => $this->helper->route('phpbb2plus_gallery_picture', $params),
					'U_THUMB' => $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => 'small'))));
			}
			$this->db->sql_freeresult($result);
		}
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
				(int) $this->access->setting('rate_scale', 10), (int) $rating['votes'])); }
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
					'EDITED' => $edit, 'TIME' => $this->user->format_date($comment['created_at']), 'BODY' => censor_text($comment['body'])));
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
		}
		$this->template->assign_vars(array('ALBUM_TITLE' => $pic['title'], 'ALBUM_DESCRIPTION' => $pic['description'], 'ALBUM_AUTHOR' => $this->byline->display($pic),
			'ALBUM_DATE' => $this->user->format_date($pic['created_at']), 'S_ALBUM_COMMENTS' => $comments,
			'U_FULL' => $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => 'full')),
			'U_PREVIEW' => $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => $this->request->variable('full', 0) ? 'full' : 'medium'))));
		return $this->helper->render('@phpbb2plus_gallery/picture.html', utf8_htmlspecialchars($pic['title']));
	}
}
