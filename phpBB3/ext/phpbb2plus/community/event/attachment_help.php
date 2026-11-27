<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class attachment_help implements EventSubscriberInterface
{
	private $config;
	private $language;

	public function __construct($config, $language)
	{
		$this->config = $config;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.help_manager_add_block_before'=>'extend_attachments');
	}

	public function extend_attachments($event)
	{
		if ($event['block_name'] !== 'HELP_FAQ_BLOCK_ATTACHMENTS') { return; }
		$posts = !empty($this->config['allow_attachments']);
		$messages = !empty($this->config['allow_pm_attach']) && !empty($this->config['allow_privmsg']);
		if (!$posts && !$messages) { return; }
		$this->language->add_lang('attachment_help', 'phpbb2plus/community');
		$questions = $event['questions'];
		if ($posts)
		{
			$questions['PLUS_HELP_FILE_EDIT'] = 'PLUS_HELP_FILE_EDIT_ANSWER';
			$questions['PLUS_HELP_FILE_INLINE'] = 'PLUS_HELP_FILE_INLINE_ANSWER';
			$questions['PLUS_HELP_FILE_MISSING'] = 'PLUS_HELP_FILE_MISSING_ANSWER';
			$questions['PLUS_HELP_FILE_REMOVE'] = 'PLUS_HELP_FILE_REMOVE_ANSWER';
			$questions['PLUS_HELP_FILE_ACCESS'] = 'PLUS_HELP_FILE_ACCESS_ANSWER';
			$questions['PLUS_HELP_FILE_LIMITS'] = 'PLUS_HELP_FILE_LIMITS_ANSWER';
		}
		if ($messages) { $questions['PLUS_HELP_FILE_PM'] = 'PLUS_HELP_FILE_PM_ANSWER'; }
		if (!empty($this->config['plus_attach_quotas'])) { $questions['PLUS_HELP_FILE_QUOTA'] = 'PLUS_HELP_FILE_QUOTA_ANSWER'; }
		$event['questions'] = $questions;
	}
}
