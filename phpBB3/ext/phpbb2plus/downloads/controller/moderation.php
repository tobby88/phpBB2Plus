<?php
namespace phpbb2plus\downloads\controller;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\settings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class moderation
{
	private const ROUTE = 'phpbb2plus_downloads_moderation';
	private const FILTERS = array(
		'overview' => 'PLUS_DL_MOD_OVERVIEW',
		'pending' => 'PLUS_DL_PENDING_FILES',
		'broken' => 'PLUS_DL_MOD_BROKEN',
		'published' => 'PLUS_DL_PUBLISHED_FILES',
	);
	private $access;
	private $submissions;
	private $db;
	private $prefix;
	private $request;
	private $lang;
	private $template;
	private $helper;
	private $pagination;
	private $listing;

	public function __construct($access, $submissions, $db, $prefix, $request, $lang, $template, $helper, $pagination, $listing)
	{
		$this->access = $access;
		$this->submissions = $submissions;
		$this->db = $db;
		$this->prefix = $prefix;
		$this->request = $request;
		$this->lang = $lang;
		$this->template = $template;
		$this->helper = $helper;
		$this->pagination = $pagination;
		$this->listing = $listing;
	}

	private function response($status = 200)
	{
		$response = $this->helper->render('@phpbb2plus_downloads/moderation.html', $this->lang->lang('PLUS_DL_MODERATION'), $status);
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}

