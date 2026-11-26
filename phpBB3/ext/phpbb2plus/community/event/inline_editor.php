<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class inline_editor implements EventSubscriberInterface
{
	private const OPTIONS = array('plus_inline_edit' => 'PLUS_INLINE_TITLE', 'plus_full_preview' => 'PLUS_FULL_PREVIEW');
	private $config;
	private $user;
	private $request;
	private $template;
	private $language;
	private $auth;

	public function __construct($config, $user, $request, $template, $language, $auth)
	{
		$this->config = $config;
		$this->user = $user;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->auth = $auth;
	}

	public static function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_post_row' => array('offer', -200),
			'core.modify_posting_parameters' => 'expand',
			'core.ucp_prefs_view_data' => 'member_form', 'core.ucp_prefs_view_update_data' => 'member_save',
			'core.acp_board_config_edit_add' => 'board_form');
	}

	public function enabled($option)
	{
		$value = !empty($this->user->data['is_registered']) && empty($this->user->data['is_bot']) ? (int) ($this->user->data[$option] ?? -1) : -1;
		return !$this->config[$option . '_over'] && $value >= 0 ? (bool) $value : (bool) $this->config[$option];
	}

	public function prepare_controls()
	{
		$this->language->add_lang('inline_editor', 'phpbb2plus/community');
		$enabled = $this->enabled('plus_inline_edit');
		if ($enabled) { $this->template->assign_var('S_PLUS_INLINE_EDIT', true); }
		return $enabled;
	}

	public function offer($event)
	{
		$row = $event['post_row'];
		if (empty($row['U_EDIT'])) { return; }
		$topic = $event['topic_data'];
		// The native posting controller requires m_edit to edit in either kind of lock.
		if (((int) $topic['forum_status'] === ITEM_LOCKED || (int) $topic['topic_status'] === ITEM_LOCKED) &&
			!$this->auth->acl_get('m_edit', (int) $topic['forum_id']))
		{
			$row['U_EDIT'] = '';
			$event['post_row'] = $row;
			return;
		}
		if (!$this->prepare_controls()) { return; }
		$row['S_PLUS_INLINE_EDIT'] = true;
		$event['post_row'] = $row;
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
		$this->language->add_lang('inline_editor', 'phpbb2plus/community');
		foreach (self::OPTIONS as $option => $label)
		{
			if ($this->config[$option . '_over']) { continue; }
			$value = (int) $this->user->data[$option];
			if ($event['submit'] && $this->request->is_set_post($option)) { $value = $this->choice($option); }
			$this->template->assign_block_vars('plus_editor_prefs', array('OPTION' => $option, 'LABEL' => $this->language->lang($label), 'VALUE' => $value));
		}
	}

	private function choice($option)
	{
		$value = $this->request->variable($option, '', false, request_interface::POST);
		return in_array($value, array('0', '1'), true) ? (int) $value : -1;
	}

	public function member_save($event)
	{
		$values = $event['sql_ary'];
		foreach (array_keys(self::OPTIONS) as $option)
		{
			if (!$this->config[$option . '_over'] && $this->request->is_set_post($option)) { $values[$option] = $this->choice($option); }
		}
		$event['sql_ary'] = $values;
	}

	public function board_form($event)
	{
		if ($event['mode'] !== 'post') { return; }
		$this->language->add_lang('inline_editor', 'phpbb2plus/community');
		$display = $event['display_vars'];
		$fields = array('legend_plus_inline_edit' => 'PLUS_INLINE_TITLE',
			'plus_inline_edit' => array('lang' => 'PLUS_INLINE_DEFAULT', 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => false),
			'plus_inline_edit_over' => array('lang' => 'PLUS_INLINE_FORCE', 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => false),
			'legend_plus_full_preview' => 'PLUS_FULL_PREVIEW',
			'plus_full_preview' => array('lang' => 'PLUS_FULL_PREVIEW_DEFAULT', 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => false),
			'plus_full_preview_over' => array('lang' => 'PLUS_INLINE_FORCE', 'validate' => 'bool', 'type' => 'radio:yes_no', 'explain' => false));
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
