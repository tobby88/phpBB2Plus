<?php
namespace phpbb2plus\community\attachment;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class member_panel implements EventSubscriberInterface
{
	private $auth;
	private $user;
	private $language;
	private $config;
	private $template;
	private $quotas;
	private $legacy;
	private $ucp;
	private $acp;

	public function __construct($auth, $user, $language, $config, $template, $quotas, $legacy, $ucp, $acp)
	{
		$this->auth = $auth;
		$this->user = $user;
		$this->language = $language;
		$this->config = $config;
		$this->template = $template;
		$this->quotas = $quotas;
		$this->legacy = $legacy;
		$this->ucp = $ucp;
		$this->acp = $acp;
	}

	public static function getSubscribedEvents() { return array('core.memberlist_view_profile' => 'profile'); }

	private function url($member, $sort = 'a', $direction = 'a', $start = 0)
	{
		if (empty($this->user->data['is_registered']) || (!$this->config['allow_attachments'] &&
			!($this->config['allow_privmsg'] && $this->config['allow_pm_attach']))) { return ''; }
		$options = array('sk' => $sort, 'sd' => $direction, 'start' => (int) $start);
		if ($member === (int) $this->user->data['user_id'] && $this->auth->acl_get('u_attach'))
		{
			return append_sid($this->ucp, http_build_query(array('i' => 'ucp_attachments', 'mode' => 'attachments') + $options, '', '&'), false);
		}
		if (!$this->auth->acl_get('a_user')) { return ''; }
		// The native ACP does not offer comment sorting.
		if ($options['sk'] === 'b') { $options['sk'] = 'a'; }
		return append_sid($this->acp, http_build_query(array('i' => 'users', 'mode' => 'attach', 'u' => $member) + $options, '', '&'), false, $this->user->session_id);
	}

	public function profile($event)
	{
		$member = (int) $event['member']['user_id'];
		if ($member <= ANONYMOUS || !($url = $this->url($member))) { return; }
		$this->language->add_lang('attachment_profile', 'phpbb2plus/community');
		$used = $this->quotas->occupied($member, false);
		$limit = $this->quotas->allowance($member, false);
		$this->template->assign_vars(array('U_PLUS_MEMBER_ATTACHMENTS' => htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
			'PLUS_UPLOAD_USED' => $used, 'PLUS_UPLOAD_LIMIT' => $limit === '0' ? '' : $limit,
			'PLUS_UPLOAD_PERCENT' => $limit === '0' ? 0 : min(100, (int) round(100.0 * (float) $used / (float) $limit))));
	}

	public function bookmark($source, $sort, $direction, $start)
	{
		if (empty($this->user->data['is_registered']))
		{
			$back = 'app.php/legacy/member/' . (int) $source . '/attachments/' . $sort . '/' . $direction . '/' . (int) $start;
			$url = append_sid($this->ucp, http_build_query(array('mode' => 'login', 'redirect' => $back), '', '&'), false);
			return new RedirectResponse($url, 302, array('Cache-Control' => 'private, no-store'));
		}
		if ((int) $this->user->data['plus_legacy_id'] !== (int) $source && !$this->auth->acl_get('a_user')) { throw new AccessDeniedHttpException(); }
		$member = $this->legacy->member_id($source);
		if (!$member) { throw new NotFoundHttpException(); }
		$url = $this->url($member, $sort, $direction, $start);
		if (!$url) { throw new AccessDeniedHttpException(); }
		return new RedirectResponse($url, 302, array('Cache-Control' => 'private, no-store'));
	}
}
