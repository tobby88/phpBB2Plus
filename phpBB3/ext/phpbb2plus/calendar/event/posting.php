<?php
namespace phpbb2plus\calendar\event;

use phpbb\request\request_interface;
use phpbb2plus\calendar\service\period;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class posting implements EventSubscriberInterface
{
	private $db;
	private $request;
	private $auth;
	private $user;
	private $language;
	private $template;
	private $change = null;

	public function __construct($db, $request, $auth, $user, $language, $template)
	{
		$this->db = $db;
		$this->request = $request;
		$this->auth = $auth;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
	}

	static public function getSubscribedEvents()
	{
		return array('core.permissions'=>'permission', 'core.posting_modify_template_vars'=>'editor',
			'core.posting_modify_submission_errors'=>'validate', 'core.submit_post_modify_sql_data'=>'store',
			'core.viewtopic_assign_template_vars_before'=>'display');
	}

	public function permission($event)
	{
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		$permissions = $event['permissions'];
		$permissions['f_plus_calendar'] = array('lang'=>'ACL_F_PLUS_CALENDAR','cat'=>'post');
		$event['permissions'] = $permissions;
	}

	private function may_edit($event)
	{
		if (!$this->auth->acl_get('f_plus_calendar',(int) $event['forum_id'])) { return false; }
		return $event['mode'] === 'post' || ($event['mode'] === 'edit' && (int) $event['post_id'] > 0 &&
			(int) $event['post_id'] === (int) ($event['post_data']['topic_first_post_id'] ?? 0));
	}

	private function stored($forum, $topic, $lock = false)
	{
		if (!$topic) { return array(0,0); }
		$sql = 'SELECT plus_cal_start,plus_cal_end FROM ' . TOPICS_TABLE . ' WHERE forum_id=' . (int) $forum . ' AND topic_id=' . (int) $topic;
		$result = $this->db->sql_query($sql . ($lock ? ' FOR UPDATE' : ''));
		$r = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$r) { throw new ConflictHttpException($this->language->lang('PLUS_CAL_CONFLICT')); }
		return array((int) $r['plus_cal_start'],(int) $r['plus_cal_end']);
	}

	private function revision($forum, $topic, $values)
	{
		return hash('sha256', implode(':',array_merge(array((int) $forum,(int) $topic),$values)));
	}

	private function fields()
	{
		$fields = array();
		foreach (array('start_date','start_time','end_date','end_time') as $name)
		{
			$fields[$name] = $this->request->variable('plus_cal_' . $name,'',false,request_interface::POST);
		}
		return $fields;
	}

	public function editor($event)
	{
		if (!$this->may_edit($event)) { return; }
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		$topic = $event['mode'] === 'post' ? 0 : (int) $event['topic_id'];
		$stored = $this->stored($event['forum_id'],$topic);
		$posted = $this->request->is_set_post('plus_cal_present');
		$fields = $posted ? $this->fields() : period::values($stored[0],$stored[1],$this->user->create_datetime()->getTimezone());
		$vars = $event['page_data'];
		$vars['S_PLUS_CAL_EDITOR'] = true;
		$vars['S_PLUS_CAL_EXISTING'] = (bool) $stored[0];
		$vars['S_PLUS_CAL_REMOVE'] = $posted && $this->request->is_set_post('plus_cal_remove');
		$vars['PLUS_CAL_REVISION'] = $this->revision($event['forum_id'],$topic,$stored);
		foreach ($fields as $name=>$value) { $vars['PLUS_CAL_' . strtoupper($name)] = $value; }
		$event['page_data'] = $vars;
	}

	public function validate($event)
	{
		$this->change = null;
		if (!$this->request->is_set_post('plus_cal_present') || !$this->may_edit($event)) { return; }
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		$topic = $event['mode'] === 'post' ? 0 : (int) $event['topic_id'];
		$forum = (int) $event['forum_id'];
		$old = $this->stored($forum,$topic);
		$expected = $this->request->variable('plus_cal_revision','',false,request_interface::POST);
		try
		{
			if (!hash_equals($this->revision($forum,$topic,$old),$expected)) { throw new \DomainException('PLUS_CAL_CONFLICT'); }
			$new = period::parse($this->fields(), $this->request->is_set_post('plus_cal_remove'), $old, $this->user->create_datetime()->getTimezone());
			if ($event['submit'] && $new !== $old)
			{
				$this->change = array('forum'=>$forum,'topic'=>$topic,'post'=>(int) $event['post_id'],'old'=>$old,'new'=>$new);
			}
		}
		catch (\DomainException $e)
		{
			$errors = $event['error'];
			$errors[] = $this->language->lang($e->getMessage());
			$event['error'] = $errors;
		}
	}

	public function store($event)
	{
		$change = $this->change;
		$this->change = null;
		if (!$change || !in_array($event['post_mode'],array('post','edit_topic','edit_first_post'),true)) { return; }
		$data = $event['data'];
		if ((int) $data['forum_id'] !== $change['forum'] || (int) ($data['topic_id'] ?? 0) !== $change['topic'] ||
			(int) ($data['post_id'] ?? 0) !== $change['post'] || !$this->auth->acl_get('f_plus_calendar',$change['forum'])) { return; }
		// phpBB has begun the native post transaction. A competing edit must
		// compare against the locked row before the topic and first post are saved.
		if ($this->stored($change['forum'],$change['topic'],true) !== $change['old'])
		{
			$this->db->sql_transaction('rollback');
			throw new ConflictHttpException($this->language->lang('PLUS_CAL_CONFLICT'));
		}
		$sql = $event['sql_data'];
		$sql[TOPICS_TABLE]['sql']['plus_cal_start'] = $change['new'][0];
		$sql[TOPICS_TABLE]['sql']['plus_cal_end'] = $change['new'][1];
		$event['sql_data'] = $sql;
	}

	public function display($event)
	{
		$row = $event['topic_data'];
		if (empty($row['plus_cal_start'])) { return; }
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		$this->template->assign_vars(array('PLUS_CAL_TOPIC_START'=>$this->user->format_date($row['plus_cal_start'],false,true),
			'PLUS_CAL_TOPIC_END'=>(int) $row['plus_cal_start'] !== (int) $row['plus_cal_end'] ? $this->user->format_date($row['plus_cal_end'],false,true) : ''));
	}
}
