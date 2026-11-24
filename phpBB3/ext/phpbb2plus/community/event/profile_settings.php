<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class profile_settings implements EventSubscriberInterface
{
	private $template;
	private $language;
	private $imported = false;

	public function __construct($template,$language) { $this->template = $template; $this->language = $language; }

	public static function getSubscribedEvents()
	{
		return array('core.acp_profile_create_edit_init'=>'initialise', 'core.acp_profile_create_edit_after'=>'form',
			'core.acp_profile_create_edit_save_before'=>'save');
	}

	public function initialise($event)
	{
		$this->imported = !empty($event['field_row']['plus_source_id']);
		if (!$this->imported) { return; }
		$visibility = $event['visibility_ary'];
		$visibility[] = 'plus_view_profile';
		$event['visibility_ary'] = $visibility;
		$steps = $event['exclude'];
		$steps[1][] = 'plus_view_profile';
		$event['exclude'] = $steps;
	}

	public function form($event)
	{
		if (!$this->imported) { return; }
		$this->language->add_lang('community','phpbb2plus/community');
		$this->template->assign_vars(array('S_PLUS_PROFILE_DISPLAY_SETTING'=>true,
			'S_PLUS_PROFILE_DISPLAY'=>(bool) $event['field_data']['plus_view_profile']));
	}

	public function save($event)
	{
		if (!$this->imported) { return; }
		$row = $event['profile_fields'];
		$row['plus_view_profile'] = (int) !empty($event['field_data']['plus_view_profile']);
		$event['profile_fields'] = $row;
	}
}
