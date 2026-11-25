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
	private $user;

	public function __construct($helper, $template, $language, $personal, $access, $user)
	{
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
		$this->personal = $personal;
		$this->access = $access;
		$this->user = $user;
	}

	static public function getSubscribedEvents()
	{
		return array('core.page_header' => 'header', 'core.permissions' => 'permissions', 'core.memberlist_view_profile' => 'profile');
	}

	public function profile($event)
	{
		$member = $event['member'];
		if (empty($this->user->data['is_registered']) || !in_array((int) $member['user_type'], array(USER_NORMAL, USER_FOUNDER), true) ||
			!(int) $this->access->setting('show_personal_gallery_link', 1)) { return; }
		$params = array('owner' => (int) $member['user_id']);
		$this->template->assign_var('U_PROFILE_ALBUM_ACTIVITY', $this->helper->route('phpbb2plus_gallery_member_activity', $params + array('type' => 'pic')));
		if ($this->personal->available($params['owner']))
		{
			$this->template->assign_var('U_PROFILE_ALBUM', $this->helper->route('phpbb2plus_gallery_personal', $params));
			if ((int) $this->access->setting('show_all_in_personal_gallery', 0))
			{ $this->template->assign_var('U_PROFILE_ALBUM_ALL', $this->helper->route('phpbb2plus_gallery_member_pictures', $params)); }
		}
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
		$permissions['a_plus_album_categories'] = array('lang' => 'ACL_A_PLUS_ALBUM_CATEGORIES', 'cat' => 'misc');
		$permissions['a_plus_album_auth'] = array('lang' => 'ACL_A_PLUS_ALBUM_AUTH', 'cat' => 'misc');
		$permissions['a_plus_album_personal_auth'] = array('lang' => 'ACL_A_PLUS_ALBUM_PERSONAL_AUTH', 'cat' => 'misc');
		$permissions['u_plus_album_personal'] = array('lang' => 'ACL_U_PLUS_ALBUM_PERSONAL', 'cat' => 'misc');
		$event['permissions'] = $permissions;
	}
}
