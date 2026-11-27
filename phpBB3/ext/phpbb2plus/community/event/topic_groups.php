<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\forum_display;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class topic_groups implements EventSubscriberInterface
{
	private $settings, $config, $db, $language, $template, $visibility;
	private $posting_forum = false, $box = false, $show_headings = false;
	private $groups = array(), $previous;

	public function __construct($settings, $config, $db, $language, $template, $visibility)
	{
		$this->settings=$settings; $this->config=$config; $this->db=$db; $this->language=$language; $this->template=$template; $this->visibility=$visibility;
	}
	public static function getSubscribedEvents()
	{
		return array('core.viewforum_modify_page_title'=>'forum', 'core.viewforum_get_announcement_topic_ids_data'=>array('news',20),
			'core.viewforum_get_topic_ids_data'=>'regular', 'core.viewforum_modify_sort_data_sql'=>array('filtered_total',20),
			'core.viewforum_modify_topics_data'=>'order', 'core.viewforum_modify_topicrow'=>'heading',
			'core.submit_post_modify_sql_data'=>array('posted',-20), 'core.mcp_change_topic_type_after'=>'moderated');
	}
	public function forum($event)
	{
		$this->posting_forum = !empty($this->config['plus_topic_groups_ready']) && (int) $event['forum_data']['forum_type'] === FORUM_POST;
	}
	private static function news_condition()
	{
		return 't.plus_list_news=1 AND t.plus_legacy_removed=0 AND t.topic_type=' . POST_NORMAL . ' AND t.topic_status<>' . ITEM_MOVED;
	}
	public function news($event)
	{
		if (!$this->posting_forum) { return; }
		$sql=$event['sql_ary'];
		// Native visibility and moderator checks still run for every fetched row, before the list is built.
		$sql['WHERE']='(' . $sql['WHERE'] . ') OR (t.forum_id=' . (int) $event['forum_id'] . ' AND ' . self::news_condition() . ')';
		$event['sql_ary']=$sql;
	}
	public function regular($event)
	{
		if (!$this->posting_forum) { return; }
		$sql=$event['sql_ary'];
		$sql['WHERE']='(' . $sql['WHERE'] . ') AND NOT (' . self::news_condition() . ')';
		$event['sql_ary']=$sql;
	}
	public function filtered_total($event)
	{
		if (!$this->posting_forum) { return; }
		$id = (int) $event['forum_id'];
		$sql = $event['sql_array'];
		$sql['WHERE']='(' . $sql['WHERE'] . ') OR (t.forum_id=' . $id . ' AND ' . self::news_condition() .
			' AND ' . $this->visibility->get_visibility_sql('topic', $id, 't.') . ')';
		$event['sql_array']=$sql;
	}
	public function order($event)
	{
		if (!$this->posting_forum) { return; }
		$this->language->add_lang('forum_display','phpbb2plus/community');
		$this->groups=array(); $this->previous=null;
		$type_names=$this->settings->topic_groups();
		$this->show_headings=$type_names['global'] !== 'normal';
		$this->box=$this->show_headings && $this->settings->get('split_topic_split');
		$this->template->assign_var('S_PLUS_TOPIC_GROUPS', $this->show_headings);
		$this->template->assign_var('S_PLUS_TOPIC_GROUP_BOX', $this->box);
		if (!$this->show_headings) { return; }
		$buckets=array_fill_keys(array_keys(forum_display::GROUP_LABELS),array());
		$native_types=array(POST_GLOBAL=>'global', POST_ANNOUNCE=>'announce', POST_STICKY=>'sticky');
		$rows=$event['rowset'];
		foreach ($event['topic_list'] as $id)
		{
			$row=$rows[$id];
			$type=$native_types[(int) $row['topic_type']] ?? ((int) ($row['plus_list_news'] ?? 0) === 1 && (int) $row['topic_status'] !== ITEM_MOVED ? 'news' : 'normal');
			$group=$type_names[$type] ?? 'normal';
			$buckets[$group][]=$id;
			$this->groups[$id]=$group;
		}
		// Within each selected group, retain phpBB's existing order and the reader's sort choice.
		$event['topic_list']=array_merge(...array_values($buckets));
	}
	public function heading($event)
	{
		if (!$this->posting_forum) { return; }
		$row=$event['topic_row'];
		$group=$this->groups[$event['row']['topic_id']] ?? 'normal';
		$first=$this->previous === null;
		$changed=$this->previous !== $group;
		$this->previous=$group;
		$row['S_TOPIC_TYPE_SWITCH']=($first || ($this->box && $changed)) ? 0 : -1;
		if ($changed && $this->show_headings)
		{ $row['PLUS_GROUP_LABEL']=$this->language->lang(forum_display::GROUP_LABELS[$group]); }
		$event['topic_row']=$row;
	}
	public function posted($event)
	{
		if (empty($this->config['plus_topic_groups_ready']) || (int) $event['topic_type'] === POST_NORMAL ||
			!in_array($event['post_mode'], array('edit_topic','edit_first_post'), true)) { return; }
		$data = $event['sql_data'];
		$data[TOPICS_TABLE]['sql']['plus_list_news'] = 0;
		$event['sql_data']=$data;
	}
	public function moderated($event)
	{
		if (empty($this->config['plus_topic_groups_ready']) || !$event['topic_ids']) { return; }
		$this->db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET plus_list_news=0 WHERE topic_type=' . (int) $event['new_topic_type'] .
			' AND ' . $this->db->sql_in_set('topic_id', array_map('intval', $event['topic_ids'])));
	}
}
