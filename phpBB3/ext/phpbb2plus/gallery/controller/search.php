<?php
namespace phpbb2plus\gallery\controller;

use phpbb\request\request_interface;

class search
{
	private $db;
	private $access;
	private $byline;
	private $request;
	private $helper;
	private $template;
	private $language;
	private $user;
	private $pagination;
	private $config;
	private $pictures;

	public function __construct($db, $access, $byline, $request, $helper, $template, $language, $user, $pagination, $config, $pictures)
	{
		$this->db = $db;
		$this->access = $access;
		$this->byline = $byline;
		$this->request = $request;
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->user = $user;
		$this->pagination = $pagination;
		$this->config = $config;
		$this->pictures = $pictures;
	}

	private function parameter($name)
	{
		$source = $this->request->is_set($name, request_interface::POST) ? request_interface::POST : request_interface::GET;
		$value = $this->request->raw_variable($name, '', $source);
		return is_string($value) ? trim($value) : '';
	}

	private function contains($term)
	{
		// The old search accepted SQL LIKE's % and _ wildcards. Let DBAL quote the remaining text.
		$any = $this->db->get_any_char();
		$term = str_replace(array('%', '_'), array($any, $this->db->get_one_char()), $term);
		return $this->db->sql_like_expression($any . $term . $any);
	}

	private function plain_text_match($column, $term)
	{
		if ($this->db->get_sql_layer() === 'mysqli')
		{
			// Decode the stored bytes and the pattern as Unicode. LOWER and LIKE's "_"
			// must operate on characters; a quoted literal uses the driver's three-byte charset.
			$pattern = '0x' . bin2hex('%' . $term . '%');
			return "LOWER(CONVERT($column USING utf8mb4)) COLLATE utf8mb4_bin LIKE CONVERT($pattern USING utf8mb4)";
		}
		return "LOWER($column) " . $this->contains($term);
	}

	public function find()
	{
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$title = $this->language->lang('PLUS_ALBUM_SEARCH');
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_ALBUM'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_gallery_index')));
		$mode = strtolower($this->parameter('mode'));
		$term = $this->parameter('search');
		$valid_text = strlen($term) <= 100 && strpos($term, "\0") === false && (bool) preg_match('//u', $term);
		$valid_mode = in_array($mode, array('user', 'name', 'desc'), true);
		$this->template->assign_vars(array('ALBUM_SEARCH_MODE' => $valid_mode ? $mode : 'user', 'ALBUM_QUERY' => $valid_text ? $term : '',
			'S_ALBUM_SEARCH_POST' => $this->request->server('REQUEST_METHOD') === 'POST'));
		if ($term !== '' && (!$valid_text || !$valid_mode))
		{
			$this->template->assign_var('S_ALBUM_SEARCH_ERROR', true);
			return $this->helper->render('@phpbb2plus_gallery/search.html', $title, 422);
		}
		if ($term !== '') { $this->results($mode, $term); }
		return $this->helper->render('@phpbb2plus_gallery/search.html', $title);
	}

	private function results($mode, $term)
	{
		$categories = $this->access->portal_categories(true, array());
		$where = $this->access->picture_filter($categories, true);
		$needle = utf8_strtolower(utf8_normalize_nfc($term));
		$accounts = ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.author_id AND p.author_id>' . ANONYMOUS;
		if ($mode === 'user')
		{
			$where .= ' AND ' . $this->byline->search_condition($this->contains(utf8_encode_ucr(utf8_htmlspecialchars($needle))),
				$this->plain_text_match('p.author_name', $needle));
		}
		else { $where .= ' AND ' . $this->plain_text_match($mode === 'name' ? 'p.title' : 'p.description', $needle); }
		$order = ' ORDER BY p.created_at DESC,p.pic_id DESC';
		// Count only visible results, up to the original ceiling. Fetch descriptions only as a SQL predicate.
		$result = $this->db->sql_query_limit('SELECT p.pic_id FROM ' . $this->pictures . ' p' . ($mode === 'user' ? $accounts : '') .
			' WHERE ' . $where . $order, 501);
		$ids = array();
		while ($row = $this->db->sql_fetchrow($result)) { $ids[] = (int) $row['pic_id']; }
		$this->db->sql_freeresult($result);
		$total = min(500, count($ids));
		$this->template->assign_vars(array('S_ALBUM_RESULTS' => true, 'ALBUM_FOUND' => $total, 'S_ALBUM_CAPPED' => count($ids) > $total));
		if (!$total) { return; }
		$limit = max(1, min(100, (int) $this->config['topics_per_page']));
		$start = $this->pagination->validate_start(max(0, (int) $this->parameter('start')), $limit, $total);
		if ($this->request->server('REQUEST_METHOD') === 'POST' && $total > $limit)
		{
			// Keep a POSTed search term out of URLs on subsequent pages, too.
			$this->template->assign_vars(array('ALBUM_POST_PAGES' => $this->language->lang('PAGE_OF', intdiv($start, $limit) + 1,
				(int) ceil($total / $limit)), 'S_ALBUM_PREVIOUS' => $start > 0, 'S_ALBUM_NEXT' => $start + $limit < $total,
				'ALBUM_PREVIOUS_START' => max(0, $start - $limit), 'ALBUM_NEXT_START' => $start + $limit));
		}
		elseif ($total > $limit)
		{
			$url = $this->helper->route('phpbb2plus_gallery_search', array('mode' => $mode, 'search' => $term));
			$this->pagination->generate_template_pagination($url, 'pagination', 'start', $total, $limit, $start);
		}
		$page = array_slice($ids, $start, min($limit, $total - $start));
		$result = $this->db->sql_query('SELECT p.pic_id,p.cat_id,p.title,p.author_id,p.author_name,p.author_removed,p.created_at,u.username,u.user_colour
			FROM ' . $this->pictures . ' p' . $accounts . ' WHERE ' . $this->db->sql_in_set('p.pic_id', $page) . ' AND ' . $where . $order);
		while ($picture = $this->db->sql_fetchrow($result))
		{
			$category = $this->access->category_row($picture['cat_id']);
			$this->template->assign_block_vars('album_results', array('TITLE' => $picture['title'], 'CATEGORY' => $category['title'],
				'AUTHOR' => $this->byline->display($picture), 'TIME' => $this->user->format_date($picture['created_at']),
				'DATETIME' => gmdate('c', $picture['created_at']), 'U_PICTURE' => $this->helper->route('phpbb2plus_gallery_picture', array('picture' => $picture['pic_id'])),
				'U_CATEGORY' => $this->helper->route('phpbb2plus_gallery_category', array('category' => $picture['cat_id']))));
		}
		$this->db->sql_freeresult($result);
	}
}
