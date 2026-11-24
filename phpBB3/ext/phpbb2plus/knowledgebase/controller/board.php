<?php
namespace phpbb2plus\knowledgebase\controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class board
{
	private $articles;
	private $db;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $pagination;
	private $prefix;
	private $root;
	private $php_ext;
	private $names;

	public function __construct($articles, $db, $user, $language, $template, $helper, $request, $pagination, $prefix, $root, $php_ext, $names = null)
	{
		$this->articles = $articles;
		$this->db = $db;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->request = $request;
		$this->pagination = $pagination;
		$this->prefix = $prefix;
		$this->root = $root;
		$this->php_ext = $php_ext;
		$this->names = $names;
	}

	private function breadcrumbs($id)
	{
		$this->language->add_lang('knowledgebase', 'phpbb2plus/knowledgebase');
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_KB'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_kb_index')));
		foreach ($this->articles->trail((int) $id) as $cat)
		{
			$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => utf8_htmlspecialchars($cat['title']),
				'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_kb_category', array('category' => $cat['cat_id']))));
		}
	}

	public function index($category = 0)
	{
		$id = (int) $category;
		$this->breadcrumbs($id);
		$cats = $this->articles->category_list();
		$query = htmlspecialchars_decode($this->request->variable('q', '', true), ENT_QUOTES);
		$view = $this->request->variable('order', '');
		$params = $id ? array('category' => $id) : array();
		$route = $id ? 'phpbb2plus_kb_category' : 'phpbb2plus_kb_index';
		$url = $this->helper->route($route, $params);
		$stats = array('newest' => 'a.created_at DESC', 'viewed' => 'a.views DESC', 'alphabetical' => 'a.title ASC');
		$configured = $this->articles->setting('news_sort', 'Alphabetic') === 'Alphabetic' ? 'alphabetical' : 'newest';
		$sort = $stats[$view] ?? $stats[$configured];
		if (isset($stats[$view])) { $params['order'] = $view; }
		foreach ($cats as $cat)
		{
			if ((int) $cat['parent_id'] !== $id || $query !== '') { continue; }
			$this->template->assign_block_vars('kb_cats', array('TITLE' => $cat['title'], 'DESCRIPTION' => $cat['description'],
				'URL' => $this->helper->route('phpbb2plus_kb_category', array('category' => $cat['cat_id']))));
		}
		$where = $this->articles->visible_sql();
		if ($id) { $where .= ' AND a.cat_id=' . $id; }
		if ($query !== '')
		{
			$params['q'] = $query;
			$where .= ' AND ' . ($this->names && $this->names->hidden() && $this->names->matches_search($query) ? '1=0' : $this->articles->search_sql($query));
		}
		$from = $this->prefix . 'articles a';
		$result = $this->db->sql_query("SELECT COUNT(*) AS total FROM $from WHERE $where");
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		$limit = max(1, min(100, (int) $this->articles->setting('art_pagination', 5)));
		$start = $this->pagination->validate_start(max(0, $this->request->variable('start', 0)), $limit, $count);
		$this->pagination->generate_template_pagination($this->helper->route($route, $params), 'pagination', 'start', $count, $limit, $start);
		$result = $this->db->sql_query_limit("SELECT a.article_id,a.title,a.description,a.approval FROM $from WHERE $where ORDER BY $sort,a.article_id", $limit, $start);
		while ($article = $this->db->sql_fetchrow($result))
		{
			$this->template->assign_block_vars('kb_articles', array('TITLE' => $article['title'], 'SUMMARY' => $article['description'],
				'PENDING' => (int) $article['approval'] !== 1, 'URL' => $this->helper->route('phpbb2plus_kb_article', array('article' => $article['article_id']))));
		}
		$this->db->sql_freeresult($result);
		$title = $id ? $cats[$id]['title'] : $this->language->lang('PLUS_KB');
		$this->template->assign_vars(array('KB_TITLE' => $title, 'KB_QUERY' => $query, 'KB_SORT' => $view ?: $configured, 'U_SEARCH' => $url));
		return $this->helper->render('@phpbb2plus_knowledgebase/index.html', utf8_htmlspecialchars($title));
	}

	private function author($a)
	{
		if ((int) $a['author_id'] > ANONYMOUS && $a['username'] !== null) { return get_username_string('full', $a['author_id'], $a['username'], $a['user_colour']); }
		if ($a['author_removed'] && ($this->names ? $this->names->hidden() : empty($this->user->data['is_registered']))) { return $this->language->lang('PLUS_KB_FORMER'); }
		return $a['author_name'] === '' ? $this->language->lang('GUEST') : utf8_htmlspecialchars($a['author_name']);
	}

	public function article($article)
	{
		$id = (int) $article;
		$a = $this->articles->find($id);
		$this->breadcrumbs($a['cat_id']);
		$params = array('article' => $id);
		$print = $this->request->variable('print', 0) === 1;
		$page = max(1, $this->request->variable('page', 1));
		$part = $this->articles->page($id, $page);
		foreach ($this->articles->pages($id) as $part_info)
		{
			$params_page = $params + array('page' => $part_info['page_number']);
			if ($print) { $params_page['print'] = 1; }
			$this->template->assign_block_vars('kb_pages', array('NUMBER' => $part_info['page_number'], 'HEADING' => $part_info['heading'],
				'CURRENT' => (int) $part_info['page_number'] === $page, 'URL' => $this->helper->route('phpbb2plus_kb_article', $params_page)));
		}
		if (!$print && $page === 1 && $this->request->server('REQUEST_METHOD') === 'GET' && empty($this->user->data['is_bot']) && (string) $a['views'] !== '9223372036854775807')
		{
			$this->db->sql_query('UPDATE ' . $this->prefix . 'articles SET views=views+1 WHERE article_id=' . $id . ' AND views<9223372036854775807');
			$a['views'] = (int) $a['views'] + 1;
		}
		if ((int) $this->articles->setting('allow_rating', 0))
		{
			$score = $this->articles->scores($a);
			if ($score['count'] !== '0')
			{
				$this->template->assign_var('KB_SCORE', $this->language->lang('PLUS_KB_SCORE', number_format($score['average'], 2, $this->language->lang('PLUS_KB_DECIMAL'), ''), $score['count']));
			}
			if (!$print && $this->articles->may_rate())
			{
				add_form_key('plus_kb_rate_' . $id);
				$this->template->assign_var('U_KB_RATE', $this->helper->route('phpbb2plus_kb_rate', $params));
			}
		}
		$topic = $this->articles->discussion($a['topic_id']);
		if ($topic)
		{
			$this->template->assign_vars(array('KB_DISCUSSION' => $topic['topic_title'],
				'U_KB_DISCUSSION' => append_sid($this->root . 'viewtopic.' . $this->php_ext, 't=' . $topic['topic_id'])));
		}
		$this->template->assign_vars(array('KB_TITLE' => $a['title'], 'KB_SUMMARY' => $a['description'], 'KB_TYPE' => $a['type_name'],
			'KB_PENDING' => (int) $a['approval'] !== 1, 'KB_AUTHOR' => $this->author($a), 'KB_DATE' => $this->user->format_date($a['created_at']),
			'KB_VIEWS' => $a['views'], 'KB_PRINT' => $print, 'KB_BODY' => generate_text_for_display($part['content'], $part['bbcode_uid'], $part['bbcode_bitfield'], $part['bbcode_options']),
			'U_KB_PRINT' => $this->helper->route('phpbb2plus_kb_article', $params + array('page' => $page, 'print' => 1))));
		return $this->helper->render('@phpbb2plus_knowledgebase/article.html', utf8_htmlspecialchars($a['title']));
	}

	public function rate($article)
	{
		$this->language->add_lang('knowledgebase', 'phpbb2plus/knowledgebase');
		if (!check_form_key('plus_kb_rate_' . (int) $article)) { throw new AccessDeniedHttpException(); }
		try { $this->articles->rate($article, $this->request->variable('rating', 0, false, \phpbb\request\request_interface::POST)); }
		catch (\DomainException $e) { return $this->helper->message($e->getMessage(), array(), 'INFORMATION', 422); }
		return new RedirectResponse($this->helper->route('phpbb2plus_kb_article', array('article' => $article), false), 303);
	}
}
