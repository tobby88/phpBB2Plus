<?php
namespace phpbb2plus\gallery\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class navigation implements EventSubscriberInterface
{
	private $helper;
	private $template;
	private $language;

	public function __construct($helper, $template, $language)
	{
		$this->helper = $helper;
		$this->template = $template;
		$this->language = $language;
	}

	static public function getSubscribedEvents()
	{
		return array('core.page_header' => 'header', 'core.permissions' => 'permissions');
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
		$event['permissions'] = $permissions;
	}
}
