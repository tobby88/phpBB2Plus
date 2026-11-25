<?php
namespace phpbb2plus\gallery\controller;

use phpbb\request\request_interface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
	private $navigation;
	private $config;
	private $member_pictures;
	private $category_index;
	private $group_helper;

	public function __construct($access, $db, $user, $language, $template, $helper, $request, $pagination, $prefix, $discussion, $pictures, $byline, $personal, $navigation, $config, $member_pictures, $category_index, $group_helper)
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
		$this->navigation = $navigation;
		$this->config = $config;
		$this->member_pictures = $member_pictures;
		$this->category_index = $category_index;
		$this->group_helper = $group_helper;
	}

	private function breadcrumbs($cat = null, $listing = array())
	{
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_ALBUM'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_gallery_index')));
		$current = $cat ? (int) $cat['cat_id'] : 0;
		$parents = array();
		while ($cat)
		{
			$parents[] = $cat;
			$cat = $cat['parent_id'] ? $this->access->category($cat['parent_id']) : null;
		}
		foreach (array_reverse($parents) as $category)
		{
			$params = array('category' => $category['cat_id']) + ((int) $category['cat_id'] === $current ? $listing : array());
			$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => utf8_htmlspecialchars($category['title']),
				'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_gallery_category', $params)));
		}
	}

	private function page($url, $total, $limit)
	{
		$start = $this->pagination->validate_start(max(0, $this->request->variable('start', 0)), $limit, $total);
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $total, $limit, $start);
		return $start;
	}

	private function picture_grid($heading, $columns, $where, $order, $limit, $start = 0, $context = array(), $show_empty = true)
	{
		$rate = (int) $this->access->setting('rate', 1) === 1;
		$rating = $rate || $heading === 'PLUS_ALBUM_HIGHEST' ? ',(SELECT COALESCE(AVG(r.points),0) FROM ' . $this->prefix .
			'ratings r WHERE r.pic_id=p.pic_id AND r.points>0) AS rating' : '';
		$result = $this->db->sql_query_limit('SELECT p.pic_id,p.title,p.created_at,p.views,p.author_id,p.author_name,p.author_removed,p.storage_name,
			u.username,u.user_colour' . $rating . ' FROM ' . $this->prefix . 'pics p LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.author_id
			WHERE ' . $where . ' ORDER BY ' . $order, $limit, $start);
		$pictures = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		if (!$pictures && !$show_empty) { return; }
		$this->grid_rows($heading, $pictures, $columns, $rate, $context);
	}

	private function grid_rows($heading, $pictures, $columns, $rate, $context = array())
	{
		$popup = (int) $this->access->setting('fullpic_popup', 0) === 1;
		$this->template->assign_block_vars('album_grids', array('HEADING' => $this->language->lang($heading),
			'S_CATEGORY' => $heading === 'PLUS_ALBUM_PICTURES', 'S_RECENT' => $heading === 'PLUS_ALBUM_RECENT',
			'COLUMNS' => $columns, 'MOBILE_COLUMNS' => min(2, $columns)));
		foreach ($pictures as $pic)
		{
			$params = array('picture' => (int) $pic['pic_id']);
			$category = isset($pic['cat_id']) ? $this->access->category_row($pic['cat_id']) : null;
			$delete = $category && ($this->access->administrator() || (int) $pic['author_id'] === (int) $this->user->data['user_id']) &&
				$this->pictures->allowed($pic, $category, 'delete');
			$this->template->assign_block_vars('album_grids.pictures', array('TITLE' => $pic['title'], 'CATEGORY' => $category ? $category['title'] : '',
				'U_CATEGORY' => $category ? $this->helper->route('phpbb2plus_gallery_category', array('category' => $category['cat_id'])) : '',
				'U_DELETE' => $delete ? $this->helper->route('phpbb2plus_gallery_manage', $params + array('mode' => 'delete')) : '',
				'IP' => !empty($pic['author_ip']) && filter_var($pic['author_ip'], FILTER_VALIDATE_IP) ? $pic['author_ip'] : '',
				'S_COMMENTS' => isset($pic['comments']), 'COMMENTS' => isset($pic['comments']) ? (int) $pic['comments'] : 0,
				'TIME' => $this->user->format_date($pic['created_at']), 'DATETIME' => gmdate('c', $pic['created_at']),
				'AUTHOR' => $this->byline->display($pic), 'VIEWS' => (int) $pic['views'], 'S_RATE' => $rate,
				'RATING' => $rate && (float) $pic['rating'] > 0 ? number_format((float) $pic['rating'], 1, $this->language->lang('PLUS_ALBUM_DECIMAL'), '') : '',
				'U_PICTURE' => $this->helper->route('phpbb2plus_gallery_picture', $params + $context),
				'U_FULL' => $popup && $pic['storage_name'] !== '' ? $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => 'full')) : '',
				'U_THUMB' => $pic['storage_name'] !== '' ? $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => 'small')) : ''));
		}
	}

	private function preview_dimensions()
	{
		$columns = max(1, min(20, (int) $this->access->setting('img_cols', 4, 'sp')));
		$rows = max(1, min(100, (int) $this->access->setting('img_rows', 2, 'sp')));
		return array($columns, min(100, $rows * $columns));
	}

	private function home_grids()
	{
		$enabled = array();
		foreach (array('disp_late' => 'PLUS_ALBUM_RECENT', 'disp_high' => 'PLUS_ALBUM_HIGHEST', 'disp_rand' => 'PLUS_ALBUM_RANDOM') as $setting => $heading)
		{
			if ((int) $this->access->setting($setting, 1, 'sp') === 1) { $enabled[$setting] = $heading; }
		}
		if (!$enabled) { return; }
		list($columns, $limit) = $this->preview_dimensions();
		$categories = $this->access->portal_categories(false, array());
		$where = $this->access->picture_filter($categories) . " AND p.storage_name<>''";
		$random = in_array($this->db->get_sql_layer(), array('postgres', 'sqlite3'), true) ? 'RANDOM()' : 'RAND()';
		$sort = array('disp_late' => 'p.created_at DESC,p.pic_id DESC', 'disp_high' => 'rating DESC,' . $random, 'disp_rand' => $random);
		foreach ($enabled as $setting => $heading)
		{
			$this->picture_grid($heading, $columns, $where, $sort[$setting], $limit);
		}
	}

	private function category_preview($cat, $total, $has_children)
	{
		$personal = $this->access->personal_category($cat);
		$option = ($personal ? 'personal_' : '') . ($total ? 'show_recent_in_subcats' : 'show_recent_instead_of_nopics');
		if ((!$total && !$has_children) || (int) $this->access->setting($option, 1) !== 1) { return; }
		$categories = $has_children ? $this->access->visible_subtree($cat) : array((int) $cat['cat_id']);
		list($columns, $limit) = $this->preview_dimensions();
		$this->picture_grid('PLUS_ALBUM_RECENT', $columns, $this->access->picture_filter($categories) . " AND p.storage_name<>''",
			'p.created_at DESC,p.pic_id DESC', $limit, 0, array(), !$personal || $total > 0);
	}

	private function index_event($row, $is_comment, $popup)
	{
		if (!$row) { return array(); }
		$params = array('picture' => (int) $row['pic_id']);
		$title = $row['title'];
		$full = !$is_comment && $popup && $row['storage_name'] !== '';
		if ($is_comment) { $link = $this->helper->route('phpbb2plus_gallery_comment_legacy', array('comment' => (int) $row['event_id'])); }
		else
		{
			$link = $full ? $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => 'full')) : $this->helper->route('phpbb2plus_gallery_picture', $params);
			$length = max(1, min(200, (int) $this->access->setting('last_pic_title_length', 25)));
			if (utf8_strlen($title) > $length) { $title = utf8_substr($title, 0, $length) . '…'; }
		}
		return array('ID' => (int) $row['event_id'], 'TITLE' => $title, 'URL' => $link, 'FULL' => $full, 'AUTHOR' => $this->byline->display($row),
			'TIME' => $this->user->format_date($row['created_at']), 'DATETIME' => gmdate('c', $row['created_at']),
			'THUMB' => !$is_comment && $row['storage_name'] !== '' ? $this->helper->route('phpbb2plus_gallery_media', $params + array('size' => 'small')) : '');
	}

	private function category_directory($cat)
	{
		$options = $this->category_index->options();
		$children = $this->category_index->listing($cat, $options);
		foreach ($options as $key => $enabled) { $this->template->assign_var('S_' . strtoupper($key), $enabled); }
		foreach ($children as $child)
		{
			$row = $child['category'];
			$this->template->assign_block_vars('album_categories', array('ID' => (int) $row['cat_id'], 'TITLE' => $row['title'], 'DESCRIPTION' => $row['description'],
				'OWNER' => !$cat && (int) $row['owner_id'] > ANONYMOUS && $row['owner_name'] !== null ? html_entity_decode($row['owner_name'], ENT_QUOTES, 'UTF-8') : '',
				'NEW' => $child['local']['new'] ? $this->language->lang('PLUS_ALBUM_INDEX_NEW', $child['local']['new']) : '',
				'PICS' => $child['local']['pictures'], 'COMMENTS' => $child['local']['comments'], 'TOTAL_PICS' => $child['total']['pictures'],
				'TOTAL_COMMENTS' => $child['total']['comments'], 'PHOTO' => $this->index_event($child['photo'], false, $options['fullpic_popup']),
				'COMMENT' => $this->index_event($child['comment'], true, false),
				'U_CATEGORY' => $this->helper->route('phpbb2plus_gallery_category', array('category' => $row['cat_id']))));
			foreach ($child['moderators'] as $group)
			{
				$this->template->assign_block_vars('album_categories.moderators', array(
					'NAME' => htmlspecialchars_decode($group['group_name'], ENT_QUOTES),
					'URL' => $this->group_helper->get_name_string('profile', (int) $group['group_id'], $group['group_name'])));
			}
			foreach ($child['children'] as $sub)
			{
				$item = $sub['category'];
				$this->template->assign_block_vars('album_categories.children', array('ID' => (int) $item['cat_id'], 'TITLE' => $item['title'], 'DESCRIPTION' => $item['description'],
					'COUNT' => $sub['pictures'] ? $this->language->lang('PLUS_ALBUM_INDEX_SUBCOUNT', $sub['pictures']) : '',
					'NEW' => $sub['new'] ? $this->language->lang('PLUS_ALBUM_INDEX_NEW', $sub['new']) : '',
					'URL' => $this->helper->route('phpbb2plus_gallery_category', array('category' => $item['cat_id']))));
			}
		}
		return (bool) $children;
	}

	public function index($category = 0)
	{
		$id = (int) $category;
		$cat = $id ? $this->access->category($id) : null;
		$this->breadcrumbs($cat);
		$has_children = $this->category_directory($cat);
		if ($id)
		{
			$where = $this->navigation->category_filter($cat);
			$result = $this->db->sql_query('SELECT COUNT(*) AS count FROM ' . $this->prefix . 'pics p WHERE ' . $where);
			$total = (int) $this->db->sql_fetchfield('count');
			$this->db->sql_freeresult($result);
			$this->category_preview($cat, $total, $has_children);
			$columns = max(1, min(20, (int) $this->access->setting('cols_per_page', 4)));
			$rows = max(1, min(50, (int) $this->access->setting('rows_per_page', 3)));
			$limit = min(100, $rows * $columns);
			$sort = $this->navigation->sort($cat, $this->request->variable('sort_method', ''), $this->request->variable('sort_order', ''));
			$params = array('category' => $id);
			$url = $this->helper->route('phpbb2plus_gallery_category', $params + array('sort_method' => $sort[0], 'sort_order' => $sort[1]));
			$start = $this->page($url, $total, $limit);
			if ($total)
			{
				foreach ($this->navigation->methods($cat) as $key => $label)
				{
					$this->template->assign_block_vars('album_sorts', array('VALUE' => $key, 'LABEL' => $this->language->lang($label), 'SELECTED' => $key === $sort[0]));
				}
				$this->template->assign_vars(array('U_ALBUM_SORT' => $this->helper->route('phpbb2plus_gallery_category', $params),
					'ALBUM_SORT_ORDER' => $sort[1], 'ALBUM_SORT_HIDDEN' => $this->hidden_fields(array())));
				$this->picture_grid('PLUS_ALBUM_PICTURES', $columns, $where, $this->navigation->order_by($sort), $limit, $start,
					array('picture_sort' => $sort[0], 'picture_order' => $sort[1]));
			}
		}
		else { $this->home_grids(); }
		$title = $cat ? $cat['title'] : $this->language->lang('PLUS_ALBUM');
		$owner = $cat ? (int) $cat['owner_id'] : (int) $this->user->data['user_id'];
		if ($owner > ANONYMOUS && $this->personal->available($owner))
		{
			$this->template->assign_var('U_ALBUM_PERSONAL', $this->helper->route('phpbb2plus_gallery_personal', array('owner' => $owner)));
			if ($cat && (int) $this->access->setting('show_all_in_personal_gallery', 0))
			{ $this->template->assign_var('U_ALBUM_COLLECTION', $this->helper->route('phpbb2plus_gallery_member_pictures', array('owner' => $owner))); }
		}
		if ($cat && !empty($this->user->data['is_registered']) && empty($this->user->data['user_perm_from']) && $this->access->moderates($cat))
		{
			$this->template->assign_var('U_ALBUM_MODERATE', $this->helper->route('phpbb2plus_gallery_moderate', array('category' => $id)));
		}
		if ($cat && empty($this->user->data['is_bot']) && $this->access->allows($cat, 'upload'))
		{
			$this->template->assign_var('U_ALBUM_UPLOAD', $this->helper->route('phpbb2plus_gallery_upload', array('category' => $id)));
		}
		$this->template->assign_vars(array('ALBUM_TITLE' => $title, 'ALBUM_DESCRIPTION' => $cat ? $cat['description'] : '', 'ALBUM_CATEGORY_ID' => $id));
		return $this->helper->render('@phpbb2plus_gallery/index.html', utf8_htmlspecialchars($title));
	}

	private function hidden_fields($fields)
	{
		$sid = $this->request->variable('sid', '');
		if ($sid !== '' && $sid === $this->user->session_id) { $fields['sid'] = $sid; }
		return build_hidden_fields($fields);
	}

	private function comment_order()
	{
		return $this->request->variable('sort_order', '') === 'DESC' ? 'DESC' : 'ASC';
	}

	public function comment_bookmark($comment)
	{
		$id = (int) $comment;
		if ((int) $this->access->setting('comment', 1) !== 1) { throw new NotFoundHttpException(); }
		$result = $this->db->sql_query('SELECT pic_id FROM ' . $this->prefix . 'comments WHERE comment_id=' . $id);
		$picture = (int) $this->db->sql_fetchfield('pic_id');
		$this->db->sql_freeresult($result);
		$this->access->picture($picture);
		$direction = $this->comment_order();
		$start = $this->discussion->page_start($picture, $id, $direction);
		$params = array('picture' => $picture);
		if ($direction === 'DESC') { $params['sort_order'] = $direction; }
		if ($start) { $params['start'] = $start; }
		return new RedirectResponse($this->helper->route('phpbb2plus_gallery_picture', $params, false) . '#comment-' . $id);
	}

	public function members()
	{
		$this->breadcrumbs();
		$sort = array();
		foreach (array('mode' => 'joindate', 'order' => 'ASC') as $name => $default)
		{
			$source = $this->request->is_set($name, request_interface::POST) ? request_interface::POST : request_interface::GET;
			$sort[$name] = $this->request->variable($name, $default, false, $source);
		}
		$page = $this->personal->directory($sort['mode'], $sort['order'], $this->request->variable('start', 0, false, request_interface::GET),
			$this->config['topics_per_page']);
		foreach ($page['rows'] as $owner)
		{
			$this->template->assign_block_vars('album_owners', array('NAME' => html_entity_decode($owner['username'], ENT_QUOTES, 'UTF-8'),
				'JOINED' => $this->user->format_date($owner['user_regdate'], $this->language->lang('DATE_FORMAT')),
				'JOINED_AT' => gmdate('c', $owner['user_regdate']), 'PICTURES' => (int) $owner['pics'],
				'U_ALBUM' => $this->helper->route('phpbb2plus_gallery_personal', array('owner' => $owner['user_id']))));
		}
		foreach (array('joindate' => 'JOINED', 'username' => 'USERNAME', 'pics' => 'PLUS_ALBUM_PICTURES', 'last_pic' => 'PLUS_ALBUM_LAST_PICTURE') as $value => $label)
		{
			$this->template->assign_block_vars('album_member_sorts', array('VALUE' => $value, 'LABEL' => $this->language->lang($label), 'SELECTED' => $page['mode'] === $value));
		}
		$this->template->assign_vars(array('ALBUM_MEMBER_ORDER' => $page['order'], 'ALBUM_SORT_HIDDEN' => $this->hidden_fields(array())));
		$url = $this->helper->route('phpbb2plus_gallery_members', array('mode' => $page['mode'], 'order' => $page['order']));
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $page['total'], $page['size'], $page['start']);
		return $this->helper->render('@phpbb2plus_gallery/members.html', $this->language->lang('PLUS_ALBUM_MEMBERS'));
	}

	public function collection($owner, $type)
	{
		$this->breadcrumbs();
		$owner = (int) $owner;
		$page = $this->member_pictures->page($owner, $type, $this->request->variable('sort_method', ''), $this->request->variable('sort_order', ''),
			$this->request->variable('start', 0));
		$name = html_entity_decode($page['account']['username'], ENT_QUOTES, 'UTF-8');
		$title = $this->language->lang('PLUS_ALBUM_MEMBER_' . strtoupper($type), $name);
		$params = array('owner' => $owner);
		$route = 'phpbb2plus_gallery_member_pictures';
		if ($type !== 'all') { $route = 'phpbb2plus_gallery_member_activity'; $params['type'] = $type; }
		if ($this->personal->available($owner))
		{
			$this->template->assign_var('U_ALBUM_PERSONAL', $this->helper->route('phpbb2plus_gallery_personal', array('owner' => $owner)));
			if ($type !== 'all' && (int) $this->access->setting('show_all_in_personal_gallery', 0))
			{ $this->template->assign_var('U_ALBUM_COLLECTION', $this->helper->route('phpbb2plus_gallery_member_pictures', array('owner' => $owner))); }
		}
		foreach (array('pic' => true, 'rating' => $page['rate'], 'comment' => $page['comments']) as $kind => $enabled)
		{
			if ($enabled && $kind !== $type && ($type !== 'all' || !empty($this->user->data['is_registered'])))
			{
				$this->template->assign_block_vars('album_member_links', array('LABEL' => $this->language->lang('PLUS_ALBUM_MEMBER_' . strtoupper($kind), $name),
					'URL' => $this->helper->route('phpbb2plus_gallery_member_activity', array('owner' => $owner, 'type' => $kind))));
			}
		}
		$this->template->assign_vars(array('ALBUM_TITLE' => $title, 'S_MEMBER_RATING' => $type === 'rating', 'S_MEMBER_COMMENTS' => $type === 'comment'));
		$this->grid_rows('PLUS_ALBUM_PICTURES', $page['rows'], $page['columns'], $page['rate']);
		if ($page['total'])
		{
			$sort = $page['sort'];
			foreach ($this->navigation->methods(null) as $method => $label)
			{
				$this->template->assign_block_vars('album_sorts', array('VALUE' => $method, 'LABEL' => $this->language->lang($label), 'SELECTED' => $method === $sort[0]));
			}
			$this->template->assign_vars(array('U_ALBUM_SORT' => $this->helper->route($route, $params), 'ALBUM_SORT_ORDER' => $sort[1],
				'ALBUM_SORT_HIDDEN' => $this->hidden_fields(array())));
			$url = $this->helper->route($route, $params + array('sort_method' => $sort[0], 'sort_order' => $sort[1]));
			$this->pagination->generate_template_pagination($url, 'pagination', 'start', $page['total'], $page['limit'], $page['start']);
		}
		return $this->helper->render('@phpbb2plus_gallery/index.html', utf8_htmlspecialchars($title));
	}

	private function rating_text($picture, $honour)
	{
		$rating = $this->discussion->rating($picture, $honour);
		return $rating['votes'] ? $this->language->lang('PLUS_ALBUM_RATING',
			number_format((float) $rating['rating'], 1, $this->language->lang('PLUS_ALBUM_DECIMAL'), ''), $this->discussion->scale(),
			$this->language->lang('PLUS_ALBUM_VOTES', (int) $rating['votes'])) : '';
	}

	public function hot()
	{
		$this->breadcrumbs();
		$title = $this->language->lang('PLUS_ALBUM_HOT');
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			$id = $this->request->variable('pic_id', 0, false, request_interface::POST);
			if (!check_form_key('plus_album_hot_' . $id)) { throw new AccessDeniedHttpException(); }
			try
			{
				$this->discussion->publish($id, '', '', $this->request->variable('hon_rating', 0, false, request_interface::POST), true);
			}
			catch (\DomainException $e) { return $this->helper->message($e->getMessage(), array(), 'INFORMATION', 422); }
			return new RedirectResponse($this->helper->route('phpbb2plus_gallery_hot', array('rated' => 1), false), 303);
		}
		$this->template->assign_var('S_ALBUM_SAVED_VOTE', $this->request->variable('rated', 0) === 1);
		$draw = $this->discussion->draw();
		if ($draw)
		{
			list($pic, $cat) = $draw;
			$id = (int) $pic['pic_id'];
			$status = $this->discussion->honour_status($pic, $cat);
			if ($status === 'open') { add_form_key('plus_album_hot_' . $id); }
			$rate = (int) $this->access->setting('rate', 1) === 1;
			$comments = (int) $this->access->setting('comment', 1) === 1;
			if ($comments)
			{
				$result = $this->db->sql_query('SELECT COUNT(*) AS count FROM ' . $this->prefix . 'comments WHERE pic_id=' . $id);
				$this->template->assign_var('ALBUM_COMMENTS_COUNT', (int) $this->db->sql_fetchfield('count'));
				$this->db->sql_freeresult($result);
			}
			$this->template->assign_vars(array('ALBUM_ID' => $id, 'ALBUM_TITLE' => $pic['title'], 'ALBUM_DESCRIPTION' => $pic['description'],
				'ALBUM_CATEGORY' => $cat['title'], 'U_CATEGORY' => $this->helper->route('phpbb2plus_gallery_category', array('category' => $cat['cat_id'])),
				'ALBUM_AUTHOR' => $this->byline->display($pic), 'ALBUM_DATE' => $this->user->format_date($pic['created_at']),
				'ALBUM_DATETIME' => gmdate('c', $pic['created_at']), 'ALBUM_VIEWS' => (int) $pic['views'], 'S_ALBUM_COMMENTS' => $comments,
				'S_ALBUM_RATE_DISPLAY' => $rate, 'ALBUM_RATING' => $rate ? $this->rating_text($id, true) : '', 'ALBUM_SCALE' => $this->discussion->scale(),
				'ALBUM_VOTE_STATUS' => $status, 'U_PICTURE' => $this->helper->route('phpbb2plus_gallery_picture', array('picture' => $id)),
				'U_FULL' => $this->helper->route('phpbb2plus_gallery_media', array('picture' => $id, 'size' => 'full'))));
		}
		return $this->helper->render('@phpbb2plus_gallery/hot.html', $title);
	}

	public function picture($picture)
	{
		list($pic, $cat) = $this->access->picture($picture);
		$this->language->add_lang('viewtopic');
		$id = (int) $pic['pic_id'];
		$params = array('picture' => $id);
		$context = array();
		$method = $this->request->variable('picture_sort', '');
		$order = $this->request->variable('picture_order', '');
		// Standalone legacy arrows moved forward in time. A visit from a category follows the grid's order.
		$sort = array('pic_time', 'ASC');
		if ($method !== '' || $order !== '')
		{
			$sort = $this->navigation->sort($cat, $method, $order);
			$context = array('picture_sort' => $sort[0], 'picture_order' => $sort[1]);
		}
		$this->breadcrumbs($cat, $context ? array('sort_method' => $sort[0], 'sort_order' => $sort[1]) : array());
		if ($this->request->variable('full', 0)) { $context['full'] = 1; }
		$comment_order = $this->comment_order();
		if ($comment_order === 'DESC') { $context['sort_order'] = $comment_order; }
		$comment_params = $params + ($comment_order === 'DESC' ? array('sort_order' => $comment_order) : array());
		$neighbours = $this->navigation->neighbours($pic, $cat, $sort);
		$mode = $this->request->variable('mode', '');
		if (isset($neighbours[$mode]))
		{
			return new RedirectResponse($this->helper->route('phpbb2plus_gallery_picture', array('picture' => $neighbours[$mode] ?: $id) + $context, false));
		}
		foreach ($neighbours as $step => $target)
		{
			if ($target) { $this->template->assign_var('U_ALBUM_' . strtoupper($step),
				$this->helper->route('phpbb2plus_gallery_picture', array('picture' => $target) + $context)); }
		}
		$link = $this->helper->route('phpbb2plus_gallery_picture', $params + $context);
		$rate = (int) $this->access->setting('rate', 1) === 1;
		$comments = (int) $this->access->setting('comment', 1) === 1;
		if ($rate) { $this->template->assign_var('ALBUM_RATING', $this->rating_text($id, false)); }
		if ($comments)
		{
			$result = $this->db->sql_query('SELECT COUNT(*) AS count FROM ' . $this->prefix . 'comments WHERE pic_id=' . $id);
			$total = (int) $this->db->sql_fetchfield('count');
			$this->db->sql_freeresult($result);
			$limit = $this->discussion->per_page();
			$start = $this->page($link, $total, $limit);
			$result = $this->db->sql_query_limit('SELECT c.*,u.username,u.user_colour,e.username AS editor_name,e.user_colour AS editor_colour
				FROM ' . $this->prefix . 'comments c LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=c.author_id
				LEFT JOIN ' . USERS_TABLE . ' e ON e.user_id=c.editor_id WHERE c.pic_id=' . $id . ' ORDER BY c.comment_id ' . $comment_order, $limit, $start);
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
						$links['U_' . strtoupper($mode)] = $this->helper->route('phpbb2plus_gallery_comment', $comment_params + array('comment' => $comment['comment_id'], 'mode' => $mode));
					}
				}
				$this->template->assign_block_vars('album_comments', $links + array('ID' => $comment['comment_id'], 'AUTHOR' => $this->byline->display($comment),
					'EDITED' => $edit, 'TIME' => $this->user->format_date($comment['created_at']),
					'DATETIME' => gmdate('c', $comment['created_at']), 'BODY' => censor_text($comment['body'])));
			}
			$this->db->sql_freeresult($result);
			if ($total > 1)
			{
				unset($context['sort_order']);
				$this->template->assign_vars(array('U_ALBUM_COMMENT_SORT' => $this->helper->route('phpbb2plus_gallery_picture', $params),
					'ALBUM_COMMENT_ORDER' => $comment_order, 'ALBUM_COMMENT_HIDDEN' => $this->hidden_fields($context)));
			}
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
			$this->template->assign_vars(array('U_DISCUSS' => $this->helper->route('phpbb2plus_gallery_discuss', $comment_params),
				'S_ALBUM_COMMENT' => $may_comment, 'S_ALBUM_RATE' => $may_rate, 'S_ALBUM_HONOUR' => $may_rate && $this->discussion->honour_status($pic, $cat) === 'open',
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
