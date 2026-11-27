<?php
namespace phpbb2plus\community\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use phpbb2plus\community\service\post_icons as catalogue;

class post_icons implements EventSubscriberInterface
{
	private $db;
	private $config;
	private $user;
	private $auth;
	private $template;
	private $request;
	private $cache;
	private $icons;
	private $previous = 0;

	public function __construct($db,$config,$user,$auth,$template,$request,$cache)
	{
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->template = $template;
		$this->request = $request;
		$this->cache = $cache;
	}

	public static function getSubscribedEvents()
	{
		return array('core.posting_modify_post_data'=>'remember','core.posting_modify_submission_errors'=>'submission',
			'core.posting_modify_template_vars'=>'editor','core.ucp_pm_compose_template'=>'pm_editor',
			'core.ucp_pm_compose_modify_parse_after'=>'pm_submission','core.submit_pm_before'=>'pm_write',
			'core.viewtopic_modify_post_row'=>'post_row','core.viewforum_modify_topicrow'=>'topic_row',
			'phpbb2plus.community.announcement_topic_row'=>'topic_row');
	}

	private function icons()
	{
		if ($this->icons === null)
		{
			$this->icons = array();
			$result = $this->db->sql_query('SELECT icons_id,plus_icon_level,plus_icon_label FROM ' . ICONS_TABLE . ' WHERE plus_icon_id>0',300);
			while ($row = $this->db->sql_fetchrow($result)) { $this->icons[(int) $row['icons_id']] = $row; }
			$this->db->sql_freeresult($result);
		}
		return $this->icons;
	}

	private function allowed($id,$forum)
	{
		if (!$id) { return true; }
		$native = $this->cache->obtain_icons();
		if (empty($native[$id]['display'])) { return false; }
		$plus = $this->icons()[$id] ?? null;
		if (!$plus) { return true; }
		switch ((int) $plus['plus_icon_level'])
		{
			case 0: return true;
			case 1: return !empty($this->user->data['is_registered']);
			// A moderator of the Knowledge Base can have the generic m_ flag without moderating forum posts.
			case 3: return $this->auth->acl_get('a_icons') || ($forum && $this->auth->acl_gets(
				'm_edit','m_delete','m_lock','m_approve','m_move','m_split','m_merge',$forum));
			case 5: return $this->auth->acl_get('a_icons');
		}
		return false;
	}

	public function remember($event)
	{
		if ($event['mode'] === 'edit') { $this->previous = (int) ($event['post_data']['icon_id'] ?? 0); }
	}

	public function submission($event)
	{
		$id = (int) $event['post_data']['icon_id'];
		if ($id !== $this->previous && !$this->allowed($id,(int) $event['forum_id']))
		{
			$errors = $event['error'];
			$errors[] = $this->user->lang('NO_AUTH_OPERATION');
			$event['error'] = $errors;
		}
	}

	private function options($forum)
	{
		$native = $this->cache->obtain_icons();
		foreach ($this->icons() as $id=>$row)
		{
			if (empty($native[$id]['display'])) { continue; }
			$key = array('ICON_ID'=>$id);
			if (!$this->allowed($id,$forum)) { $this->template->alter_block_array('topic_icon',array(),$key,'delete'); }
			else
			{
				$title = catalogue::label($row['plus_icon_label'],$native[$id]['alt'],$this->user->data['user_lang']);
				$this->template->alter_block_array('topic_icon',array('ICON_ALT'=>$title),$key,'change');
			}
		}
	}

	public function editor($event) { if ($event['s_topic_icons']) { $this->options((int) $event['forum_id']); } }
	public function pm_editor($event) { if (!empty($event['template_ary']['S_SHOW_PM_ICONS'])) { $this->options(0); } }

	public function pm_submission($event)
	{
		$id = $this->request->variable('icon',0);
		if ($id && !$this->allowed($id,0))
		{
			$errors = $event['error'];
			$errors[] = $this->user->lang('NO_AUTH_OPERATION');
			$event['error'] = $errors;
		}
	}
	public function pm_write($event)
	{
		$data = $event['data'];
		if (!empty($data['icon_id']) && !$this->allowed((int) $data['icon_id'],0))
		{
			$data['icon_id'] = 0;
			$event['data'] = $data;
		}
	}

	public function post_row($event)
	{
		$post = $event['post_row'];
		if (empty($post['POST_ICON_IMG'])) { return; }
		$icon = $this->icons()[(int) $event['row']['icon_id']] ?? null;
		if ($icon)
		{
			$post['POST_ICON_IMG_ALT'] = catalogue::label($icon['plus_icon_label'],$post['POST_ICON_IMG_ALT'],$this->user->data['user_lang']);
			$event['post_row'] = $post;
		}
	}

	public function topic_row($event)
	{
		$row = $event['row'];
		$topic = $event['topic_row'];
		if (!empty($topic['TOPIC_ICON_IMG']) || (isset($row['enable_icons']) && !$row['enable_icons'])) { return; }
		$icons = $this->cache->obtain_icons();
		// Core already resolved its selected icon; the announcement list supplies the originating forum setting.
		$id = isset($row['enable_icons']) ? (int) $row['icon_id'] : 0;
		if (empty($icons[$id]['img']))
		{
			$types = array(POST_NORMAL=>'normal',POST_STICKY=>'sticky',POST_ANNOUNCE=>'announce',POST_GLOBAL=>'global_announce');
			$type = $types[(int) $row['topic_type']] ?? 'normal';
			if ($type === 'normal' && !empty($row['plus_cal_start'])) { $type = 'calendar'; }
			$id = (int) $this->config['plus_icon_' . $type];
		}
		if (empty($icons[$id]['img'])) { return; }
		$topic['TOPIC_ICON_IMG'] = $icons[$id]['img'];
		$topic['TOPIC_ICON_IMG_WIDTH'] = $icons[$id]['width'];
		$topic['TOPIC_ICON_IMG_HEIGHT'] = $icons[$id]['height'];
		$plus = $this->icons()[$id] ?? null;
		$topic['TOPIC_ICON_IMG_ALT'] = $plus ? catalogue::label($plus['plus_icon_label'],$icons[$id]['alt'],$this->user->data['user_lang']) : $icons[$id]['alt'];
		$event['topic_row'] = $topic;
	}
}
