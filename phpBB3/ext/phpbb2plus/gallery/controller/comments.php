<?php
namespace phpbb2plus\gallery\controller;

use phpbb\request\request_interface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class comments
{
	private $discussion;
	private $request;
	private $helper;
	private $language;
	private $template;

	public function __construct($discussion, $request, $helper, $language, $template)
	{
		$this->discussion = $discussion;
		$this->request = $request;
		$this->helper = $helper;
		$this->language = $language;
		$this->template = $template;
	}

	private function input($name)
	{
		return htmlspecialchars_decode($this->request->variable($name, '', true, request_interface::POST), ENT_QUOTES);
	}

	private function picture_parameters($picture)
	{
		$params = array('picture' => (int) $picture);
		if ($this->request->variable('sort_order', '') === 'DESC') { $params['sort_order'] = 'DESC'; }
		return $params;
	}

	private function back($picture, $comment = 0)
	{
		$params = $this->picture_parameters($picture);
		if ($comment)
		{
			$offset = $this->discussion->page_start($picture, $comment, $params['sort_order'] ?? 'ASC');
			if ($offset) { $params['start'] = $offset; }
		}
		$url = $this->helper->route('phpbb2plus_gallery_picture', $params, false);
		return new RedirectResponse($url . ($comment ? '#comment-' . (int) $comment : ''), 303);
	}

	public function post($picture)
	{
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		if (!check_form_key('plus_discussion_' . (int) $picture)) { throw new AccessDeniedHttpException(); }
		try
		{
			$id = $this->discussion->publish($picture, $this->input('body'), $this->input('guest_name'),
				$this->request->variable('points', 0, false, request_interface::POST), $this->input('mode') === 'honour');
			return $this->back($picture, $id);
		}
		catch (\DomainException $e) { return $this->helper->message($e->getMessage(), array(), 'INFORMATION', 422); }
	}

	public function bookmark($comment, $mode)
	{
		$row = $this->discussion->comment(null, $comment, $mode);
		$params = $this->picture_parameters($row['pic_id']) + array('comment' => (int) $comment, 'mode' => $mode);
		return new RedirectResponse($this->helper->route('phpbb2plus_gallery_comment', $params, false));
	}

	public function manage($picture, $comment, $mode)
	{
		$row = $this->discussion->comment($picture, $comment, $mode);
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$key = 'plus_album_' . $mode . '_' . (int) $comment;
		$title = $mode === 'delete' ? 'PLUS_ALBUM_DELETE_COMMENT' : 'PLUS_ALBUM_EDIT_COMMENT';
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($key)) { throw new AccessDeniedHttpException(); }
			try
			{
				$this->discussion->change($picture, $comment, $mode, $this->input('body'));
				return $this->back($picture, $mode === 'edit' ? $comment : 0);
			}
			catch (\DomainException $e) { return $this->helper->message($e->getMessage(), array(), 'INFORMATION', 422); }
		}
		add_form_key($key);
		$params = $this->picture_parameters($picture);
		$this->template->assign_vars(array('ALBUM_ACTION_TITLE' => $this->language->lang($title), 'S_COMMENT_DELETE' => $mode === 'delete',
			'ALBUM_BODY' => $row['body'], 'ALBUM_LIMIT' => $this->discussion->text_limit(),
			'U_PICTURE' => $this->helper->route('phpbb2plus_gallery_picture', $params),
			'U_ACTION' => $this->helper->route('phpbb2plus_gallery_comment', $params + array('comment' => $comment, 'mode' => $mode))));
		return $this->helper->render('@phpbb2plus_gallery/comment_edit.html', $this->language->lang($title));
	}
}
