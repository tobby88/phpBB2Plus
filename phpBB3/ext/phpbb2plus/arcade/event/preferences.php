<?php
namespace phpbb2plus\arcade\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class preferences implements EventSubscriberInterface
{
	private $db;
	private $user;
	private $request;
	private $language;
	private $template;
	private $members;

	public function __construct($db,$user,$request,$language,$template,$members)
	{
		$this->db = $db;
		$this->user = $user;
		$this->request = $request;
		$this->language = $language;
		$this->template = $template;
		$this->members = $members;
	}

	public static function getSubscribedEvents()
	{
		return array('core.ucp_prefs_personal_data'=>'show','core.ucp_prefs_personal_update_data'=>'save');
	}

	private function current($id)
	{
		$r = $this->db->sql_query('SELECT block_pm FROM ' . $this->members . ' WHERE user_id=' . (int) $id);
		$row = $this->db->sql_fetchrow($r);
		$this->db->sql_freeresult($r);
		return $row;
	}

	public function show($event)
	{
		$this->language->add_lang('arcade','phpbb2plus/arcade');
		$row = $this->current($this->user->data['user_id']);
		$receive = $row && (int) $row['block_pm'] === 0;
		if ($event['submit'] && $this->request->is_set_post('plus_arcade_pm'))
		{
			$value = $this->request->variable('plus_arcade_pm','',false,request_interface::POST);
			if (!in_array($value,array('0','1'),true))
			{
				$errors = $event['error'];
				$errors[] = 'FORM_INVALID';
				$event['error'] = $errors;
			}
			else { $receive = $value === '1'; }
		}
		$this->template->assign_var('PLUS_ARC_RECEIVE_PM',$receive);
	}

	public function save($event)
	{
		if (!$this->request->is_set_post('plus_arcade_pm')) { return; }
		$id = (int) $this->user->data['user_id'];
		$block = $this->request->variable('plus_arcade_pm',1,false,request_interface::POST) ? 0 : 1;
		$this->db->sql_transaction('begin');
		try
		{
			// New phpBB accounts can save this preference before they first visit the arcade.
			$r = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
			$this->db->sql_freeresult($r);
			$row = $this->current($id);
			if ($row)
			{
				$this->db->sql_query("UPDATE $this->members SET block_pm=$block WHERE user_id=$id");
			}
			elseif (!$block)
			{
				$this->db->sql_query('INSERT INTO ' . $this->members . ' ' . $this->db->sql_build_array('INSERT',array('user_id'=>$id,'blocked'=>0,'block_pm'=>$block)));
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}
}
