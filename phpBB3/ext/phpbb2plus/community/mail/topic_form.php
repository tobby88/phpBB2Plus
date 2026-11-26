<?php
namespace phpbb2plus\community\mail;

use phpbb\request\request_interface;

class topic_form extends \phpbb\message\topic_form
{
	private $policy;
	private $board_action = '';
	private $nonce = '';

	public function set_policy($policy) { $this->policy=$policy; }
	public function for_board($action) { $this->board_action=$action; }
	public function __clone()
	{
		$this->message=clone $this->message;
	}

	protected function get_topic_row($id,$lock=false)
	{
		if (!$id || $this->board_action!=='') { return array(); }
		// Email leaves the board. A shadow, draft or deleted title must not be placed in an external envelope.
		$unlocked = "f.forum_password='' OR EXISTS (SELECT 1 FROM " . FORUMS_ACCESS_TABLE . ' fa WHERE fa.forum_id=f.forum_id
			AND fa.user_id=' . (int)$this->user->data['user_id'] . " AND fa.session_id='" . $this->db->sql_escape($this->user->session_id) . "')";
		$result=$this->db->sql_query('SELECT t.forum_id,t.topic_title,t.topic_poster,t.topic_visibility FROM ' . TOPICS_TABLE .
			' t JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id WHERE t.topic_id=' . (int)$id .
			' AND t.topic_moved_id=0 AND t.topic_status<>' . ITEM_MOVED . ' AND t.topic_visibility=' . ITEM_APPROVED .
			" AND t.plus_legacy_removed=0 AND ($unlocked)" . ($lock ? ' LOCK IN SHARE MODE' : ''));
		$row=$this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row && $this->content_visibility->is_visible('topic',$row['forum_id'],$row) ? $row : array();
	}

	public function bind(request_interface $request)
	{
		$this->user->add_lang_ext('phpbb2plus/community','friend');
		parent::bind($request);
		$this->nonce=$request->variable('plus_friend_form','',false,request_interface::POST);
		$this->recipient_address=$request->variable('email','',false,request_interface::POST);
		$this->recipient_name=$request->variable('name','',true,request_interface::POST);
		$this->body=$request->variable('message','',true,request_interface::POST);
		if ($this->board_action !== '')
		{
			$this->topic_id=0;
			if (!$request->is_set_post('message')) { $this->body=$this->user->lang('PLUS_FRIEND_NOTE'); }
		}
		$this->recipient_lang=$request->variable('lang',$this->user->lang_name,false,request_interface::POST);
	}

	public function check_allow()
	{
		if (empty($this->user->data['is_registered']) && empty($this->user->data['is_bot']) && $this->topic_id)
		{
			login_box(append_sid($this->phpbb_root_path . 'memberlist.' . $this->phpEx,'mode=email&amp;t=' . (int) $this->topic_id));
		}
		if (!$this->policy->member() || !$this->auth->acl_get('u_sendemail')) { return 'NO_EMAIL'; }
		if ((int)$this->config['plus_friend_protection']===2 || !$this->policy->board()) { return 'EMAIL_DISABLED'; }
		if ($this->board_action !== '') { return \phpbb\message\form::check_allow(); }
		if (!$this->topic_row || !$this->auth->acl_get('f_list',(int)$this->topic_row['forum_id'])) { return 'NO_TOPIC'; }
		return parent::check_allow();
	}

	private function key($nonce)
	{
		return 'plus_friend_' . (int)$this->topic_id . '_' . $this->user->session_id . '_' . $nonce;
	}

	public function render(\phpbb\template\template $template)
	{
		parent::render($template);
		if ($this->board_action !== '')
		{
			$template->assign_vars(array('L_EMAIL_TOPIC'=>$this->user->lang('PLUS_FRIEND_BOARD'),
				'L_EMAIL_BODY_EXPLAIN'=>$this->user->lang('PLUS_FRIEND_BOARD_EXPLAIN'),'S_POST_ACTION'=>$this->board_action));
		}
		$nonce=bin2hex(random_bytes(16));
		add_form_key($this->key($nonce));
		$template->assign_var('S_FORM_TOKEN',$template->retrieve_var('S_FORM_TOKEN') . build_hidden_fields(array('plus_friend_form'=>$nonce)));
	}

