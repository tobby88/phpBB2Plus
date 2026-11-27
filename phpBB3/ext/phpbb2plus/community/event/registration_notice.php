<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class registration_notice implements EventSubscriberInterface
{
	const KEY = 'plus_registration_notice';
	private $config;
	private $store;
	private $language;
	private $template;
	private $submitted;

	public function __construct($config, $store, $language, $template)
	{
		$this->config = $config;
		$this->store = $store;
		$this->language = $language;
		$this->template = $template;
	}

	public static function getSubscribedEvents()
	{
		return array('core.acp_board_config_edit_add' => 'settings', 'core.validate_config_variable' => 'validate',
			'core.acp_board_config_emoji_enabled' => 'save', 'core.page_footer' => 'message');
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'registration') { return; }
		$this->language->add_lang('info_acp_registration', 'phpbb2plus/community');
		$display = $event['display_vars'];
		$fields = array();
		foreach ($display['vars'] as $name => $definition)
		{
			$fields[$name] = $definition;
			if ($name === 'require_activation')
			{
				$fields[self::KEY] = array('lang' => 'PLUS_REGISTRATION_NOTICE', 'validate' => self::KEY,
					'type' => 'custom', 'function' => array($this, 'field'), 'explain' => true);
			}
		}
		$display['vars'] = $fields;
		$event['display_vars'] = $display;
	}

	private function text()
	{
		return (string) json_decode((string) $this->store->get(self::KEY), true);
	}

	public function field()
	{
		$value = $this->submitted ?? $this->text();
		return '<input id="' . self::KEY . '" name="config[' . self::KEY . ']" type="text" size="40" maxlength="255" value="' .
			utf8_htmlspecialchars($value) . '" />';
	}

	public function validate($event)
	{
		if ($event['config_name'] !== self::KEY) { return; }
		$values = $event['cfg_array'];
		$this->submitted = htmlspecialchars_decode($values[self::KEY], ENT_COMPAT);
		if (utf8_strlen($this->submitted) > 255)
		{
			$event['error'] = array_merge($event['error'], array($this->language->lang('SETTING_TOO_LONG',
				$this->language->lang('PLUS_REGISTRATION_NOTICE'), 255)));
		}
		// The native VARCHAR cannot hold a full Plus notice after escaping. Keep
		// this text in config_text and let the native form validate all settings.
		unset($values[self::KEY]);
		$event['cfg_array'] = $values;
	}

	public function save()
	{
		// phpBB emits this event inside its settings-write loop, only after the
		// registration form and all its fields have passed native validation.
		if ($this->submitted === null) { return; }
		$this->store->set(self::KEY, json_encode($this->submitted, JSON_THROW_ON_ERROR));
		$this->submitted = null;
	}

	public function message()
	{
		if (!defined('IN_ERROR_HANDLER') || (int) $this->config['require_activation'] !== USER_ACTIVATION_DISABLE ||
			!$this->template->retrieve_var('S_IN_UCP') || !$this->template->retrieve_var('S_USER_NOTICE') ||
			$this->template->retrieve_var('MESSAGE_TEXT') !== $this->language->lang('UCP_REGISTER_DISABLE')) { return; }
		$notice = $this->text();
		if ($notice !== '') { $this->template->assign_var('MESSAGE_TEXT', utf8_htmlspecialchars($notice)); }
	}
}
