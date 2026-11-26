<?php
namespace phpbb2plus\knowledgebase\controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
	private $editor;
	private $replies;

	private $dispatcher;

	public function __construct($articles, $db, $user, $language, $template, $helper, $request, $pagination, $prefix, $root, $php_ext, $editor, $replies, $names = null, $dispatcher = null)
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
		$this->dispatcher = $dispatcher;
		$this->editor = $editor;
		$this->replies = $replies;
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

	private function navigation($active = '')
	{
		$this->template->assign_vars(array('KB_HEADER_BANNER' => (bool) (int) $this->articles->setting('header_banner', 1),
			'U_KB_HOME' => $this->helper->route('phpbb2plus_kb_index')));
		foreach (array('latest','mostpopular','toprated') as $list)
		{
			if ($list === 'toprated' && !(int) $this->articles->setting('allow_rating', 0)) { continue; }
			$this->template->assign_block_vars('kb_lists', array('ACTIVE' => $list === $active,
				'LABEL' => $this->language->lang('PLUS_KB_LIST_' . strtoupper($list)),
				'URL' => $this->helper->route('phpbb2plus_kb_rankings', array('list' => $list))));
		}
		if (!(int) $this->articles->setting('stats_list', 1)) { return; }
		$result = $this->db->sql_query('SELECT t.title,t.label_encoded,COALESCE(c.amount,0) AS amount FROM ' . $this->prefix . 'types t
			LEFT JOIN (SELECT type_id,COUNT(*) AS amount FROM ' . $this->prefix . 'articles WHERE approval=1 GROUP BY type_id) c ON c.type_id=t.type_id
			ORDER BY t.title,t.type_id');
		while ($type = $this->db->sql_fetchrow($result))
		{
			$this->template->assign_block_vars('kb_totals', array('TITLE' => $this->articles->label($type['title'], $type['label_encoded']), 'COUNT' => $type['amount']));
		}
		$this->db->sql_freeresult($result);
	}

	private function page_window($url, $count)
	{
		$limit = max(1, min(1000, (int) $this->articles->setting('art_pagination', 5)));
		$start = $this->pagination->validate_start(max(0, $this->request->variable('start', 0)), $limit, $count);
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $count, $limit, $start);
		return array($limit, $start);
	}

	private function score_label($average, $count)
	{
		return $this->language->lang('PLUS_KB_SCORE', number_format($average, 2, $this->language->lang('PLUS_KB_DECIMAL'), ''), $count);
	}

	private function article_row($article)
	{
		return array(
			'TITLE' => $article['title'], 'SUMMARY' => $article['description'], 'AUTHOR' => $this->author($article),
			'DATE' => $this->user->format_date($article['created_at']), 'VIEWS' => $article['views'],
			'TYPE' => $this->articles->label($article['type_name'], $article['label_encoded']),
			'URL' => $this->helper->route('phpbb2plus_kb_article', array('article' => $article['article_id'])),
		);
	}

	public function rankings($list)
	{
		$ratings = (int) $this->articles->setting('allow_rating', 0);
		if (!in_array($list, array('latest','mostpopular','toprated'), true) || ($list === 'toprated' && !$ratings)) { throw new NotFoundHttpException(); }
		$this->breadcrumbs(0);
		$this->navigation($list);
		$from = $this->prefix . 'articles a';
		$where = 'a.approval=1';
		$order = 'a.created_at';
		$votes = 'CAST(a.historical_votes AS DECIMAL(30,0)) + COALESCE(v.amount,0)';
		$points = 'CAST(a.historical_points AS DECIMAL(32,4)) + COALESCE(v.points,0)';
		$totals = $ratings ? ' LEFT JOIN (SELECT article_id,COUNT(*) AS amount,SUM(points) AS points FROM ' . $this->prefix .
			'votes WHERE points>0 GROUP BY article_id) v ON v.article_id=a.article_id' : '';
		if ($list === 'mostpopular') { $where .= ' AND a.views>0'; $order = 'a.views'; }
		elseif ($list === 'toprated') { $where .= " AND ($votes)>0"; $order = "($points)/NULLIF(($votes),0)"; }
		$result = $this->db->sql_query("SELECT COUNT(*) AS total FROM $from " . ($list === 'toprated' ? $totals : '') . " WHERE $where");
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		list($limit, $start) = $this->page_window($this->helper->route('phpbb2plus_kb_rankings', array('list' => $list)), $count);
		$sql = 'SELECT a.article_id,a.title,a.description,a.cat_id,a.created_at,a.author_id,a.author_name,a.author_removed,a.views,
			u.username,u.user_colour,t.title AS type_name,t.label_encoded' . ($ratings ? ",($votes) AS num_votes,($points) AS num_points" : '') .
			" FROM $from $totals LEFT JOIN " . USERS_TABLE . ' u ON u.user_id=a.author_id LEFT JOIN ' . $this->prefix . 'types t ON t.type_id=a.type_id' .
			" WHERE $where ORDER BY $order DESC,a.article_id DESC";
		$categories = $this->articles->category_list();
		$result = $this->db->sql_query_limit($sql, $limit, $start);
		while ($a = $this->db->sql_fetchrow($result))
		{
			$cat = $categories[(int) $a['cat_id']] ?? null;
			$this->template->assign_block_vars('kb_ranked', $this->article_row($a) + array(
				'CATEGORY' => $cat ? $cat['title'] : '', 'U_CATEGORY' => $cat ? $this->helper->route('phpbb2plus_kb_category', array('category' => $a['cat_id'])) : '',
				'SCORE' => $ratings && (string) $a['num_votes'] !== '0' ? $this->score_label((float) $a['num_points'] / (float) $a['num_votes'], $a['num_votes']) : ''));
		}
		$this->db->sql_freeresult($result);
		$title = $this->language->lang('PLUS_KB_LIST_' . strtoupper($list));
		$this->template->assign_var('KB_TITLE', $title);
		return $this->helper->render('@phpbb2plus_knowledgebase/rankings.html', $title);
	}

	public function index($category = 0)
	{
		$id = (int) $category;
		$this->breadcrumbs($id);
		$this->navigation();
		$cats = $this->articles->category_list();
		$query = htmlspecialchars_decode($this->request->variable('q', '', true), ENT_QUOTES);
		$match = $this->request->variable('match', '');
		if (!in_array($match, array('any', 'all'), true)) { $match = ''; }
		$view = $this->request->variable('order', '');
		$params = $id ? array('category' => $id) : array();
		$route = $id ? 'phpbb2plus_kb_category' : 'phpbb2plus_kb_index';
		$url = $this->helper->route($route, $params);
		$visitor = array('newest' => array('Creation','DESC'), 'viewed' => array('Views','DESC'), 'alphabetical' => array('Alphabetic','ASC'));
		if (isset($visitor[$view]))
		{
			list($mode, $direction) = $visitor[$view];
			$params['order'] = $view;
		}
		else
		{
			$view = 'default';
			$mode = $this->articles->setting('news_sort', 'Alphabetic');
			$direction = strtoupper($this->articles->setting('news_sort_par', 'ASC')) === 'ASC' ? 'ASC' : 'DESC';
		}
		$counts = $query === '' ? $this->articles->published_in_children($id) : array();
		foreach ($counts as $child => $published)
		{
			$this->template->assign_block_vars('kb_cats', array('TITLE' => $cats[$child]['title'], 'DESCRIPTION' => $cats[$child]['description'],
				'COUNT' => $published, 'URL' => $this->helper->route('phpbb2plus_kb_category', array('category' => $child))));
		}
		$where = $this->articles->visible_sql();
		if ($id) { $where .= ' AND a.cat_id=' . $id; }
		if ($query !== '')
		{
			$params['q'] = $query;
			if ($match !== '') { $params['match'] = $match; }
			$where .= ' AND ' . ($this->names && $this->names->hidden() && $this->names->matches_search($query) ? '1=0' : $this->articles->search_sql($query, $match));
		}
		$from = $this->prefix . 'articles a';
		$result = $this->db->sql_query("SELECT COUNT(*) AS total FROM $from WHERE $where");
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		list($limit, $start) = $this->page_window($this->helper->route($route, $params), $count);
		$fields = array('Id' => 'a.article_id', 'Creation' => 'a.created_at', 'Alphabetic' => 'a.title', 'Views' => 'a.views');
		$from .= ' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=a.author_id LEFT JOIN ' . $this->prefix . 'types t ON t.type_id=a.type_id';
		if ($mode === 'Latest') { $field = $this->articles->discussion_sort_sql(); }
		elseif ($mode === 'Userrank') { $field = 'COALESCE(u.user_rank,0)'; }
		else { $field = $fields[$mode] ?? 'a.created_at'; }
		$order = $field . ' ' . $direction . ($field === 'a.article_id' ? '' : ',a.article_id ' . $direction);
		$result = $this->db->sql_query_limit("SELECT a.article_id,a.title,a.description,a.approval,a.created_at,a.views,a.author_id,a.author_name,a.author_removed,
			u.username,u.user_colour,t.title AS type_name,t.label_encoded FROM $from WHERE $where ORDER BY $order", $limit, $start);
		while ($article = $this->db->sql_fetchrow($result))
		{
			$this->template->assign_block_vars('kb_articles', $this->article_row($article) + array('PENDING' => (int) $article['approval'] !== 1));
		}
		$this->db->sql_freeresult($result);
		$title = $id ? $cats[$id]['title'] : $this->language->lang('PLUS_KB');
		if ($this->editor->allowed('new')) { $this->template->assign_var('U_KB_NEW', $this->helper->route('phpbb2plus_kb_submit', $id ? array('category' => $id) : array())); }
		$this->template->assign_vars(array('KB_TITLE' => $title, 'KB_QUERY' => $query, 'KB_SORT' => $view, 'U_SEARCH' => $url,
			'KB_MATCH_ANY' => $match === 'any' || ($query === '' && $match === '')));
		return $this->helper->render('@phpbb2plus_knowledgebase/index.html', utf8_htmlspecialchars($title));
	}

	private function author($a)
	{
		if ((int) $a['author_id'] > ANONYMOUS && $a['username'] !== null) { return get_username_string('full', $a['author_id'], $a['username'], $a['user_colour']); }
		if ($a['author_removed'] && ($this->names ? $this->names->hidden() : empty($this->user->data['is_registered']))) { return $this->language->lang('PLUS_KB_FORMER'); }
		return $a['author_name'] === '' ? $this->language->lang('GUEST') : utf8_htmlspecialchars($a['author_name']);
	}

	private function discussion_replies($topic, $params, $page, $print)
	{
		$count = $this->replies->count($topic);
		$this->template->assign_vars(array('KB_DISCUSSION' => $topic['topic_title'], 'KB_REPLIES' => $count,
			'U_KB_DISCUSSION' => append_sid($this->root . 'viewtopic.' . $this->php_ext, 't=' . $topic['topic_id'])));
		if (!$count || !(int) $this->articles->setting('comments_show', 1)) { return 0; }
		$this->language->add_lang('viewtopic');
		$limit = max(1, min(1000, (int) $this->articles->setting('comments_pagination', 5)));
		$start = $this->pagination->validate_start(max(0, $this->request->variable('start', 0)), $limit, $count);
		if ($page > 1) { $params['page'] = $page; }
		if ($print) { $params['print'] = 1; }
		$url = $this->helper->route('phpbb2plus_kb_article', $params);
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $count, $limit, $start);
		foreach ($this->replies->page($topic, $limit, $start) as $reply)
		{
			$reply['URL'] = append_sid($this->root . 'viewtopic.' . $this->php_ext, 'p=' . $reply['ID']) . '#p' . $reply['ID'];
			$this->template->assign_block_vars('kb_replies', $reply);
		}
		return $start;
	}

	public function article($article)
	{
		$id = (int) $article;
		$a = $this->articles->find($id);
		$this->breadcrumbs($a['cat_id']);
		$params = array('article' => $id);
		$print = $this->request->variable('print', 0) === 1;
		if (!$print) { $this->navigation(); }
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
				$this->template->assign_var('KB_SCORE', $this->score_label($score['average'], $score['count']));
			}
			if (!$print && $this->articles->may_rate())
			{
				add_form_key('plus_kb_rate_' . $id);
				$this->template->assign_var('U_KB_RATE', $this->helper->route('phpbb2plus_kb_rate', $params));
			}
		}
		$topic = $this->articles->discussion($a['topic_id']);
		if (!$print)
		{
			foreach (array('edit','approve','unapprove','delete') as $action)
			{
				if (!$this->editor->allowed($action, $a)) { continue; }
				$this->template->assign_block_vars('kb_actions', array('LABEL' => $this->language->lang('PLUS_KB_ACTION_' . strtoupper($action)),
					'URL' => $this->helper->route('phpbb2plus_kb_manage', $params + array('mode' => $action))));
			}
		}
		$start = $topic ? $this->discussion_replies($topic, $params, $page, $print) : 0;
		$this->template->assign_vars(array('KB_TITLE' => $a['title'], 'KB_SUMMARY' => $a['description'], 'KB_TYPE' => $a['type_name'],
			'KB_PENDING' => (int) $a['approval'] !== 1, 'KB_AUTHOR' => $this->author($a), 'KB_DATE' => $this->user->format_date($a['created_at']),
			'KB_VIEWS' => $a['views'], 'KB_PRINT' => $print, 'KB_BODY' => $this->article_body($part),
			'U_KB_PRINT' => $this->helper->route('phpbb2plus_kb_article', $params + array('page' => $page, 'print' => 1) + ($start ? array('start' => $start) : array()))));
		return $this->helper->render('@phpbb2plus_knowledgebase/article.html', utf8_htmlspecialchars($a['title']));
	}

	private function article_body($part)
	{
		$html = generate_text_for_display($part['content'], $part['bbcode_uid'], $part['bbcode_bitfield'], $part['bbcode_options']);
		return $this->dispatcher ? $this->dispatcher->trigger_event('phpbb2plus.community.acronym_html', array('html' => $html))['html'] : $html;
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
