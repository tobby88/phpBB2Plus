<?php
namespace phpbb2plus\community\controller;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class member_notice implements EventSubscriberInterface
{
	private $notices;
	private $user;
	private $request;
	private $helper;
	private $language;
	private $template;

	public function __construct($notices, $user, $request, $helper, $language, $template)
	{
		$this->notices = $notices;
		$this->user = $user;
		$this->request = $request;
		$this->helper = $helper;
		$this->language = $language;
		$this->template = $template;
	}

	public static function getSubscribedEvents() { return array('core.page_header' => 'header'); }

	private function local_page($path)
	{
		if (!is_string($path) || $path === '' || strlen($path) > 8192 || $path[0] !== '/') { return false; }
		$decoded = rawurldecode($path);
		return substr($decoded, 0, 2) !== '//' && !preg_match('~[\\\\\x00-\x1f\x7f]~', $decoded);
	}

	private function form_name($revision, $return)
	{
		return 'plus_member_notice_' . hash('sha256', $this->user->session_id . "\0" . $revision . "\0" . $return);
	}

	public function header($event)
	{
		if (empty($this->user->data['is_registered']) || empty($this->user->data['plus_notice_pending'])) { return; }
		$state = $this->notices->pending($this->user->data['user_id']);
		if (!$state || $state['message'] === '') { return; }
		$this->language->add_lang('info_acp_tracker', 'phpbb2plus/community');
		$return = $this->request->raw_variable('REQUEST_URI', '', request_interface::SERVER);
		if ($this->request->server('REQUEST_METHOD') !== 'GET' || !$this->local_page($return))
		{
			global $phpEx;
			$return = rtrim(html_entity_decode($this->user->page['root_script_path'], ENT_QUOTES, 'UTF-8'), '/') . '/index.' . $phpEx;
		}
		// A separate template variable leaves this page's posting, profile or login form token intact.
		add_form_key($this->form_name($state['revision'], $return), '_PLUS_MEMBER_NOTICE');
		$this->template->assign_vars(array('S_PLUS_MEMBER_NOTICE' => true, 'PLUS_NOTICE_REVISION' => $state['revision'],
			'PLUS_NOTICE_RETURN' => $return, 'PLUS_NOTICE_BODY' => html_entity_decode($state['message'], ENT_QUOTES, 'UTF-8'),
			'PLUS_NOTICE_URL' => $state['format'] === 'link' ? $this->notices->link($state['message']) : '',
			'U_PLUS_NOTICE_READ' => $this->helper->route('phpbb2plus_community_member_notice_read')));
	}

	public function acknowledge()
	{
		$revision = $this->request->variable('revision', '', false, request_interface::POST);
		$return = $this->request->raw_variable('return_page', '', request_interface::POST);
		if (empty($this->user->data['is_registered']) || !$this->local_page($return) ||
			!$this->request->is_set_post('form_token') || !$this->request->is_set_post('creation_time') ||
			!check_form_key($this->form_name($revision, $return)))
		{
			throw new AccessDeniedHttpException();
		}
		try { $this->notices->acknowledge($this->user->data['user_id'], $this->user->session_id, $revision); }
		catch (\DomainException $error)
		{
			if ($error->getMessage() === 'NO_AUTH_OPERATION') { throw new AccessDeniedHttpException(); }
			$this->language->add_lang('info_acp_tracker', 'phpbb2plus/community');
			throw new ConflictHttpException($this->language->lang('PLUS_MEMBER_NOTICE_CHANGED'));
		}
		return new RedirectResponse($return, 303, array('Cache-Control' => 'private, no-store'));
	}
}
