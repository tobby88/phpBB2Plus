<?php
namespace phpbb2plus\downloads\controller;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\editor_form;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class submissions
{
	private $access;
	private $submissions;
	private $db;
	private $request;
	private $user;
	private $helper;
	private $template;
	private $language;
	private $pagination;
	private $prefix;
	private $listing;

	public function __construct($access, $submissions, $db, $request, $user, $helper, $template, $language, $pagination, $prefix, $listing)
	{
		$this->access = $access;
		$this->submissions = $submissions;
		$this->db = $db;
		$this->request = $request;
		$this->user = $user;
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->pagination = $pagination;
		$this->prefix = $prefix;
		$this->listing = $listing;
	}

	private function heading($id = 0, $active = '')
	{
		$this->access->open();
		$this->language->add_lang('downloads', 'phpbb2plus/downloads');
		$this->listing->navigation($active, $id);
	}

	public function choose()
	{
		$this->heading(0, 'submit');
		$found = false;
		foreach ($this->access->categories() as $id => $cat)
		{
			if (!$this->access->can_submit($id)) { continue; }
			$this->template->assign_block_vars('dl_destinations', array('TITLE'=>access::plain($cat['title']),
				'U_SUBMIT'=>$this->helper->route('phpbb2plus_downloads_submit',array('category'=>$id))));
			$found = true;
		}
		if (!$found) { throw new NotFoundHttpException(); }
		$this->template->assign_var('DL_CHOOSE', true);
		return $this->helper->render('@phpbb2plus_downloads/submit.html', $this->language->lang('PLUS_DL_SUBMIT'));
	}

	public function form($category)
	{
		if (!$this->access->can_submit((int) $category)) { throw new NotFoundHttpException(); }
		return $this->editor((int) $category);
	}

	public function edit($file)
	{
		$item = $this->access->file((int) $file);
		if (!$this->access->can_manage($item, 'edit_file')) { throw new NotFoundHttpException(); }
		return $this->editor((int) $item['cat_id'], $item);
	}

	private function editor($category, $item = null)
	{
		$this->heading($category, $item ? 'edit' : 'submit');
		$id = $item ? (int) $item['file_id'] : 0;
		$posted = $this->request->server('REQUEST_METHOD') === 'POST';
		$csrf = $id ? 'plus_dl_edit_' . $id : 'plus_dl_submit_' . $category;
		$url = $id ? $this->helper->route('phpbb2plus_downloads_edit',array('file'=>$id)) :
			$this->helper->route('phpbb2plus_downloads_submit',array('category'=>$category));
		$form = editor_form::metadata($this->request,$item,empty($this->user->data['is_registered']) ? '' : access::plain($this->user->data['username']),$posted);
		$fields = $this->request->raw_variable('field', array(), request_interface::POST);
		$existing = $id ? $this->submissions->saved_fields($id) : array();
		$revision = $posted ? $this->request->variable('revision', -1, false, request_interface::POST) : (int) ($item['review_revision'] ?? 0);
		$destination = $this->request->variable('category', $category, false, request_interface::POST);
		$remove_preview = $this->request->variable('remove_preview', false, false, request_interface::POST);
		if ($id)
		{
			$raw = json_decode($item['source_json'], true) ?: array();
			if (!$posted) { $fields = $existing; }
			foreach ($this->access->categories() as $key => $cat)
			{
				if ($key === $category || $this->access->can_submit($key))
				{ $this->template->assign_block_vars('dl_destinations',array('ID'=>$key,'TITLE'=>access::plain($cat['title']),'SELECTED'=>$key === $destination)); }
			}
			$this->template->assign_vars(array('DL_EDITING'=>true,'DL_REVISION'=>$revision, 'DL_CURRENT_SOURCE'=>$item['asset_status'] === 'remote' ?
				($raw['file_dlurl'] ?? '') : ($raw['real_name'] ?? ''), 'DL_HAS_PREVIEW'=>($item['preview_name'] || !empty($raw['file_ssurl'])),
				'DL_REMOVE_PREVIEW'=>$remove_preview,'DL_EMPTY_DESCRIPTION'=>($raw['file_longdesc'] ?? '') === ''));
		}
		$error = '';
		$status = 200;
		$fields_changed = false;
		if ($posted)
		{
			if (!check_form_key($csrf)) { throw new AccessDeniedHttpException(); }
			try
			{
				$original = $this->request->raw_variable('original', array(), request_interface::FILES);
				$preview = $this->request->raw_variable('screenshot', array(), request_interface::FILES);
				if ($item) { $this->submissions->edit($id, $destination, $revision, $form, $fields, $original, $preview, $remove_preview); }
				else { $id = $this->submissions->submit($category, $this->request->variable('nonce', '', false, request_interface::POST), $form, $fields, $original, $preview); }
				try { $this->access->file($id); }
				catch (NotFoundHttpException $e) { return $this->helper->message($item ? 'PLUS_DL_EDITED' : 'PLUS_DL_SUBMITTED',array(),'INFORMATION',200); }
				return new RedirectResponse($this->helper->route('phpbb2plus_downloads_file',array('file'=>$id),false), 303);
			}
			catch (\DomainException $e)
			{
				$error = $this->language->lang($e->getMessage());
				$fields_changed = $e->getMessage() === 'PLUS_DL_FIELDS_CHANGED';
				$status = $e->getCode() ?: 422;
			}
		}
		editor_form::display($this->template,$this->language,$this->submissions,$item,$form,$fields,$existing);
		add_form_key($csrf);
		$this->template->assign_vars(array('DL_NONCE'=>bin2hex(random_bytes(16)),'DL_ERROR'=>$error,
			'DL_STALE'=>$status === 409 && !$fields_changed,'U_DL_SUBMIT'=>$url));
		return $this->helper->render('@phpbb2plus_downloads/submit.html', $this->language->lang($item ? 'PLUS_DL_EDIT' : 'PLUS_DL_SUBMIT'), $status);
	}

