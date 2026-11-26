<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class navigation implements EventSubscriberInterface
{
	private $helper;
	private $template;
	private $language;
	private $request;

	public function __construct($helper, $template, $language, $request)
	{
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->request = $request;
	}

	static public function getSubscribedEvents()
	{
		return array('core.page_header' => 'header', 'core.index_modify_page_title' => 'home', 'core.permissions' => 'permissions');
	}

	public function permissions($event)
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$this->language->add_lang('info_acp_statistics', 'phpbb2plus/community');
		$this->language->add_lang('info_acp_tracker', 'phpbb2plus/community');
		$permissions = $event['permissions'];
		$permissions['u_plus_shout'] = array('lang' => 'ACL_U_PLUS_SHOUT', 'cat' => 'post');
		$permissions['m_plus_shout'] = array('lang' => 'ACL_M_PLUS_SHOUT', 'cat' => 'misc');
		$permissions['a_plus_credits'] = array('lang' => 'ACL_A_PLUS_CREDITS', 'cat' => 'misc');
		$permissions['a_plus_flags'] = array('lang' => 'ACL_A_PLUS_FLAGS', 'cat' => 'user_group');
		$permissions['a_plus_ct_settings'] = array('lang' => 'ACL_A_PLUS_CT_SETTINGS', 'cat' => 'misc');
		$permissions['a_plus_ct_notice'] = array('lang' => 'ACL_A_PLUS_CT_NOTICE', 'cat' => 'misc');
		$permissions['a_plus_stats_private'] = array('lang' => 'ACL_A_PLUS_STATS_PRIVATE', 'cat' => 'misc');
		foreach (\phpbb2plus\community\service\statistics_admin::RIGHTS as $right)
		{
			$permissions[$right] = array('lang' => 'ACL_' . strtoupper($right), 'cat' => 'misc');
		}
		$permissions['f_plus_read_if_listed'] = array('lang'=>'ACL_F_PLUS_READ_IF_LISTED','cat'=>'actions');
		$event['permissions'] = $permissions;
	}

	public function header()
	{
		$this->language->add_lang('community', 'phpbb2plus/community');
		$this->template->assign_vars(array('U_PLUS_PORTAL' => $this->helper->route('phpbb2plus_community_portal'),
			'U_PLUS_STATISTICS' => $this->helper->route('phpbb2plus_community_statistics'),
			'U_PLUS_CREDITS' => $this->helper->route('phpbb2plus_community_credits')));
	}

	public function home()
	{
		$uri = $this->request->server('REQUEST_URI', '');
		$method = $this->request->server('REQUEST_METHOD', '');
		if (!in_array($method, array('HEAD', 'GET'), true)) { return; }
		$category = $this->request->variable('c', 0);
		if ($category > 0)
		{
			redirect($this->helper->route('phpbb2plus_community_forum_link', array('alias'=>'forumc' . $category), false));
		}
		if ($uri !== '' && substr($uri, -1) === '/' && strpos($uri, '?') === false)
		{
			redirect($this->helper->route('phpbb2plus_community_portal', array(), false));
		}
	}
}
