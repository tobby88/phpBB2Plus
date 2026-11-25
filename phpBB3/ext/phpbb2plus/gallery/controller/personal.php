<?php
namespace phpbb2plus\gallery\controller;

use phpbb\request\request_interface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class personal
{
	private $service;
	private $access;
	private $request;
	private $helper;
	private $language;
	private $template;
	private $user;
	private $legacy;

	public function __construct($service, $access, $request, $helper, $language, $template, $user, $legacy = null)
	{
		$this->service = $service;
		$this->access = $access;
		$this->request = $request;
		$this->helper = $helper;
		$this->language = $language;
		$this->template = $template;
		$this->user = $user;
		$this->legacy = $legacy;
	}

	private function listing_parameters()
	{
		$params = array();
		$method = $this->request->variable('sort_method', '');
		$direction = $this->request->variable('sort_order', '');
		if (in_array($method, array('pic_time','pic_title','username','pic_view_count','rating','comments','new_comment','pic_id'), true))
		{ $params['sort_method'] = $method; }
		if (in_array($direction, array('ASC','DESC'), true)) { $params['sort_order'] = $direction; }
		$start = $this->request->variable('start', 0);
		if ($start > 0) { $params['start'] = $start; }
		return $params;
	}

	public function bookmark($source)
	{
		$source = (int) $source;
		$category = 0;
		$mode = strtolower($this->request->variable('mode', ''));
		$extra = array();
		if ($mode === 'all' || $mode === 'list')
		{
			$extra['mode'] = $mode;
			$type = strtolower($this->request->variable('type', 'pic'));
			$extra['type'] = in_array($type, array('rating','comment'), true) ? $type : 'pic';
			$extra['cat_id'] = max(0, $this->request->variable('cat_id', 0));
		}
		if ($source < 1 && empty($this->user->data['is_registered']))
		{
			login_box($this->helper->route('phpbb2plus_gallery_personal_legacy', array('source' => $source) + $extra + $this->listing_parameters(), false));
		}
		if ($source === -1) { $category = $this->service->historical_guest_root(); }
		if ($category) { $this->access->category($category); }
		else
		{
			$owner = $source < 1 ? (int) $this->user->data['user_id'] : ($this->legacy ? $this->legacy->member_id($source) : 0);
			$this->service->owner($owner);
			if ($extra)
			{
				$cat = $extra['cat_id'] ? $this->access->category($extra['cat_id']) : null;
				if ($cat && (int) $cat['owner_id'] !== $owner) { throw new NotFoundHttpException(); }
				if ($mode === 'list' && $cat && $cat['parent_id'])
				{
					return new RedirectResponse($this->helper->route('phpbb2plus_gallery_category', array('category' => $cat['cat_id']) + $this->listing_parameters(), false));
				}
				$params = array('owner' => $owner) + $this->listing_parameters();
				if ($mode === 'list')
				{
					$params['type'] = $extra['type'];
					return new RedirectResponse($this->helper->route('phpbb2plus_gallery_member_activity', $params, false));
				}
				return new RedirectResponse($this->helper->route('phpbb2plus_gallery_member_pictures', $params, false));
			}
			$roots = $this->service->roots($owner);
			$visible = array_filter($roots, array($this->access, 'visible'));
			if (!$visible && ($roots || !$this->access->may_create_personal($owner))) { throw new NotFoundHttpException(); }
			if (count($visible) !== 1)
			{ return new RedirectResponse($this->helper->route('phpbb2plus_gallery_personal', array('owner' => $owner) + $this->listing_parameters(), false)); }
			$root = reset($visible);
			$category = (int) $root['cat_id'];
		}
		$params = array('category' => $category) + $this->listing_parameters();
		return new RedirectResponse($this->helper->route('phpbb2plus_gallery_category', $params, false));
	}

	private function setup($owner)
	{
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$account = $this->service->owner($owner);
		$title = $this->language->lang('PLUS_ALBUM_OWNER_TITLE', html_entity_decode($account['username'], ENT_QUOTES, 'UTF-8'));
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME' => $this->language->lang('PLUS_ALBUM'),
			'U_VIEW_FORUM' => $this->helper->route('phpbb2plus_gallery_index')));
		$this->template->assign_vars(array('ALBUM_PERSONAL_TITLE' => $title, 'U_PERSONAL' => $this->helper->route('phpbb2plus_gallery_personal', array('owner' => $owner))));
		return $title;
	}

	public function management_bookmark($source)
	{
		$source = (int) $source;
		$id = $this->request->variable('cat_id', 0, false, request_interface::GET);
		$mode = $this->request->variable('action', '', false, request_interface::GET);
		if ($id < 0 || !in_array($mode, array('', 'edit', 'delete'), true)) { throw new NotFoundHttpException(); }
		if (empty($this->user->data['is_registered']))
		{
			login_box($this->helper->route('phpbb2plus_gallery_personal_manage_legacy', array('source' => $source, 'cat_id' => $id, 'action' => $mode), false));
		}
		$actor = (int) $this->user->data['user_id'];
		$owner = $source ? ($this->legacy ? $this->legacy->member_id($source) : 0) : $actor;
		if (!empty($this->user->data['user_perm_from']) || ($owner !== $actor && !$this->access->administrator())) { throw new NotFoundHttpException(); }
		$this->service->owner($owner);
		try
		{
			$tree = $this->service->tree($owner);
			if (!$tree && !$id)
			{
				$this->service->review($owner, $tree, 0, 'start');
				$mode = 'start';
			}
			else
			{
				if ($id && (!isset($tree[$id]) || !$this->access->may_manage_personal($tree[$id]))) { throw new NotFoundHttpException(); }
				$manageable = array_filter($tree, array($this->access, 'may_manage_personal'));
				if (!$manageable || ($mode === 'delete' && !$id)) { throw new NotFoundHttpException(); }
				if ($mode === 'edit' && !$id)
				{
					// A historic root edit omitted cat_id. Multiple imported roots require an explicit choice.
					$roots = array_filter($manageable, function ($cat) { return !(int) $cat['parent_id']; });
					if (count($roots) === 1) { $id = (int) key($roots); }
					else { $mode = ''; }
				}
				if ($mode !== '') { $this->service->review($owner, $tree, $id, $mode); }
			}
		}
		catch (\DomainException $e) { throw new NotFoundHttpException(); }
		$params = array('owner' => $owner);
		$route = 'phpbb2plus_gallery_personal';
		if ($mode !== '')
		{
			$route = 'phpbb2plus_gallery_personal_form';
			$params += array('category' => $id, 'mode' => $mode);
		}
		return new RedirectResponse($this->helper->route($route, $params, false));
	}

	private function action($owner, $category, $mode)
	{
		return $this->helper->route('phpbb2plus_gallery_personal_form', array('owner' => $owner, 'category' => $category, 'mode' => $mode));
	}

	public function index($owner)
	{
		$owner = (int) $owner;
		$title = $this->setup($owner);
		if (!$this->service->available($owner)) { throw new NotFoundHttpException(); }
		if (!empty($this->user->data['is_registered']))
		{ $this->template->assign_var('U_MEMBER_ACTIVITY', $this->helper->route('phpbb2plus_gallery_member_activity', array('owner' => $owner, 'type' => 'pic'))); }
		if ((int) $this->access->setting('show_all_in_personal_gallery', 0))
		{ $this->template->assign_var('U_ALBUM_COLLECTION', $this->helper->route('phpbb2plus_gallery_member_pictures', array('owner' => $owner))); }
		try { $tree = $this->service->tree($owner); }
		catch (\DomainException $e) { return $this->helper->message($e->getMessage(), array(), 'INFORMATION', 409); }
		if (!$tree) { $this->template->assign_var('U_START', $this->action($owner, 0, 'start')); }
		$previous = $next = $last = array();
		foreach ($tree as $id => $cat)
		{
			$parent = (int) $cat['parent_id'];
			if (!$parent) { continue; }
			if (isset($last[$parent])) { $previous[$id] = $last[$parent]; $next[$last[$parent]] = $id; }
			$last[$parent] = $id;
		}
		$room = $this->service->can_add($tree);
		$listing = $this->listing_parameters();
		foreach ($tree as $id => $cat)
		{
			if (!$this->access->visible($cat)) { continue; }
			$urls = array();
			if ($this->access->may_manage_personal($cat))
			{
				$urls['U_EDIT'] = $this->action($owner, $id, 'edit');
				if ($room) { $urls['U_ADD'] = $this->action($owner, $id, 'add'); }
				if ($cat['parent_id'] || $this->access->administrator()) { $urls['U_DELETE'] = $this->action($owner, $id, 'delete'); }
				if (isset($previous[$id]) && $this->access->may_manage_personal($tree[$previous[$id]])) { $urls['U_UP'] = $this->action($owner, $id, 'up'); }
				if (isset($next[$id]) && $this->access->may_manage_personal($tree[$next[$id]])) { $urls['U_DOWN'] = $this->action($owner, $id, 'down'); }
			}
			$this->template->assign_block_vars('personal_cats', $urls + array('TITLE' => $cat['title'], 'DESCRIPTION' => $cat['description'],
				'INDENT' => str_repeat('— ', min(12, $cat['depth'])), 'U_CATEGORY' => $this->helper->route('phpbb2plus_gallery_category', array('category' => $id) + $listing)));
		}
		return $this->helper->render('@phpbb2plus_gallery/personal.html', utf8_htmlspecialchars($title));
	}

	public function form($owner, $category, $mode)
	{
		$owner = (int) $owner;
		$id = (int) $category;
		$title = $this->setup($owner);
		try
		{
			$tree = $this->service->tree($owner);
			$review = $this->service->review($owner, $tree, $id, $mode);
		}
		catch (\DomainException $e) { return $this->helper->message($e->getMessage(), array(), 'INFORMATION', 409); }
		$cat = $tree[$id] ?? array();
		$root = $id && !(int) $cat['parent_id'];
		$editing = $mode === 'edit' || $mode === 'add';
		$fields = array('title' => '', 'description' => '', 'parent' => '', 'destination' => '',
			'view' => '3', 'upload' => '3', 'rate' => '0', 'comment' => '0');
		if ($mode === 'edit')
		{
			$fields['title'] = $cat['title']; $fields['description'] = $cat['description']; $fields['parent'] = (string) $cat['parent_id'];
			foreach (array('view','upload','rate','comment') as $name) { $fields[$name] = (string) $cat[$name . '_level']; }
		}
		$key = 'plus_gallery_personal_' . $owner . '_' . $id . '_' . $mode;
		$error = '';
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($key)) { throw new AccessDeniedHttpException(); }
			foreach (array_keys($fields) as $name)
			{
				$fields[$name] = trim(htmlspecialchars_decode($this->request->variable($name, '', true, request_interface::POST), ENT_QUOTES));
			}
			try
			{
				$this->service->change($owner, $id, $mode, $fields, $this->request->variable('state', '', false, request_interface::POST), $this->language->lang('PLUS_ALBUM_PERSONAL'));
				$target = $this->service->available($owner) ? $this->helper->route('phpbb2plus_gallery_personal', array('owner' => $owner), false) : $this->helper->route('phpbb2plus_gallery_index', array(), false);
				return new RedirectResponse($target, 303);
			}
			catch (\DomainException $e)
			{
				if ($e->getMessage() === 'PLUS_ALBUM_TREE_CHANGED' || $e->getMessage() === 'PLUS_ALBUM_TREE_INVALID')
				{ return $this->helper->message($e->getMessage(), array(), 'INFORMATION', 409); }
				$error = $this->language->lang($e->getMessage());
			}
		}
		if ($mode === 'add' && !$this->service->can_add($tree)) { $error = $this->language->lang('PLUS_ALBUM_CAT_QUOTA'); }
		if ($editing)
		{
			foreach (array('view','upload','rate','comment') as $name)
			{
				$this->template->assign_block_vars('personal_levels', array('FIELD' => $name, 'LABEL' => $this->language->lang('PLUS_ALBUM_POLICY_' . strtoupper($name))));
				foreach (array(-1 => 'GUESTS', 0 => 'MEMBERS', 3 => 'PRIVATE', 2 => 'MODS', 1 => 'ADMINS') as $level => $label)
				{
					if (in_array($level, array(1,2), true) && !$this->access->administrator() && !($mode === 'edit' && $level === (int) $cat[$name . '_level'])) { continue; }
					$this->template->assign_block_vars('personal_levels.options', array('VALUE' => $level, 'LABEL' => $this->language->lang('PLUS_ALBUM_LEVEL_' . $label),
						'SELECTED' => $fields[$name] === (string) $level));
				}
			}
		}
		if (($mode === 'edit' && !$root) || $mode === 'delete')
		{
			foreach ($this->service->destinations($tree, $id, $mode) as $other => $row)
			{
				$this->template->assign_block_vars('personal_choices', array('ID' => $other, 'TITLE' => $row['title'], 'INDENT' => str_repeat('— ', min(12, $row['depth'])),
					'SELECTED' => $fields[$mode === 'edit' ? 'parent' : 'destination'] === (string) $other));
			}
		}
		add_form_key($key);
		$this->template->assign_vars(array('S_PERSONAL_FORM' => true, 'PERSONAL_MODE' => $mode, 'S_PERSONAL_EDIT' => $editing,
			'S_ALBUM_TEXT' => $mode === 'add' || ($mode === 'edit' && !$root), 'S_ALBUM_PARENT' => $mode === 'edit' && !$root,
			'ALBUM_CATEGORY' => $cat['title'] ?? '', 'ALBUM_TITLE' => $fields['title'], 'ALBUM_DESCRIPTION' => $fields['description'],
			'PERSONAL_ACTION' => $this->language->lang('PLUS_ALBUM_CAT_' . strtoupper($mode)), 'PERSONAL_STATE' => $review['state'],
			'ALBUM_ERROR' => $error, 'S_ALBUM_FULL' => $mode === 'add' && !$this->service->can_add($tree),
			'PICTURE_COUNT' => $review['pictures'], 'CHILD_COUNT' => $cat['children'] ?? 0, 'U_ACTION' => $this->action($owner, $id, $mode)));
		return $this->helper->render('@phpbb2plus_gallery/personal.html', utf8_htmlspecialchars($title), $error === '' ? 200 : 422);
	}
}
