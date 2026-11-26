<?php
namespace phpbb2plus\downloads\controller;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\feedback;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class actions
{
	private $access;
	private $feedback;
	private $request;
	private $helper;
	private $language;
	private $template;
	private $listing;

	public function __construct($access, $feedback, $request, $helper, $language, $template, $listing)
	{
		$this->access = $access;
		$this->feedback = $feedback;
		$this->request = $request;
		$this->helper = $helper;
		$this->language = $language;
		$this->template = $template;
		$this->listing = $listing;
	}

	public function publish($file, $operation)
	{
		$this->language->add_lang('downloads', 'phpbb2plus/downloads');
		$id = (int) $file;
		if (!check_form_key('plus_dl_feedback_' . $id)) { throw new AccessDeniedHttpException(); }
		$nonce = $this->request->variable('nonce','',false,request_interface::POST);
		$title = htmlspecialchars_decode($this->request->variable('subject','',true,request_interface::POST),ENT_QUOTES);
		$body = htmlspecialchars_decode($this->request->variable('message','',true,request_interface::POST),ENT_QUOTES);
		$points = $this->request->variable('points',0,false,request_interface::POST);
		if ($operation === 'comment' && $this->request->is_set('preview', request_interface::POST))
		{
			return $this->draft($id, $nonce, $title, $body);
		}
		try { $cid = $this->feedback->publish($id,$operation,$nonce,$title,$body,$points); }
		catch (\DomainException $e)
		{
			if ($operation === 'comment' && $e->getMessage() === 'PLUS_DL_BAD_COMMENT')
			{
				return $this->draft($id, $nonce, $title, $body, $e->getMessage());
			}
			return $this->helper->message($e->getMessage(),array(),'INFORMATION',422);
		}
		$params = array('file' => $id);
		if ($cid) { $params['comment'] = $cid; }
		$url = $this->helper->route('phpbb2plus_downloads_file', $params, false);
		return new RedirectResponse($url . ($cid ? '#comment-' . $cid : '#rating'), 303);
	}

	private function draft($id, $nonce, $subject, $body, $error = '')
	{
		$file = $this->access->file($id);
		if (!$this->feedback->can($file, 'comment')) { throw new NotFoundHttpException(); }
		if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { throw new AccessDeniedHttpException(); }
		if ($error === '')
		{
			try
			{
				$prepared = $this->feedback->prepare_comment($subject, $body);
				$this->template->assign_var('DL_COMMENT_PREVIEW', $this->feedback->render($prepared['body']));
			}
			catch (\DomainException $e) { $error = $e->getMessage(); }
		}
		$this->language->add_lang('posting');
		$this->listing->navigation('', (int) $file['cat_id']);
		add_form_key('plus_dl_feedback_' . $id);
		$this->template->assign_vars(array(
			'DL_FILE_TITLE' => access::plain($file['title']),
			'U_DL_FILE' => $this->helper->route('phpbb2plus_downloads_file', array('file' => $id)),
			'U_DL_COMMENT' => $this->helper->route('phpbb2plus_downloads_feedback', array('file' => $id, 'operation' => 'comment')),
			'DL_COMMENT_OPTIONS' => $this->feedback->comment_options(),
			'DL_COMMENT_ERROR' => $error === '' ? '' : $this->language->lang($error),
			'DL_SUBJECT' => $subject,
			'DL_MESSAGE' => $body,
			'DL_NONCE' => $nonce,
		));
		$response = $this->helper->render('@phpbb2plus_downloads/comment_draft.html',
			$this->language->lang('PLUS_DL_COMMENT'), $error === '' ? 200 : 422);
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}

	public function remove($file, $comment)
	{
		$this->language->add_lang('downloads', 'phpbb2plus/downloads');
		$f = $this->access->file($file);
		$c = $this->feedback->comment($comment,$f['file_id']);
		if (!$this->feedback->can_delete($f,$c)) { throw new NotFoundHttpException(); }
		$form = 'plus_dl_remove_' . $c['comment_id'];
		$params = array('file'=>(int) $f['file_id']);
		$back = $this->helper->route('phpbb2plus_downloads_file', $params, false);
		if ($this->request->server('REQUEST_METHOD') === 'POST')
		{
			if (!check_form_key($form)) { throw new AccessDeniedHttpException(); }
			try { $this->feedback->remove($file,$comment,$this->request->variable('revision','',false,request_interface::POST)); }
			catch (\DomainException $e) { return $this->helper->message($e->getMessage(),array(),'INFORMATION',409); }
			return new RedirectResponse($back,303);
		}
		add_form_key($form);
		$this->listing->navigation('', (int) $f['cat_id']);
		$this->template->assign_vars(array('DL_TITLE'=>access::plain($c['title']), 'DL_REVISION'=>feedback::revision($c), 'U_DL_FILE'=>$back,
			'U_DL_REMOVE'=>$this->helper->route('phpbb2plus_downloads_remove',$params + array('comment'=>(int) $comment))));
		return $this->helper->render('@phpbb2plus_downloads/remove.html',$this->language->lang('PLUS_DL_DELETE_COMMENT'));
	}
}
