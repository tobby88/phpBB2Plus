<?php
namespace phpbb2plus\knowledgebase\controller;

use phpbb\request\request_interface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class editor
{
	private $editor;
	private $articles;
	private $request;
	private $user;
	private $language;
	private $helper;
	private $template;

	public function __construct($editor, $articles, $request, $user, $language, $helper, $template)
	{
		$this->editor = $editor;
		$this->articles = $articles;
		$this->request = $request;
		$this->user = $user;
		$this->language = $language;
		$this->helper = $helper;
		$this->template = $template;
	}

	public function form($mode, $article = 0)
	{
		$id = (int) $article;
		$a = $this->editor->context($mode, $id);
		$this->language->add_lang('knowledgebase', 'phpbb2plus/knowledgebase');
		$title = $this->language->lang('PLUS_KB_ACTION_' . strtoupper($mode));
		$params = $id ? array('article' => $id, 'mode' => $mode) : array();
		$action = $this->helper->route($id ? 'phpbb2plus_kb_manage' : 'phpbb2plus_kb_submit', $params);
		$editing = in_array($mode, array('new','edit'), true);
		if ($editing) { $this->language->add_lang('posting'); }
		$form = array('title' => $a['title'] ?? '', 'description' => $a['description'] ?? '', 'body' => $this->editor->editor_text($a),
			'category' => $a ? (int) $a['cat_id'] : $this->request->variable('category', 0), 'type' => $a ? (int) $a['type_id'] : 0, 'guest' => '');
		$revision = $this->editor->form_revision($a);
		$error = '';
		$nonce = bin2hex(random_bytes(16));
		$csrf = 'plus_kb_' . $mode . '_' . $id;
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($csrf)) { throw new AccessDeniedHttpException(); }
			$revision = $this->request->variable('revision', '', false, request_interface::POST);
			$nonce = $this->request->variable('submission', '', false, request_interface::POST);
			try
			{
				if (!$editing)
				{
					$this->editor->moderate($mode, $id, $revision);
					$next = $mode === 'delete' ? $this->helper->route('phpbb2plus_kb_category', array('category' => $a['cat_id']), false) :
						$this->helper->route('phpbb2plus_kb_article', array('article' => $id), false);
					return new RedirectResponse($next, 303);
				}
				foreach ($form as $key => $value)
				{
					$form[$key] = is_int($value) ? $this->request->variable($key, 0, false, request_interface::POST) :
						htmlspecialchars_decode($this->request->variable($key, '', true, request_interface::POST), ENT_QUOTES);
				}
				if ($this->request->is_set_post('preview'))
				{
					foreach ($this->editor->prepare($form) as $page)
					{
						$this->template->assign_block_vars('kb_preview', array('HTML' => generate_text_for_display($page['content'], $page['bbcode_uid'], $page['bbcode_bitfield'], $page['bbcode_options'])));
					}
				}
				else
				{
					$saved = $this->editor->save($mode, $id, $form, $nonce, $revision);
					try { $this->articles->find($saved); }
					catch (NotFoundHttpException $e) { return $this->helper->message('PLUS_KB_SUBMITTED', array(), 'INFORMATION', 200); }
					return new RedirectResponse($this->helper->route('phpbb2plus_kb_article', array('article' => $saved), false), 303);
				}
			}
			catch (\DomainException $e) { $error = $this->language->lang($e->getMessage()); }
		}
		if ($editing)
		{
			foreach ($this->articles->category_list() as $cat)
			{
				$this->template->assign_block_vars('kb_cats', array('ID' => $cat['cat_id'], 'NAME' => $cat['title'], 'CURRENT' => $form['category'] === (int) $cat['cat_id']));
			}
			if ($a && (int) $a['type_id'] === 0)
			{
				$this->template->assign_block_vars('kb_types', array('ID' => 0, 'NAME' => $this->language->lang('PLUS_KB_NO_TYPE'), 'CURRENT' => $form['type'] === 0));
			}
			foreach ($this->articles->types() as $type)
			{
				$this->template->assign_block_vars('kb_types', array('ID' => $type['type_id'], 'NAME' => $type['title'], 'CURRENT' => $form['type'] === (int) $type['type_id']));
			}
		}
		add_form_key($csrf);
		$this->template->assign_vars(array('KB_ACTION' => $title, 'KB_TITLE' => $form['title'], 'KB_DESCRIPTION' => $form['description'],
			'KB_BODY' => $form['body'], 'KB_GUEST_NAME' => $form['guest'], 'KB_GUEST' => empty($this->user->data['is_registered']) && !$id,
			'KB_REVISION' => $revision, 'KB_NONCE' => $nonce, 'KB_ERROR' => $error, 'KB_EDITING' => $editing, 'KB_DELETE' => $mode === 'delete',
			'KB_BBCODE' => $this->editor->bbcode_enabled(),
			'KB_PRETEXT' => $mode === 'new' && (int) $this->articles->setting('show_pretext', 0) ? $this->articles->setting('pt_body', '') : '',
			'KB_PRETEXT_TITLE' => $this->articles->setting('pt_header', ''), 'U_ACTION' => $action,
			'U_CANCEL' => $id ? $this->helper->route('phpbb2plus_kb_article', array('article' => $id)) : $this->helper->route('phpbb2plus_kb_index')));
		return $this->helper->render('@phpbb2plus_knowledgebase/editor.html', $title, $error === '' ? 200 : 422);
	}
}
