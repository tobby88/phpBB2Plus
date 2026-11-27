<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class help_bookmarks implements EventSubscriberInterface
{
	// The phpBB2 FAQ numbered its questions from zero in both supported languages.
	// Match semantic keys, since phpBB3 and extensions may insert or reorder sections.
	private const FAQ = array(
		'LOGIN_CANNOT_LOGIN'=>0, 'LOGIN_REGISTER'=>1, 'LOGIN_AUTO_LOGOUT'=>2, 'USERSETTINGS_HIDE_ONLINE'=>3,
		'LOGIN_LOST_PASSWORD'=>4, 'LOGIN_REGISTER_CONFIRM'=>5, 'LOGIN_CANNOT_LOGIN_ANYMORE'=>6,
		'USERSETTINGS_CHANGE_SETTINGS'=>7, 'USERSETTINGS_TIMEZONE'=>8, 'USERSETTINGS_SERVERTIME'=>9, 'USERSETTINGS_LANGUAGE'=>10,
		'USERSETTINGS_AVATAR_DISPLAY'=>11, 'USERSETTINGS_RANK'=>12, 'USERSETTINGS_EMAIL_LOGIN'=>13,
		'POSTING_CREATE'=>14, 'POSTING_EDIT_DELETE'=>15, 'POSTING_SIGNATURE'=>16, 'POSTING_POLL_CREATE'=>17,
		'POSTING_POLL_EDIT'=>18, 'POSTING_FORUM_RESTRICTED'=>19, 'FORMATTING_BBOCDE'=>21, 'FORMATTING_HTML'=>22,
		'FORMATTING_SMILIES'=>23, 'FORMATTING_IMAGES'=>24, 'FORMATTING_ANNOUNCEMENT'=>25, 'FORMATTING_STICKIES'=>26,
		'FORMATTING_LOCKED'=>27, 'GROUPS_ADMINISTRATORS'=>28, 'GROUPS_MODERATORS'=>29, 'GROUPS_USERGROUPS'=>30,
		'GROUPS_USERGROUPS_JOIN'=>31, 'GROUPS_USERGROUPS_LEAD'=>32, 'PMS_CANNOT_SEND'=>33, 'PMS_UNWANTED'=>34,
		'PMS_SPAM'=>35, 'ISSUES_WHOIS_PHPBB'=>36, 'ISSUES_FEATURE'=>37, 'ISSUES_LEGAL'=>38,
	);
	private const BBCODE = array('INTRO_BBCODE'=>0, 'TEXT_BASIC'=>1, 'TEXT_COLOR'=>2, 'TEXT_COMBINE'=>3, 'QUOTES_TEXT'=>4,
		'QUOTES_CODE'=>5, 'LISTS_UNORDERER'=>6, 'LISTS_ORDERER'=>7, 'LINKS_BASIC'=>8, 'IMAGES_BASIC'=>9, 'OTHERS_CUSTOM'=>10);
	private const PLUS = array('PLUS_HELP_POLL_VOTE'=>array(20), 'PLUS_HELP_FILE_EDIT'=>array(39,40,41,42),
		'PLUS_HELP_FILE_MISSING'=>array(43), 'PLUS_HELP_FILE_LIMITS'=>array(45), 'PLUS_HELP_FILE_REMOVE'=>array(46),
		'PLUS_HELP_FILE_ACCESS'=>array(47));

	private $config;
	private $template;
	private $language;

	public function __construct($config, $template, $language)
	{
		$this->config = $config;
		$this->template = $template;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.help_manager_add_block_before'=>'poll',
			'core.help_manager_add_question_after'=>array('bookmark', 100));
	}

	public function poll($event)
	{
		if ($event['block_name'] !== 'HELP_FAQ_BLOCK_POSTING') { return; }
		$this->language->add_lang('help_topics', 'phpbb2plus/community');
		$event['questions'] = $event['questions'] + array('PLUS_HELP_POLL_VOTE'=>'PLUS_HELP_POLL_VOTE_ANSWER');
	}

	public function bookmark($event)
	{
		$key = $event['question'];
		$ids = self::PLUS[$key] ?? array();
		if (preg_match('/^HELP_(FAQ|BBCODE)_(.+)_QUESTION$/D', $key, $match))
		{
			$map = $match[1] === 'FAQ' ? self::FAQ : self::BBCODE;
			if (isset($map[$match[2]])) { $ids[] = $map[$match[2]]; }
		}
		if ($key === 'HELP_FAQ_POSTING_NO_ATTACHMENTS_QUESTION' && $this->config['allow_attachments']) { $ids[] = 44; }
		if ($key === 'HELP_FAQ_ISSUES_LEGAL_QUESTION' && ($this->config['allow_attachments'] ||
			($this->config['allow_privmsg'] && $this->config['allow_pm_attach']))) { $ids[] = 48; }
		if (!$ids) { return; }
		$row = $this->template->retrieve_block_vars('faq_block.faq_row', array('FAQ_ANSWER'));
		if (!isset($row['FAQ_ANSWER'])) { return; }
		// Questions also occur in the index. Put each alias once at the start of the actual answer.
		$anchors = '';
		foreach ($ids as $id) { $anchors .= '<span class="plus-help-bookmark" id="' . $id . '" aria-hidden="true"></span>'; }
		$this->template->alter_block_array('faq_block.faq_row', array('FAQ_ANSWER'=>$anchors . $row['FAQ_ANSWER']), true, 'change');
	}
}