	public function remove($file)
	{
		$id = (int) $file;
		$item = $this->access->file($id);
		if (!$this->access->can_manage($item, 'delete_file')) { throw new NotFoundHttpException(); }
		$cat = (int) $item['cat_id'];
		$this->heading($cat);
		$csrf = 'plus_dl_delete_' . $id;
		$params = array('file'=>$id);
		$back = $this->helper->route('phpbb2plus_downloads_file', $params);
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($csrf)) { throw new AccessDeniedHttpException(); }
			try { $this->submissions->remove($id, $this->request->variable('revision', -1, false, request_interface::POST)); }
			catch (\DomainException $e) { return $this->helper->message($e->getMessage(),array(),'INFORMATION',409); }
			return new RedirectResponse($this->helper->route('phpbb2plus_downloads_category',array('category'=>$cat),false), 303);
		}
		add_form_key($csrf);
		$this->template->assign_vars(array('DL_CONFIRM'=>true, 'DL_DELETING'=>true, 'DL_TITLE'=>access::plain($item['title']),
			'DL_ACTION'=>$this->language->lang('PLUS_DL_DELETE'), 'DL_REVISION'=>(int) $item['review_revision'], 'U_DL_BACK'=>$back,
			'U_DL_FILE'=>$back, 'U_DL_REVIEW'=>$this->helper->route('phpbb2plus_downloads_delete',$params)));
		return $this->helper->render('@phpbb2plus_downloads/queue.html', $this->language->lang('PLUS_DL_DELETE'));
	}

	public function queue($category)
	{
		$id = (int) $category;
		if (!$this->access->can_review($id)) { throw new NotFoundHttpException(); }
		$this->heading($id);
		$approved = $this->request->variable('status', 'pending') === 'published';
		$base = array('category'=>$id);
		$route = 'phpbb2plus_downloads_queue';
		$url = $this->helper->route($route,$base + array('status'=>$approved ? 'published' : 'pending'));
		$from = ' FROM ' . $this->prefix . 'files WHERE cat_id=' . $id . ' AND approved=' . (int) $approved;
		$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		$start = $this->pagination->validate_start(max(0,$this->request->variable('start',0)), 25, $count);
		$this->pagination->generate_template_pagination($url,'pagination','start',$count,25,$start);
		$result = $this->db->sql_query_limit('SELECT file_id,title,summary' . $from . ' ORDER BY added_at DESC,file_id DESC',25,$start);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$params = array('file'=>(int) $row['file_id']);
			$this->template->assign_block_vars('dl_queue', array('TITLE'=>access::plain($row['title']), 'SUMMARY'=>access::plain($row['summary']),
				'U_FILE'=>$this->helper->route('phpbb2plus_downloads_file',$params),
				'U_REVIEW'=>$this->helper->route('phpbb2plus_downloads_review',$params + array('action'=>$approved ? 'withdraw' : 'approve')),
				'U_REJECT'=>$approved ? '' : $this->helper->route('phpbb2plus_downloads_review',$params + array('action'=>'reject'))));
		}
		$this->db->sql_freeresult($result);
		$this->template->assign_vars(array('DL_PUBLISHED'=>$approved, 'U_DL_PENDING'=>$this->helper->route($route,$base),
			'U_DL_PUBLISHED'=>$this->helper->route($route,$base + array('status'=>'published'))));
		return $this->helper->render('@phpbb2plus_downloads/queue.html',$this->language->lang('PLUS_DL_REVIEW'));
	}

	public function review($file, $action)
	{
		$id = (int) $file;
		$item = $this->access->file($id);
		$cat = (int) $item['cat_id'];
		if (!$this->access->can_review($cat)) { throw new NotFoundHttpException(); }
		$this->heading($cat);
		$csrf = 'plus_dl_review_' . $action . '_' . $id;
		$back = $this->helper->route('phpbb2plus_downloads_queue', array('category'=>$cat, 'status'=>$action === 'withdraw' ? 'published' : 'pending'), false);
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($csrf)) { throw new AccessDeniedHttpException(); }
			try { $this->submissions->review($id, $action, $this->request->variable('revision', -1, false, request_interface::POST)); }
			catch (\DomainException $e) { return $this->helper->message($e->getMessage(),array(),'INFORMATION',409); }
			return new RedirectResponse($back, 303);
		}
		if (($action === 'withdraw') !== (bool) $item['approved']) { throw new NotFoundHttpException(); }
		add_form_key($csrf);
		$this->template->assign_vars(array('DL_CONFIRM'=>true, 'DL_TITLE'=>access::plain($item['title']), 'DL_ACTION'=>$this->language->lang('PLUS_DL_' . strtoupper($action)),
			'DL_REJECT'=>$action === 'reject','DL_REVISION'=>(int) $item['review_revision'], 'U_DL_BACK'=>$back,
			'U_DL_FILE'=>$this->helper->route('phpbb2plus_downloads_file',array('file'=>$id)),
			'U_DL_REVIEW'=>$this->helper->route('phpbb2plus_downloads_review',array('file'=>$id,'action'=>$action))));
		return $this->helper->render('@phpbb2plus_downloads/queue.html', $this->language->lang('PLUS_DL_REVIEW'));
	}
}
