<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use phpbb2plus\community\service\announcements as duration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class announcements implements EventSubscriberInterface
{
	private $schedule;
	private $config;
	private $auth;
	private $request;
	private $language;
	private $form_scope;
	private $original = array(0,0);
	private $keep = false;

	public function __construct($schedule,$config,$auth,$request,$language)
	{
		$this->schedule=$schedule; $this->config=$config; $this->auth=$auth; $this->request=$request; $this->language=$language;
	}

	public static function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_forum_id'=>'view','core.posting_modify_post_data'=>'read',
			'core.posting_modify_template_vars'=>'editor','core.posting_modify_submission_errors'=>'validate',
			'core.submit_post_modify_sql_data'=>'store','core.acp_board_config_edit_add'=>'settings','core.mcp_main_modify_fork_sql'=>'forked');
	}

	public function view($event) { $event['topic_data'] = $this->schedule->on_view($event['topic_data']); }

	private function first($event)
	{
		return $event['mode'] === 'post' || ($event['mode'] === 'edit' && (int) $event['post_id'] > 0 &&
			(int) $event['post_id'] === (int) ($event['post_data']['topic_first_post_id'] ?? 0));
	}

	public function read($event)
	{
		$data = $event['post_data'];
		if (!$this->first($event)) { return; }
		// phpBB has already converted its native seconds to days for the edit form.
		$type = (int) ($data['topic_type'] ?? POST_NORMAL);
		$days = duration::editor_days($type,$data['topic_time_limit'],$data['plus_announce_days'] ?? 0);
		$this->original = array($type,$days);
		$data['topic_time_limit'] = $days;
		$event['post_data'] = $data;
	}

	private function controls_current($forum)
	{
		$type = $this->original[0];
		return !duration::is_announcement($type) || $this->auth->acl_get($type === POST_GLOBAL ? 'f_announce_global' : 'f_announce',$forum);
	}

	private function revision($event,$values)
	{
		return hash('sha256',implode(':',array_merge(array((int) $event['forum_id'],(int) $event['topic_id'],(int) $event['post_id']),$values)));
	}

	public function editor($event)
	{
		if (!$this->first($event)) { return; }
		$page = $event['page_data'];
		if (!$this->controls_current((int) $event['forum_id']))
		{
			$page['S_TYPE_TOGGLE'] = $page['S_TOPIC_TYPE_ANNOUNCE'] = $page['S_TOPIC_TYPE_STICKY'] = false;
			$event['page_data'] = $page;
			return;
		}
		if (!$this->auth->acl_gets('f_announce','f_announce_global',(int) $event['forum_id'])) { return; }
		$this->language->add_lang('announcements','phpbb2plus/community');
		// Retain phpBB's radio buttons and give its one duration field the full source range.
		$page['S_TOPIC_TYPE_ANNOUNCE'] = $page['S_TOPIC_TYPE_STICKY'] = false;
		$page['S_PLUS_ANNOUNCE_LENGTH'] = true;
		$page['PLUS_ANNOUNCE_MAX'] = duration::MAX_DAYS;
		$page['PLUS_ANNOUNCE_REVISION'] = $this->revision($event,$this->original);
		$default = (int) $this->config['plus_announce_default'];
		$page['PLUS_ANNOUNCE_HINT'] = $default > 0 ? $this->language->lang('PLUS_ANNOUNCE_HINT',$default) : $this->language->lang('PLUS_ANNOUNCE_PAUSED');
		$event['page_data'] = $page;
	}

	public function validate($event)
	{
		$this->form_scope = null;
		$this->keep = false;
		if (!$this->first($event)) { return; }
		$data = $event['post_data'];
		$type = (int) $data['topic_type'];
		$this->form_scope = array((int) $event['forum_id'],(int) $event['topic_id'],(int) $event['post_id']);
		$days = $type === POST_NORMAL ? 0 : (int) $data['topic_time_limit'];
		if ($event['mode'] === 'edit' && (duration::is_announcement($type) || duration::is_announcement($this->original[0]) ||
			$this->request->is_set_post('plus_announce_revision')))
		{
			$new = array($type,$days);
			$revision = $this->request->variable('plus_announce_revision','',false,request_interface::POST);
			if (!$this->controls_current((int) $event['forum_id']) || $this->original === $new || hash_equals($this->revision($event,$new),$revision))
			{
				$this->keep = true;
				list($data['topic_type'],$data['topic_time_limit']) = $this->original;
				$event['post_data'] = $data;
				return;
			}
			if ($revision !== '' && !hash_equals($this->revision($event,$this->original),$revision))
			{
				$this->language->add_lang('announcements','phpbb2plus/community');
				$errors = $event['error']; $errors[] = $this->language->lang('PLUS_ANNOUNCE_CHANGED'); $event['error'] = $errors;
				return;
			}
		}
		if (!duration::is_announcement($type) && $type !== POST_STICKY) { return; }
		$max = $type === POST_STICKY ? 999 : duration::MAX_DAYS;
		$raw = $this->request->variable('topic_time_limit',(string) $days,false,request_interface::POST);
		if (!preg_match('/^-?[0-9]{1,8}$/D',$raw) || $days < -1 || $days > $max)
		{
			$this->language->add_lang('announcements','phpbb2plus/community');
			$errors = $event['error'];
			$errors[] = $this->language->lang('PLUS_ANNOUNCE_RANGE',$max);
			$event['error'] = $errors;
			return;
		}
		if ($type === POST_STICKY && $days < 0) { $data['topic_time_limit'] = 0; $event['post_data'] = $data; }
	}

	public function store($event)
	{
		if (!in_array($event['post_mode'],array('post','edit_topic','edit_first_post'),true)) { return; }
		$sql = $event['sql_data'];
		if (!isset($sql[TOPICS_TABLE]['sql']['topic_time_limit'])) { return; }
		$data = $event['data'];
		$from_form = $this->form_scope === array((int) $data['forum_id'],(int) ($data['topic_id'] ?? 0),(int) ($data['post_id'] ?? 0));
		if ($from_form && $this->keep)
		{
			unset($sql[TOPICS_TABLE]['sql']['topic_type'],$sql[TOPICS_TABLE]['sql']['topic_time_limit']);
			$event['sql_data'] = $sql;
			$this->form_scope = null;
			return;
		}
		if ($from_form && $this->form_scope[1] && (duration::is_announcement($event['topic_type']) || duration::is_announcement($this->original[0])) &&
			!$this->schedule->still_current($this->form_scope,$this->original))
		{
			$this->language->add_lang('announcements','phpbb2plus/community');
			throw new ConflictHttpException($this->language->lang('PLUS_ANNOUNCE_CHANGED'));
		}
		if (duration::is_announcement($event['topic_type']))
		{
			$days = (int) $data['topic_time_limit'];
			// Zero is the native API's permanent duration. The Plus form additionally offers the board default.
			list($seconds,$extra) = duration::storage(!$from_form && !$days ? -1 : $days,(int) $this->config['plus_announce_default']);
			$sql[TOPICS_TABLE]['sql']['topic_time_limit'] = $seconds;
			$sql[TOPICS_TABLE]['sql']['plus_announce_days'] = $extra;
		}
		else { $sql[TOPICS_TABLE]['sql']['plus_announce_days'] = 0; }
		$this->form_scope = null;
		$event['sql_data'] = $sql;
	}

	public function forked($event)
	{
		$sql = $event['sql_ary'];
		$sql['topic_time_limit'] = (int) $event['topic_row']['topic_time_limit'];
		$sql['plus_announce_days'] = (int) $event['topic_row']['plus_announce_days'];
		$event['sql_ary'] = $sql;
	}

	public function settings($event)
	{
		if ($event['mode'] !== 'post') { return; }
		$this->language->add_lang('announcements','phpbb2plus/community');
		$display = $event['display_vars'];
		$fields = array();
		$options = array('legend_plus_announcements'=>'PLUS_ANNOUNCEMENTS',
			'plus_announce_default'=>array('lang'=>'PLUS_ANNOUNCE_DEFAULT','validate'=>'int:-1:' . duration::MAX_DAYS,'type'=>'number:-1:' . duration::MAX_DAYS,'explain'=>true),
			'plus_announce_sticky'=>array('lang'=>'PLUS_ANNOUNCE_STICKY','validate'=>'bool','type'=>'radio:yes_no','explain'=>true));
		foreach ($display['vars'] as $key=>$value)
		{
			if ($value === 'ACP_SUBMIT_CHANGES') { $fields += $options; }
			$fields[$key] = $value;
		}
		$display['vars'] = $fields + $options;
		$event['display_vars'] = $display;
	}
}
