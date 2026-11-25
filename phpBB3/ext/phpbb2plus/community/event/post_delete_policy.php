<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class post_delete_policy implements EventSubscriberInterface
{
	private $config;
	private $auth;
	private $request;
	private $language;
	private $deletions;
	private $files;

	public function __construct($config, $auth, $request, $language, $deletions, $files)
	{
		$this->config = $config;
		$this->auth = $auth;
		$this->request = $request;
		$this->language = $language;
		$this->deletions = $deletions;
		$this->files = $files;
	}

	public static function getSubscribedEvents()
	{
		return array('core.handle_post_delete_conditions' => 'posting', 'core.mcp_main_before' => 'moderation',
			'core.acp_board_config_edit_add' => 'settings', 'core.garbage_collection' => 'finish_files');
	}
	public function finish_files() { $this->files->after_request(); }
	private function keep_requested()
	{
		return in_array((string) ($this->config['plus_post_delete_policy'] ?? '-1'), array('0', '1'), true) &&
			$this->request->is_set_post('confirm') && !$this->request->is_set_post('cancel') && !$this->request->is_set_post('delete_permanent');
	}

	private function restrict()
	{
		$policy = (string) ($this->config['plus_post_delete_policy'] ?? '-1');
		if ($policy === '-1' || $policy === '1') { return false; }
		if ($this->request->is_set_post('confirm') && $this->request->is_set_post('delete_permanent'))
		{
			$this->language->add_lang('deletion', 'phpbb2plus/community');
			trigger_error($this->language->lang('PLUS_PERMANENT_DELETE_DISABLED'));
		}
		$this->auth->restrict_post_deletion();
		return true;
	}

	public function posting($event)
	{
		if ($this->keep_requested())
		{
			if ((int) $event['post_data']['post_visibility'] !== ITEM_APPROVED) { trigger_error('NO_POST'); }
			$this->deletions->from_author((int) $event['post_id'], (int) $event['topic_id'], (int) $event['forum_id']);
		}
		if (!$this->restrict()) { return; }
		if ((int) $event['post_data']['post_visibility'] === ITEM_DELETED) { trigger_error('NO_POST'); }
		$event['is_soft'] = true;
		$event['perm_check'] = 'softdelete';
		$event['force_delete_allowed'] = false;
	}

	public function moderation($event)
	{
		if (!in_array($event['action'], array('delete_topic', 'delete_post'), true)) { return; }
		if ($this->keep_requested())
		{
			$topic = $event['action'] === 'delete_topic';
			$ids = $event['quickmod'] ? array($this->request->variable($topic ? 't' : 'p', 0))
				: $this->request->variable($topic ? 'topic_id_list' : 'post_id_list', array(0));
			$this->deletions->from_mcp($topic, $ids);
		}
		$this->restrict();
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'post' || (string) ($this->config['plus_post_delete_policy'] ?? '-1') === '-1') { return; }
		$this->language->add_lang('deletion', 'phpbb2plus/community');
		$display = $event['display_vars'];
		$vars = array();
		foreach ($display['vars'] as $key => $value)
		{
			if ($value === 'ACP_SUBMIT_CHANGES')
			{
				$vars['legend_plus_deletion'] = 'PLUS_POST_DELETION';
				$vars['plus_post_delete_policy'] = array('lang' => 'PLUS_ALLOW_PERMANENT_DELETE', 'type' => 'radio:yes_no', 'validate' => 'bool', 'explain' => true);
			}
			$vars[$key] = $value;
		}
		$display['vars'] = $vars;
		$event['display_vars'] = $display;
	}
}