	public function view()
	{
		$this->access->open();
		$raw_cat = $this->request->variable('cat_id', '0', false, request_interface::GET);
		if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $raw_cat) || $raw_cat > 4294967295) { throw new NotFoundHttpException(); }
		$category = (int) $raw_cat;
		$categories = array_filter(array_keys($this->access->directory()), array($this->access, 'moderates'));
		if (!$categories || ($category && !in_array($category, $categories, true))) { throw new NotFoundHttpException(); }
		$filter = $this->request->variable('status', 'overview', false, request_interface::GET);
		if (!isset(self::FILTERS[$filter])) { throw new NotFoundHttpException(); }
		$this->lang->add_lang('downloads','phpbb2plus/downloads');
		$this->listing->navigation('moderation', $category);
		$sort = $this->listing->sort($this->request->variable('sort_method', ''), $this->request->variable('sort_order', ''));
		$params = array('cat_id'=>$category, 'status'=>$filter) + $sort;
		$start = max(0, $this->request->variable('start', 0, false, request_interface::GET));
		$url = $this->helper->route(self::ROUTE, $params + array('start'=>$start));
		$this->template->assign_vars(array('U_DL_MOD'=>$url, 'U_DL_MOD_FILTER'=>$this->helper->route(self::ROUTE),
			'DL_MOD_SAVED'=>$this->request->is_set('saved', request_interface::GET)));
		if ($this->request->server('REQUEST_METHOD') === 'POST') { return $this->selection($category, $params, $start); }

		$all_cats = $this->access->categories();
		foreach ($categories as $id)
		{
			$this->template->assign_block_vars('mod_categories', array('ID'=>$id, 'NAME'=>access::plain($all_cats[$id]['title']), 'SELECTED'=>$id === $category));
		}
		foreach (self::FILTERS as $key=>$label)
		{
			$this->template->assign_block_vars('mod_filters', array('VALUE'=>$key, 'NAME'=>$this->lang->lang($label), 'SELECTED'=>$key === $filter));
		}
		$this->listing->sort_controls($sort);
		add_form_key('plus_dl_mod_select');
		foreach ($filter === 'overview' ? array('pending','broken','published') : array($filter) as $group)
		{
			$this->group($category ? array($category) : $categories, $group, $params, $start);
		}
		return $this->response();
	}

	private function group($categories, $group, $params, $start)
	{
		$overview = $params['status'] === 'overview';
		$where = $this->db->sql_in_set('f.cat_id', $categories) . ' AND ' .
			($group === 'broken' ? 'f.broken=1' : 'f.approved=' . (int) ($group === 'published'));
		$from = " FROM {$this->prefix}files f JOIN {$this->prefix}categories c ON c.cat_id=f.cat_id WHERE $where";
		$r = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($r);
		$limit = $overview ? 5 : max(1, min(100, (int) $this->access->setting('settings_file_page', 20)));
		$start = $overview ? 0 : $this->pagination->validate_start($start, $limit, $count);
		$params['status'] = $group;
		$url = $this->helper->route(self::ROUTE, $params);
		if (!$overview) { $this->pagination->generate_template_pagination($url, 'pagination', 'start', $count, $limit, $start); }
		$can_review = $this->access->can_review(reset($categories));
		$this->template->assign_block_vars('mod_groups', array('FILTER'=>$group, 'LABEL'=>$this->lang->lang(self::FILTERS[$group]),
			'COUNT'=>$count, 'U_ALL'=>$overview && $count > $limit ? $url : '', 'REVIEW'=>$can_review && $count,
			'APPROVE'=>$group !== 'published', 'WITHDRAW'=>$group !== 'pending'));
		$order = settings::SORT[$params['sort_method']];
		$order = $order === 'rating' ? "(CASE WHEN c.allow_ratings=1 THEN (SELECT AVG(v.points) FROM {$this->prefix}votes v WHERE v.file_id=f.file_id) END)" : 'f.' . $order;
		$r = $this->db->sql_query_limit('SELECT f.file_id,f.title,f.approved,f.review_revision,c.title AS category' . $from .
			' ORDER BY ' . $order . ' ' . $params['sort_order'] . ', f.file_id ' . $params['sort_order'], $limit, $start);
		while ($row = $this->db->sql_fetchrow($r))
		{
			$this->template->assign_block_vars('mod_groups.files', array('ID'=>$row['file_id'], 'NAME'=>access::plain($row['title']),
				'CATEGORY'=>access::plain($row['category']), 'APPROVED'=>(bool) $row['approved'], 'REV'=>$row['review_revision'],
				'URL'=>$this->helper->route('phpbb2plus_downloads_file', array('file'=>(int) $row['file_id']))));
		}
		$this->db->sql_freeresult($r);
	}

	private function selection($category, $params, $start)
	{
		$post = request_interface::POST;
		$confirm = $this->request->is_set('confirm', $post);
		if (!check_form_key($confirm ? 'plus_dl_mod_confirm' : 'plus_dl_mod_select'))
		{
			$this->template->assign_var('DL_MOD_ERROR', $this->lang->lang('FORM_INVALID'));
			return $this->response(400);
		}
		$action = $this->request->variable('operation', '', false, $post);
		try
		{
			$view = $this->submissions->moderate_selection($category, $action,
				$this->request->variable('ids', array(''), false, $post), $this->request->variable('rev', array(0=>''), false, $post),
				$confirm ? $this->request->variable('state', '', false, $post) : null);
		}
		catch (\DomainException $e)
		{
			$this->template->assign_var('DL_MOD_ERROR', $this->lang->lang($e->getMessage()));
			return $this->response($e->getCode() === 409 ? 409 : 400);
		}
		if ($confirm)
		{
			$response = new RedirectResponse($this->helper->route(self::ROUTE, $params + array('start'=>$start, 'saved'=>1), false), 303);
			$response->headers->set('Cache-Control', 'private, no-store');
			return $response;
		}
		foreach ($view['rows'] as $row)
		{
			$this->template->assign_block_vars('selection', array('ID'=>$row['file_id'], 'NAME'=>access::plain($row['title']), 'REV'=>$row['review_revision']));
		}
		$this->template->assign_vars(array('DL_MOD_CONFIRM'=>true, 'DL_OPERATION'=>$action, 'DL_MOD_STATE'=>$view['state'],
			'DL_MOD_QUESTION'=>$this->lang->lang($action === 'approve' ? 'PLUS_DL_MOD_CONFIRM_APPROVE' : 'PLUS_DL_MOD_CONFIRM_WITHDRAW', count($view['changed']))));
		add_form_key('plus_dl_mod_confirm');
		return $this->response();
	}
}
