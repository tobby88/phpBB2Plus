<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class acronyms implements EventSubscriberInterface
{
	private $dictionary;
	private $language;

	public function __construct($dictionary, $language) { $this->dictionary = $dictionary; $this->language = $language; }

	public static function getSubscribedEvents()
	{
		return array('core.permissions' => 'permissions', 'core.viewtopic_modify_post_row' => 'topic',
			'phpbb2plus.community.acronym_html' => 'content');
	}

	public function topic($event)
	{
		$row = $event['post_row'];
		$row['MESSAGE'] = $this->dictionary->decorate($row['MESSAGE']);
		$event['post_row'] = $row;
	}

	public function content($event) { $event['html'] = $this->dictionary->decorate($event['html']); }

	public function permissions($event)
	{
		$this->language->add_lang('info_acp_acronyms', 'phpbb2plus/community');
		$permissions = $event['permissions'];
		$permissions['a_plus_acronyms'] = array('lang' => 'ACL_A_PLUS_ACRONYMS', 'cat' => 'posting');
		$event['permissions'] = $permissions;
	}
}
