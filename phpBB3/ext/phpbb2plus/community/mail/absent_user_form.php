<?php
namespace phpbb2plus\community\mail;

class absent_user_form extends \phpbb\message\user_form
{
	private $absence;

	public function set_absence($absence) { $this->absence=$absence; }

	public function bind(\phpbb\request\request_interface $request)
	{
		$this->user->add_lang_ext('phpbb2plus/community','absence_contact');
		parent::bind($request);
	}

	private function absence_error($current,$fresh=false)
	{
		return !empty($current['plus_away']) && !$this->absence->may_contact($fresh) ? 'PLUS_AWAY_NO_EMAIL' : false;
	}

	public function check_allow()
	{
		return parent::check_allow() ?: $this->absence_error($this->absence->person($this->recipient_id));
	}

	public function submit(\messenger $messenger)
	{
		// Bind admission to the recipient's current preferences. Release the row before handing the message to SMTP.
		$this->db->sql_transaction('begin');
		try
		{
			$current=$this->absence->person($this->recipient_id,true);
			$this->recipient_row=$this->get_user_row($this->recipient_id);
			$error=parent::check_allow() ?: $this->absence_error($current,true);
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
		if ($error) { $this->errors[]=$this->user->lang($error); return; }
		parent::submit($messenger);
	}
}