	public function submit(\messenger $messenger)
	{
		$nonce=$this->nonce;
		$span=(int)$this->config['form_token_lifetime'];
		if (!preg_match('/^[a-f0-9]{32}$/D',$nonce) || !check_form_key($this->key($nonce),$span<0 ? 86400 : min(86400,max(30,$span))))
		{ $this->errors[]=$this->user->lang('FORM_INVALID'); return; }
		if (strlen($this->recipient_address)>254 || !filter_var($this->recipient_address,FILTER_VALIDATE_EMAIL) ||
			!preg_match('/^' . get_preg_expression('email') . '$/iD',$this->recipient_address))
		{ $this->errors[]=$this->user->lang('EMPTY_ADDRESS_EMAIL'); return; }
		$name=htmlspecialchars_decode($this->recipient_name,ENT_QUOTES);
		if (utf8_strlen($name)>100 || preg_match('/[\x00-\x1f\x7f]/',$name) || utf8_strlen(htmlspecialchars_decode($this->body,ENT_QUOTES))>10000)
		{ $this->errors[]=$this->user->lang('PLUS_FRIEND_TEXT'); return; }
		if ($this->recipient_name==='') { $this->recipient_name=utf8_htmlspecialchars(strstr($this->recipient_address,'@',true)); }
		if (!in_array($this->recipient_lang,array_keys($this->languages()),true)) { $this->recipient_lang=$this->config['default_lang']; }
		$receipt=hash('sha256',$this->key($nonce));
		try
		{
			$claim=$this->policy->reserve($receipt,function () {
				if ($this->board_action==='') { $this->topic_row=$this->get_topic_row($this->topic_id,true); }
				return $this->check_allow();
			});
		}
		catch (\DomainException $e) { $this->errors[]=$this->user->lang($e->getMessage()); return; }

		$transport=new delivery($claim[2]);
		try
		{
			$actor=$claim[0];
			$this->message->set_sender($this->user->ip,$actor['username'],$actor['user_email'],$actor['user_lang'],
				(int)$actor['user_id'],$actor['username']);
			$this->message->set_sender_notify_type(NOTIFY_EMAIL);
			$this->message->set_template($this->board_action==='' ? 'email_notify' : '@phpbb2plus_community/recommend_board');
			$board=$this->policy->board();
			$this->message->set_template_vars(array('TOPIC_NAME'=>html_entity_decode($this->topic_row['topic_title'] ?? '',ENT_QUOTES,'UTF-8'),
				'U_TOPIC'=>$board . '/viewtopic.' . $this->phpEx . '?t=' . (int)$this->topic_id,'PLUS_BOARD_URL'=>$board . '/'));
			// Quote the note as plain text so native mail-template header markers remain ordinary message lines.
			$this->message->set_body($this->body === '' ? '' : '> ' . preg_replace('/\r\n|\r|\n/', "\n> ", $this->body));
			$this->message->add_recipient($this->recipient_name,$this->recipient_address,$this->recipient_lang,NOTIFY_EMAIL);
			if ($this->cc_sender) { $this->message->cc_sender(); }
			$this->message->send($transport,phpbb_get_board_contact($this->config,$this->phpEx));
		}
		catch (\Throwable $e) { /* The primary envelope may already have been accepted before a copy failed. */ }
		$this->policy->finish($receipt,$claim,$transport->accepted);
		if (!$transport->accepted) { $this->errors[]=$this->user->lang('PLUS_FRIEND_FAILURE'); return; }
		$return=$this->board_action==='' ? parent::get_return_message() : \phpbb\message\form::get_return_message();
		trigger_error($this->user->lang($transport->copy_failed ? 'PLUS_FRIEND_COPY_FAILED' : 'EMAIL_SENT') . '<br /><br />' . $return);
	}

	private function languages()
	{
		$result=$this->db->sql_query('SELECT lang_iso FROM ' . LANG_TABLE);
		$rows=$this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return array_flip(array_column($rows,'lang_iso'));
	}
}
