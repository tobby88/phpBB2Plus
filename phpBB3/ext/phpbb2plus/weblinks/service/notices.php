<?php
namespace phpbb2plus\weblinks\service;

class notices
{
	private $db;
	private $config;
	private $auth;
	private $language;
	private $user;
	private $request;
	private $prefix;
	private $root;
	private $ext;

	public function __construct($db, $config, $auth, $language, $user, $request, $prefix, $root, $ext)
	{
		$this->db = $db; $this->config = $config; $this->auth = $auth; $this->language = $language;
		$this->user = $user; $this->request = $request; $this->prefix = $prefix; $this->root = $root; $this->ext = $ext;
	}

	public function pending($id)
	{
		$mail = $this->config['plus_wl_email_notify'] && $this->config['email_enable'];
		$pm = $this->config['plus_wl_pm_notify'] && $this->config['allow_privmsg'] && $this->auth->acl_get('u_sendpm');
		if (!$mail && !$pm) { return true; }
		try
		{
			$grants = $this->auth->acl_get_list(false,'a_plus_wl_review',0);
			$recipients = array_map('intval',$grants[0]['a_plus_wl_review'] ?? array());
			$audience = 'u.user_type=' . USER_FOUNDER;
			if ($recipients) { $audience .= ' OR (u.user_type=' . USER_NORMAL . ' AND ' . $this->db->sql_in_set('u.user_id',$recipients) . ')'; }
			$result = $this->db->sql_query('SELECT u.user_id,u.username,u.user_email,u.user_lang,u.user_allow_pm,l.title FROM ' .
				USERS_TABLE . ' u JOIN ' . $this->prefix . 'entries l ON l.link_id=' . (int) $id . ' AND l.status=0 AND l.user_id=' .
				(int) $this->user->data['user_id'] . " WHERE ($audience)");
			$users = $this->db->sql_fetchrowset($result);
			$this->db->sql_freeresult($result);
			$ok = true;
			foreach ($users as $r)
			{
				if ($pm && $r['user_allow_pm']) { try { $this->message($r); } catch (\Throwable $e) { $ok = false; } }
				if ($mail)
				{
					try { $ok = $this->email($r) && $ok; } catch (\Throwable $e) { $ok = false; }
				}
			}
			return $ok;
		}
		catch (\Throwable $e) { return false; }
	}

	private function email($r)
	{
		if (!filter_var($r['user_email'],FILTER_VALIDATE_EMAIL)) { return false; }
		include_once $this->root . 'includes/functions_messenger.' . $this->ext;
		$mail = new \messenger();
		$mail->template('@phpbb2plus_weblinks/weblink_pending',$r['user_lang']);
		$mail->to($r['user_email']);
		$mail->assign_vars(array('USERNAME'=>htmlspecialchars_decode($r['username'],ENT_QUOTES),
			'WEBSITE_TITLE'=>directory::plain($r['title'])));
		$sent = $mail->send(NOTIFY_EMAIL);
		if ($sent) { $mail->save_queue(); }
		return $sent;
	}

	private function message($r)
	{
		include_once $this->root . 'includes/functions_privmsgs.' . $this->ext;
		$id = (int) $r['user_id'];
		$limit = phpbb_get_max_setting_from_group($this->db,$id,'message_limit') ?: $this->config['pm_max_msgs'];
		if ($limit)
		{
			$result = $this->db->sql_query('SELECT COUNT(*) AS n FROM ' . PRIVMSGS_TO_TABLE . ' WHERE user_id=' . $id .
				' AND ' . $this->db->sql_in_set('folder_id',array(PRIVMSGS_INBOX,PRIVMSGS_NO_BOX,PRIVMSGS_HOLD_BOX)));
			$used = (int) $this->db->sql_fetchfield('n');
			$this->db->sql_freeresult($result);
			if ($used >= $limit) { return; }
		}
		$locale = clone $this->language;
		$locale->set_user_language($r['user_lang'],true);
		$locale->add_lang('directory','phpbb2plus/weblinks');
		$subject = utf8_htmlspecialchars($locale->lang('WL_NOTICE_SUBJECT'));
		$text = utf8_htmlspecialchars($locale->lang('WL_NOTICE_TEXT',directory::plain($r['title'])));
		$uid = $bits = ''; $flags = 0;
		generate_text_for_storage($text,$uid,$bits,$flags,false,false,false);
		$data = array('from_user_id'=>(int) $this->user->data['user_id'], 'from_user_ip'=>$this->user->ip, 'from_username'=>$this->user->data['username'],
			'reply_from_root_level'=>0,'reply_from_msg_id'=>0,'icon_id'=>0,'enable_sig'=>false,'enable_bbcode'=>false,
			'enable_smilies'=>false,'enable_urls'=>false,'bbcode_uid'=>$uid,'bbcode_bitfield'=>$bits,'message'=>$text,
			'attachment_data'=>array(),'filename_data'=>array(),'address_list'=>array('u'=>array($id=>'to')));
		$draft = $this->request->raw_variable('draft_loaded',null);
		$this->request->overwrite('draft_loaded',null);
		try { submit_pm('post',$subject,$data,false); }
		finally { $this->request->overwrite('draft_loaded',$draft); }
	}
}
