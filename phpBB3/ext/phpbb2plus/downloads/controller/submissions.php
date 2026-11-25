<?php
namespace phpbb2plus\downloads\controller;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
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

	public function __construct($access, $submissions, $db, $request, $user, $helper, $template, $language, $pagination, $prefix)
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
	}

	private function heading($id = 0)
	{
		$this->access->open();
		$this->language->add_lang('downloads', 'phpbb2plus/downloads');
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME'=>$this->language->lang('PLUS_DL'),
			'U_VIEW_FORUM'=>$this->helper->route('phpbb2plus_downloads_index')));
		if ($id)
		{
			$cat = $this->access->categories()[$id];
			$this->template->assign_block_vars('navlinks', array('FORUM_NAME'=>utf8_htmlspecialchars(access::plain($cat['title'])),
				'U_VIEW_FORUM'=>$this->helper->route('phpbb2plus_downloads_category',array('category'=>$id))));
		}
	}

	public function choose()
	{
		$this->heading();
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
		$id = (int) $category;
		if (!$this->access->can_submit($id)) { throw new NotFoundHttpException(); }
		$this->heading($id);
		$csrf = 'plus_dl_submit_' . $id;
		$form = array();
		foreach (array('title','summary','description','version','author','documentation','url','preview_url') as $key)
		{
			$form[$key] = htmlspecialchars_decode($this->request->variable($key, '', true, request_interface::POST), ENT_QUOTES);
		}
		if (!$this->request->is_set_post('author') && !empty($this->user->data['is_registered']))
		{ $form['author'] = access::plain($this->user->data['username']); }
		$form['license'] = $this->request->variable('license', 0, false, request_interface::POST);
		$fields = $this->request->raw_variable('field', array(), request_interface::POST);
		$error = '';
		$status = 200;
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($csrf)) { throw new AccessDeniedHttpException(); }
			try
			{
				$file = $this->submissions->submit($id, $this->request->variable('nonce', '', false, request_interface::POST), $form, $fields,
					$this->request->raw_variable('original', array(), request_interface::FILES),
					$this->request->raw_variable('screenshot', array(), request_interface::FILES));
				try { $this->access->file($file); }
				catch (NotFoundHttpException $e) { return $this->helper->message('PLUS_DL_SUBMITTED',array(),'INFORMATION',200); }
				return new RedirectResponse($this->helper->route('phpbb2plus_downloads_file',array('file'=>$file),false), 303);
			}
			catch (\DomainException $e)
			{
				$error = $this->language->lang($e->getMessage());
				$status = $e->getCode() === 503 ? 503 : 422;
			}
		}
		foreach ($this->submissions->licenses() as $license => $title)
		{
			$this->template->assign_block_vars('dl_licenses', array('ID'=>$license,'TITLE'=>$title,'SELECTED'=>$form['license'] === $license));
		}
		foreach ($this->submissions->fields() as $key => $definition)
		{
			$value = $fields[$key] ?? '';
			$this->template->assign_block_vars('dl_custom', array('ID'=>$key,'TYPE'=>$definition['type'],'TITLE'=>$definition['title'],
				'SUMMARY'=>$definition['description'],'VALUE'=>is_string($value) ? $value : ''));
			foreach ($definition['options'] as $option)
			{
				$this->template->assign_block_vars('dl_custom.choices', array('VALUE'=>$option,'SELECTED'=>in_array($option,(array) $value,true)));
			}
		}
		foreach ($form as $key => $value) { $this->template->assign_var('DL_' . strtoupper($key), $value); }
		add_form_key($csrf);
		$this->template->assign_vars(array('DL_NONCE'=>bin2hex(random_bytes(16)),'DL_ERROR'=>$error,
			'U_DL_SUBMIT'=>$this->helper->route('phpbb2plus_downloads_submit',array('category'=>$id))));
		return $this->helper->render('@phpbb2plus_downloads/submit.html', $this->language->lang('PLUS_DL_SUBMIT'), $status);
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
