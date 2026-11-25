<?php
namespace phpbb2plus\gallery\controller;

use phpbb\request\request_interface;
use phpbb2plus\gallery\service\moderation as panel;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class moderation
{
	private $panel;
	private $pictures;
	private $byline;
	private $request;
	private $helper;
	private $template;
	private $language;
	private $user;
	private $pagination;
	private $config;

	public function __construct($panel, $pictures, $byline, $request, $helper, $template, $language, $user, $pagination, $config)
	{
		$this->panel = $panel;
		$this->pictures = $pictures;
		$this->byline = $byline;
		$this->request = $request;
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->user = $user;
		$this->pagination = $pagination;
		$this->config = $config;
	}

	private function post($name, $default)
	{
		return $this->request->variable($name, $default, false, request_interface::POST);
	}

	private function confirmed_ids()
	{
		$selection = $this->post('selection', '');
		if (strlen($selection) > panel::MAX_ROWS * 11) { throw new \DomainException('PLUS_ALBUM_MOD_CHANGED'); }
		$ids = explode(',', $selection);
		if (count($ids) > panel::MAX_ROWS) { throw new \DomainException('PLUS_ALBUM_MOD_CHANGED'); }
		foreach ($ids as $id)
		{
			if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id)) { throw new \DomainException('PLUS_ALBUM_MOD_CHANGED'); }
		}
		return array_map('intval', $ids);
	}

	public function show($category)
	{
		$cat = $this->panel->category($category);
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$params = array('category' => (int) $cat['cat_id']);
		$category_url = $this->helper->route('phpbb2plus_gallery_category', $params);
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_ALBUM'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_gallery_index')));
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => utf8_htmlspecialchars($cat['title']), 'U_VIEW_FORUM' => $category_url));
		$methods = $this->panel->methods($cat);
		$method = $this->request->variable('sort_method', '', false, request_interface::GET);
		if ($method === 'username') { $method = 'pic_user_id'; }
		$sort = array(isset($methods[$method]) ? $method : 'pic_time',
			$this->request->variable('sort_order', '', false, request_interface::GET) === 'ASC' ? 'ASC' : 'DESC');
		$sorted = $params + array('sort_method' => $sort[0], 'sort_order' => $sort[1]);
		$limit = panel::page_size($this->config['topics_per_page']);
		$total = $this->panel->count($cat);
		$start = $this->pagination->validate_start(max(0, $this->request->variable('start', 0, false, request_interface::GET)), $limit, $total);
		$location = $sorted + array('start' => $start);
		$page = null;
		$form = 'plus_album_moderate_' . $params['category'];
		$review = null;
		$problem = '';
		$status = 200;
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			$confirm = $this->post('stage', '') === 'confirm';
			if (!check_form_key($confirm ? $form . '_confirm' : $form)) { throw new AccessDeniedHttpException(); }
			$action = $this->post('operation', '');
			$to = $action === 'move' ? $this->post('destination', 0) : 0;
			try
			{
				if ($action === 'move' && !$to) { throw new \DomainException('PLUS_ALBUM_MOD_TARGET'); }
				if ($confirm)
				{
					$this->panel->confirm($category, $this->confirmed_ids(), $action, $to, $this->post('review_key', ''));
					return new RedirectResponse($this->helper->route('phpbb2plus_gallery_moderate', $location, false), 303);
				}
				$ids = array_values(array_unique($this->post('pic_id', array(0))));
				if (!$ids) { throw new \DomainException('PLUS_ALBUM_MOD_SELECT'); }
				$page = $this->panel->page($cat, $sort, $limit, $start);
				if (!hash_equals($page['key'], $this->post('page_key', '')) || array_diff($ids, $page['ids']))
				{ throw new \DomainException('PLUS_ALBUM_MOD_CHANGED'); }
				$review = $this->panel->review($category, $ids, $action, $to);
			}
			catch (\DomainException $e)
			{
				$problem = $this->language->lang($e->getMessage());
				$status = $e->getMessage() === 'PLUS_ALBUM_MOD_CHANGED' ? 409 : 422;
			}
		}
		$url = $this->helper->route('phpbb2plus_gallery_moderate', $location);
		$this->template->assign_vars(array('ALBUM_TITLE' => $cat['title'], 'U_CATEGORY' => $category_url, 'U_ACTION' => $url, 'ALBUM_ERROR' => $problem));
		if ($review)
		{
			add_form_key($form . '_confirm');
			foreach ($review['rows'] as $pic) { $this->template->assign_block_vars('selected', array('TITLE' => $pic['title'])); }
			$this->template->assign_vars(array('S_REVIEW' => true, 'MOD_OPERATION' => $action, 'MOD_DESTINATION' => $to,
				'MOD_TARGET' => $review['destination'] ? $review['destination']['title'] : '', 'MOD_REVIEW_KEY' => $review['key'],
				'MOD_SELECTION' => implode(',', array_column($review['rows'], 'pic_id'))));
		}
		else
		{
			add_form_key($form);
			$this->pagination->generate_template_pagination($this->helper->route('phpbb2plus_gallery_moderate', $sorted), 'pagination', 'start', $total, $limit, $start);
			if ($page === null) { $page = $this->panel->page($cat, $sort, $limit, $start); }
			$this->assign_rows($page['rows']);
			$destinations = $this->pictures->destinations($cat);
			foreach ($destinations as $to) { $this->template->assign_block_vars('destinations', array('ID' => $to['cat_id'], 'TITLE' => $to['title'])); }
			foreach ($this->panel->actions($cat) as $action)
			{
				if ($action !== 'move' || $destinations) { $this->template->assign_block_vars('actions', array('NAME' => $action)); }
			}
			foreach ($methods as $key => $label)
			{
				$this->template->assign_block_vars('album_sorts', array('VALUE' => $key, 'LABEL' => $this->language->lang($label), 'SELECTED' => $sort[0] === $key));
			}
			$sid = $this->request->variable('sid', '');
			$this->template->assign_vars(array('MOD_PAGE_KEY' => $page['key'], 'ALBUM_SORT_ORDER' => $sort[1], 'ALBUM_SID' => $sid === $this->user->session_id ? $sid : '',
				'U_ALBUM_SORT' => $this->helper->route('phpbb2plus_gallery_moderate', $params)));
		}
		return $this->helper->render('@phpbb2plus_gallery/moderation.html', 'PLUS_ALBUM_MODERATE', $status);
	}

	private function assign_rows($rows)
	{
		foreach ($rows as $pic)
		{
			$this->template->assign_block_vars('pictures', array('ID' => (int) $pic['pic_id'], 'TITLE' => $pic['title'], 'AUTHOR' => $this->byline->display($pic),
				'TIME' => $this->user->format_date($pic['created_at']), 'COMMENTS' => (int) $pic['comments'], 'APPROVED' => (bool) $pic['approved'],
				'LOCKED' => (bool) $pic['locked'], 'RATING' => $pic['rating'] === null ? '' : number_format((float) $pic['rating'], 2, $this->language->lang('PLUS_ALBUM_DECIMAL'), ''),
				'U_PICTURE' => $this->helper->route('phpbb2plus_gallery_picture', array('picture' => (int) $pic['pic_id']))));
		}
	}
}
