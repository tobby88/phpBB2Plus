<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class age_pruning implements EventSubscriberInterface
{
	private $pruning;
	private $template;
	private $language;
	private $selections = array();
	private $counts = array();
	private $opened = array();

	public function __construct($pruning, $template, $language)
	{
		$this->pruning = $pruning;
		$this->template = $template;
		$this->language = $language;
	}
	public static function getSubscribedEvents()
	{
		return array('core.prune_sql' => array('select', -1000), 'core.prune_delete_before' => array('remove', -1000),
			'core.prune_forums_settings_template_data' => 'instructions',
			'core.prune_forums_settings_confirm' => 'check_days', 'core.adm_page_footer' => 'report');
	}

	public function instructions($event)
	{
		if ($this->pruning->policy() === '-1') { return; }
		$this->language->add_lang('deletion', 'phpbb2plus/community');
		$data = $event['template_data'];
		$data['L_ACP_PRUNE_FORUMS_EXPLAIN'] = $this->language->lang('PLUS_PRUNE_AGE_EXPLAIN');
		$event['template_data'] = $data;
	}

	public function check_days($event)
	{
		if ($this->pruning->policy() === '-1') { return; }
		$fields = $event['hidden_fields'];
		$posted = (int) $fields['prune_days'];
		$viewed = (int) $fields['prune_vieweddays'];
		if ($posted < 0 || $viewed < 0 || max($posted, $viewed) > 36500 || !($posted || $viewed))
		{
			$this->language->add_lang('deletion', 'phpbb2plus/community');
			trigger_error($this->language->lang('PLUS_PRUNE_PERIOD_REQUIRED'), E_USER_WARNING);
		}
	}

	public function select($event)
	{
		// Another subscriber may run a second, complete prune between the two
		// native events. Each invocation must consume its own selection.
		$this->selections[] = null;
		$policy = $this->pruning->policy();
		if ($policy === '-1') { return; }
		$mode = $event['prune_mode'];
		if (!in_array($policy, array('0', '1'), true) || !in_array($mode, array('posted', 'viewed', 'shadow'), true))
		{
			$this->selections[count($this->selections) - 1] = array('forums' => array());
			$event['sql_and'] .= ' AND 1=0';
			return;
		}
		if ($mode === 'shadow')
		{
			$event['sql_and'] .= ' AND topic_moved_id>0 AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' p WHERE p.topic_id=' . TOPICS_TABLE . '.topic_id)';
			return;
		}
		$flags = (int) $event['prune_flags'];
		$cutoff = (int) $event['prune_date'];
		$limit = (int) $event['prune_limit'];
		$automatic = $event['auto_sync'] && $limit > 0;
		$forums = array();
		foreach (array_unique(array_map('intval', (array) $event['forum_id'])) as $id)
		{
			$forum = $this->pruning->forum($id);
			if (!$forum || (int) $forum['forum_type'] !== FORUM_POST) { continue; }
			if ($automatic)
			{
				$schedule = $this->pruning->schedule($forum, $mode, $cutoff, $flags);
				// phpBB can run the viewed rule immediately after the posted rule,
				// which may already have advanced this forum's next due date.
				if (!$schedule || ((int) $forum['prune_next'] >= time() && empty($this->opened[$id]))) { continue; }
				$forums[$id] = $schedule;
				$this->opened[$id] = true;
			}
			elseif ($this->pruning->manual($id) && $cutoff <= time() - 86400 && $cutoff > time() - 36501 * 86400)
			{
				$forums[$id] = null;
				if (!isset($this->counts[$id])) { $this->counts[$id] = array('name' => $forum['forum_name'], 'topics' => 0, 'posts' => 0); }
			}
		}
		$this->selections[count($this->selections) - 1] = array('mode' => $mode, 'flags' => $flags, 'cutoff' => $cutoff, 'limit' => $limit, 'forums' => $forums);
		$event['sql_and'] .= $forums ? ' AND forum_id IN (' . implode(',', array_keys($forums)) . ') AND ' .
			$this->pruning->filter($mode, $cutoff, $flags) : ' AND 1=0';
	}

	public function remove($event)
	{
		$operation = array_pop($this->selections);
		if ($operation === null) { return; }
		$ids = array_values(array_unique(array_map('intval', $event['topic_list'])));
		$event['topic_list'] = array();
		foreach ($operation['forums'] as $id => $schedule)
		{
			// The native ACP and cron each pass one forum. The deletion service
			// also binds every ID to its locked forum for programmatic calls.
			if ($schedule && count($ids) >= $operation['limit']) { $this->pruning->queue($schedule); }
			$result = $this->pruning->apply($ids, $id, $operation['mode'], $operation['cutoff'], $operation['flags'], $schedule);
			if ($schedule === null)
			{
				$this->counts[$id]['topics'] += $result['topics'];
				$this->counts[$id]['posts'] += $result['posts'];
			}
		}
	}

	public function report()
	{
		if (!$this->counts || !$this->template->retrieve_var('S_PRUNED')) { return; }
		foreach (array_values($this->counts) as $i => $counts)
		{
			$row = $this->template->retrieve_block_vars('pruned[' . $i . ']', array('FORUM_NAME'));
			if (($row['FORUM_NAME'] ?? null) !== $counts['name']) { continue; }
			$this->template->alter_block_array('pruned',
				array('NUM_TOPICS' => $counts['topics'], 'NUM_POSTS' => $counts['posts']), $i, 'change');
		}
		$this->counts = array();
	}
}
