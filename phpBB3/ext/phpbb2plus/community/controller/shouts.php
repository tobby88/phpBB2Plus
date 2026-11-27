<?php
namespace phpbb2plus\community\controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class shouts
{
	private $shouts;
	private $helper;
	private $template;
	private $language;
	private $request;
	private $pagination;
	private $extension;

	public function __construct($shouts, $helper, $template, $language, $request, $pagination, $extension)
	{
		$this->shouts = $shouts;
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->request = $request;
		$this->pagination = $pagination;
		$this->extension = $extension;
	}

	public function show()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$url = $this->helper->route('phpbb2plus_community_shouts');
		$total = $this->shouts->count();
		$start = $this->pagination->validate_start($this->request->variable('start', 0), 20, $total);
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $total, 20, $start);
		foreach ($this->shouts->listing(20, $start) as $entry)
		{
			$entry['U_EDIT'] = $this->helper->route('phpbb2plus_community_shouts', array('edit' => $entry['ID']));
			$this->template->assign_block_vars('shouts', $entry);
		}
		$id = $this->request->variable('edit', 0);
		$text = '';
		if ($id)
		{
			$row = $this->shouts->find($id);
			if (!$this->shouts->may_change($row)) { throw new AccessDeniedHttpException(); }
			$data = generate_text_for_edit($row['message'], $row['bbcode_uid'], $row['bbcode_options']);
			$text = htmlspecialchars(htmlspecialchars_decode($data['text'], ENT_COMPAT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}
		add_form_key('phpbb2plus/shout');
		$show_editor = $id || $this->shouts->may_post();
		if ($show_editor) { $this->language->add_lang('posting'); }
		$this->template->assign_vars(array('S_PLUS_SHOUT_POST' => $show_editor, 'PLUS_SHOUT_BBCODE' => $this->shouts->bbcode_enabled(),
			'S_PLUS_SHOUT_MOD' => $this->shouts->may_moderate(), 'PLUS_EDIT_ID' => $id, 'PLUS_SHOUT_TEXT' => $text,
			'U_PLUS_SHOUT_WRITE' => $this->helper->route('phpbb2plus_community_shout_write')));
		return $this->helper->render('@phpbb2plus_community/shoutbox.html', $this->language->lang('PLUS_SHOUTBOX'));
	}

	public function write()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$this->language->add_lang('posting');
		if ($this->request->is_set_post('cancel'))
		{
			return new RedirectResponse($this->helper->route('phpbb2plus_community_shouts', array(), false), 303);
		}
		$id = $this->request->variable('id', 0, false, \phpbb\request\request_interface::POST);
		$action = $this->request->variable('action', '', false, \phpbb\request\request_interface::POST);
		if ($action === 'delete' && $this->request->is_set_post('confirm'))
		{
			if (!confirm_box(true) || !$this->shouts->moderate($id, 'delete')) { throw new AccessDeniedHttpException(); }
		}
		else
		{
			if (!check_form_key('phpbb2plus/shout')) { throw new AccessDeniedHttpException(); }
			if ($action === 'delete')
			{
				if (!$this->shouts->may_change($this->shouts->find($id))) { throw new AccessDeniedHttpException(); }
				confirm_box(false, 'PLUS_SHOUT_DELETE', build_hidden_fields(array('action' => 'delete', 'id' => $id)),
					'confirm_body.html', $this->helper->route('phpbb2plus_community_shout_write'));
			}
			else if ($action === 'post' || $action === 'edit')
			{
				if ($action === 'edit' && $id < 1) { throw new AccessDeniedHttpException(); }
				$text = $this->request->variable('message', '', true, \phpbb\request\request_interface::POST);
				$error = $this->shouts->store($text, $action === 'edit' ? $id : 0);
				if ($error) { trigger_error($error); }
			}
			else if (!$this->shouts->moderate($id, $action)) { throw new AccessDeniedHttpException(); }
		}
		$place = $this->request->variable('place', '', false, \phpbb\request\request_interface::POST);
		if ($action === 'post' && $place === 'index')
		{
			return new RedirectResponse(append_sid(generate_board_url() . '/index.' . $this->extension, false, false) . '#plus-shout-panel', 303);
		}
		if ($action === 'post' && $place === 'portal')
		{
			return new RedirectResponse($this->helper->route('phpbb2plus_community_portal', array(), false) . '#plus-shout-panel', 303);
		}
		return new RedirectResponse($this->helper->route('phpbb2plus_community_shouts', array(), false), 303);
	}
}
