<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class inline_editor implements EventSubscriberInterface
{
	private $config;
	private $user;
	private $request;
	private $template;
	private $language;

	public function __construct($config, $user, $request, $template, $language)
	{
		$this->config = $config;
		$this->user = $user;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_post_row' => array('offer', -100),
			'core.modify_posting_parameters' => 'expand',
			'core.ucp_prefs_view_data' => 'member_form', 'core.ucp_prefs_view_update_data' => 'member_save',
			'core.acp_board_config_edit_add' => 'board_form');
	}

	public function offer($event)
	{
		$row = $event['post_row'];
		$value = (int) ($this->user->data['plus_inline_edit'] ?? -1);
		$enabled = !$this->config['plus_inline_edit_over'] && $value >= 0 ? $value : $this->config['plus_inline_edit'];
		if (!$enabled || empty($row['U_EDIT'])) { return; }
		$this->language->add_lang('inline_editor', 'phpbb2plus/community');
		$row['S_PLUS_INLINE_EDIT'] = true;
		$event['post_row'] = $row;
		$this->template->assign_var('S_PLUS_INLINE_EDIT', true);
	}

	public function expand($event)
	{
		if ($event['mode'] !== 'edit' || !$this->request->is_set_post('plus_inline_expand')) { return; }
		// This button carries the issued editing form forward without choosing any posting action.
		if ($event['submit'] || $event['refresh'] || $event['cancel'] || $this->request->is_set_post('delete') ||
			$this->request->is_set_post('confirm'))
		{
			trigger_error('FORM_INVALID');
		}
		$event['refresh'] = true;
		// An expired editor gets the same validation error and retained input as a native preview.
		// The posting controller checks its key again before rendering any preview.
		if (!check_form_key('posting')) { $event['preview'] = true; }
	}

	public function member_form($event)
	{
		if ($this->config['plus_inline_edit_over']) { return; }
		$this->language->add_lang('inline_editor', 'phpbb2plus/community');
		$value = (int) $this->user->data['plus_inline_edit'];
		if ($event['submit'] && $this->request->is_set_post('plus_inline_edit')) { $value = $this->choice(); }
		$this->template->assign_vars(array('S_PLUS_INLINE_PREF' => true, 'PLUS_INLINE_CHOICE' => $value));
	}

	private function choice()
	{
		$value = $this->request->variable('plus_inline_edit', '', false, request_interface::POST);
		return in_array($value, array('0', '1'), true) ? (int) $value : -1;
	}

	public function member_save($event)
	{
		if ($this->config['plus_inline_edit_over'] || !$this->request->is_set_post('plus_inline_edit')) { return; }
		$values = $event['sql_ary'];
		$values['plus_inline_edit'] = $this->choice();
		$event['sql_ary'] = $values;
	}

	public function board_form($event)
	{
		if ($event['mode'] !== 'post') { return; }
		$this->language->add_lang('inline_editor', 'phpbb2plus/community');
		$display = $event['display_vars'];
		$fields = array('legend_plus_inline_edit' => 'PLUS_INLINE_TITLE',
			'plus_inline_edit' => array('lang' => 'PLUS_INLINE_DEFAULT', 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => false),
			'plus_inline_edit_over' => array('lang' => 'PLUS_INLINE_FORCE', 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => false));
		$vars = array();
		foreach ($display['vars'] as $name => $row)
		{
			if ($row === 'ACP_SUBMIT_CHANGES') { $vars += $fields; }
			$vars[$name] = $row;
		}
		$display['vars'] = $vars + $fields;
		$event['display_vars'] = $display;
	}
}
