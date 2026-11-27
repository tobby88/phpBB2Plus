<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use phpbb2plus\community\service\forum_display as settings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class forum_display implements EventSubscriberInterface
{
	// These are the controls that affect the forum lists rendered by this extension.
	private const CONTROLS = array('last_topic_title', 'sub_level_links');
	private $settings, $config, $request, $language, $template, $db, $auth;
	private $titles = array();

	public function __construct($settings, $config, $request, $language, $template, $db, $auth)
	{
		$this->settings = $settings; $this->config = $config; $this->request = $request;
		$this->language = $language; $this->template = $template; $this->db = $db; $this->auth = $auth;
	}

	public static function getSubscribedEvents()
	{
		return array('core.acp_board_config_edit_add' => 'acp', 'core.ucp_prefs_view_data' => 'member',
			'core.ucp_prefs_view_update_data' => 'save', 'core.display_forums_before' => 'forums',
			'core.display_forums_modify_template_vars' => 'forum_row');
	}

	public function acp($event)
	{
		if ($event['mode'] !== 'features' || !$this->settings->enabled()) { return; }
		$this->language->add_lang('forum_display', 'phpbb2plus/community');
		$fields = array('legend_plus_forum_display' => 'PLUS_DISPLAY_PREFERENCES');
		foreach (self::CONTROLS as $name)
		{
			$key = 'plus_' . $name;
			$choices = settings::choices($name);
			$fields[$key] = array('lang' => 'PLUS_DISPLAY_' . strtoupper($name), 'type' => 'select',
				'validate' => 'int:0:' . (count($choices)-1), 'function' => 'build_select', 'params' => array($choices, '{CONFIG_VALUE}'), 'explain' => false);
			$fields[$key . '_over'] = array('lang' => 'PLUS_DISPLAY_FORCE', 'type' => 'radio:yes_no', 'validate' => 'bool', 'explain' => true);
		}
		$fields['plus_last_topic_title_length'] = array('lang' => 'PLUS_DISPLAY_LENGTH', 'type' => 'number:0:255', 'validate' => 'int:0:255', 'explain' => true);
		$display = $event['display_vars'];
		$display['vars'] = phpbb_insert_config_array($display['vars'], $fields, array('before' => 'legend2'));
		$event['display_vars'] = $display;
	}

	private function picked($name)
	{
		$value = $this->request->variable('plus_' . $name, '', false, request_interface::POST);
		return $value === '-1' || settings::valid($name, $value) ? (int) $value : $this->settings->selected($name);
	}

	public function member($event)
	{
		$this->language->add_lang('forum_display', 'phpbb2plus/community');
		foreach (self::CONTROLS as $name)
		{
			if (!$this->settings->personal($name)) { continue; }
			$value = $event['submit'] ? $this->picked($name) : $this->settings->selected($name);
			$this->template->assign_block_vars('plus_display_pref', array('FIELD' => 'plus_' . $name,
				'LABEL' => $this->language->lang('PLUS_DISPLAY_' . strtoupper($name))));
			foreach (array(-1=>'PLUS_DISPLAY_INHERIT') + settings::choices($name) as $id => $key)
			{
				$this->template->assign_block_vars('plus_display_pref.option', array('VALUE' => $id, 'SELECTED' => $id === $value,
					'LABEL' => $this->language->lang($key)));
			}
		}
	}

	public function save($event)
	{
		$data = $event['sql_ary'];
		foreach (self::CONTROLS as $name)
		{
			if ($this->settings->personal($name) && $this->request->is_set_post('plus_' . $name)) { $data['plus_' . $name] = $this->picked($name); }
		}
		$event['sql_ary'] = $data;
	}

	public function forums($event)
	{
		$this->titles = array();
		if (!$this->settings->enabled()) { return; }
		$this->template->assign_var('S_PLUS_SUBFORUM_TEXT', $this->settings->get('sub_level_links') === 1);
		if (!$this->settings->get('last_topic_title') || empty($this->config['display_last_subject'])) { return; }
		$posts = array();
		foreach ($event['forum_rows'] as $row)
		{
			if (!empty($row['forum_last_post_id']) && isset($row['forum_password_last_post']) && $row['forum_password_last_post'] === '' &&
				$this->auth->acl_gets('f_read', 'f_list_topics', (int) $row['forum_id_last_post']))
			{ $posts[(int) $row['forum_last_post_id']] = (int) $row['forum_id_last_post']; }
		}
		if (!$posts) { return; }
		$result = $this->db->sql_query('SELECT p.post_id, t.forum_id, t.topic_title FROM ' . POSTS_TABLE . ' p
			JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			WHERE p.post_visibility=' . ITEM_APPROVED . ' AND t.topic_visibility=' . ITEM_APPROVED . ' AND ' . $this->db->sql_in_set('p.post_id', array_keys($posts)));
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($posts[(int) $row['post_id']] === (int) $row['forum_id'])
			{ $this->titles[$row['post_id']] = utf8_decode_ncr(censor_text($row['topic_title'])); }
		}
		$this->db->sql_freeresult($result);
	}

	public function forum_row($event)
	{
		if (!$this->settings->enabled()) { return; }
		$row = $event['forum_row'];
		$id = (int) $event['row']['forum_last_post_id'];
		$title = $this->titles[$id] ?? '';
		$row['S_DISPLAY_SUBJECT'] = $title !== '';
		$row['LAST_POST_SUBJECT'] = $title;
		$length = min(255, max(0, (int) $this->config['plus_last_topic_title_length']));
		$row['LAST_POST_SUBJECT_TRUNCATED'] = $title === '' ? '' : ($length === 0 ? $this->language->lang('ELLIPSIS') :
			truncate_string($title, $length, 1020, false, $this->language->lang('ELLIPSIS')));
		if (!$this->settings->get('sub_level_links'))
		{
			$row['S_LIST_SUBFORUMS'] = $row['S_SUBFORUMS'] = false;
			$row['SUBFORUMS'] = '';
			$event['subforums_row'] = array();
		}
		$event['forum_row'] = $row;
	}
}
