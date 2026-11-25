<?php
namespace phpbb2plus\gallery\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class navigation implements EventSubscriberInterface
{
	private $helper;
	private $template;
	private $language;
	private $personal;
	private $access;

	public function __construct($helper, $template, $language, $personal, $access)
	{
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->personal = $personal;
		$this->access = $access;
	}

	static public function getSubscribedEvents()
	{
		return array('core.page_header' => 'header', 'core.permissions' => 'permissions', 'core.memberlist_view_profile' => 'profile');
	}

	public function profile($event)
	{
		$member = $event['member'];
		if (!in_array((int) $member['user_type'], array(USER_NORMAL, USER_FOUNDER), true) ||
			!(int) $this->access->setting('show_personal_gallery_link', 1) || !$this->personal->available((int) $member['user_id'])) { return; }
		$this->template->assign_var('U_PROFILE_ALBUM', $this->helper->route('phpbb2plus_gallery_personal', array('owner' => (int) $member['user_id'])));
	}

	public function header()
	{
		$this->language->add_lang('gallery', 'phpbb2plus/gallery');
		$this->template->assign_var('U_PLUS_GALLERY', $this->helper->route('phpbb2plus_gallery_index'));
	}

	public function permissions($event)
	{
		$permissions = $event['permissions'];
		$permissions['a_plus_gallery'] = array('lang' => 'ACL_A_PLUS_GALLERY', 'cat' => 'misc');
		$permissions['u_plus_album_personal'] = array('lang' => 'ACL_U_PLUS_ALBUM_PERSONAL', 'cat' => 'misc');
		$event['permissions'] = $permissions;
	}
}
