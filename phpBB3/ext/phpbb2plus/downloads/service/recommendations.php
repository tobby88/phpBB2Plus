<?php
namespace phpbb2plus\downloads\service;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class recommendations implements EventSubscriberInterface
{
	const IN_FLIGHT = 0;
	const ACCEPTED = 1;
	const FAILED = 2;
	const LEASE = 600;

	private $access;
	private $db;
	private $config;
	private $user;
	private $auth;
	private $request;
	private $table;
	private $root;
	private $ext;

	public function __construct($access,$db,$config,$user,$auth,$request,$table,$root,$ext)
	{
		$this->access=$access;
		$this->db=$db;
		$this->config=$config;
		$this->user=$user;
		$this->auth=$auth;
		$this->request=$request;
		$this->table=$table;
		$this->root=$root;
		$this->ext=$ext;
	}

	public static function getSubscribedEvents() { return array('core.session_gc_after'=>'expire'); }

	public function expire()
	{
		$retention=max(86400,(int) $this->config['form_token_lifetime'],(int) $this->config['flood_interval']);
		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE attempted_at<' . (time()-$retention-self::LEASE));
	}

	public function can($file)
	{
		return $this->config['email_enable'] && empty($this->user->data['is_bot']) && $file['approved'] && $this->access->allows((int) $file['cat_id'],'email');
	}

	public function url($id)
	{
		// The public mail link uses the configured board host and never carries the sender's session.
		$scheme = $this->config['server_protocol'];
		if (!in_array($scheme,array('http://','https://'),true)) { $scheme=$this->config['cookie_secure'] ? 'https://' : 'http://'; }
		$port=(int) $this->config['server_port'];
		$authority=$this->config['server_name'] . ($port && $port!==($scheme==='https://' ? 443 : 80) ? ':' . $port : '');
		return access::external($scheme . $authority . '/' . ltrim(trim($this->config['script_path'],'/') . '/app.' . $this->ext . '/downloads/file/' . (int) $id,'/'));
	}

	private function row($sql)
	{
		$result=$this->db->sql_query_limit($sql,1);
		$row=$this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	private function address($value)
	{
		$value=trim($value);
		if (strlen($value)>254 || preg_match('/[\x00-\x20\x7f-\xff]/',$value) || !filter_var($value,FILTER_VALIDATE_EMAIL))
		{ throw new \DomainException('PLUS_DL_MAIL_ADDRESS'); }
		return $value;
	}

	private function sender_key($id,$registered,$ip)
	{
		return hash_hmac('sha256',$registered ? 'user:' . $id : 'guest:' . $ip,$this->config['plus_download_media_key']);
	}

	private function client_ip()
	{
		$ip=$this->request->server('REMOTE_ADDR','');
		$packed=filter_var($ip,FILTER_VALIDATE_IP) ? inet_pton($ip) : false;
		return $packed===false ? '' : inet_ntop($packed);
	}

	private function receipt($key,$file_id,$nonce) { return hash('sha256',$key . ':' . (int) $file_id . ':' . $nonce); }

	public function delivered($file_id,$nonce)
	{
		if (!preg_match('/^[a-f0-9]{32}$/D',$nonce)) { return false; }
		$key=$this->sender_key((int) $this->user->data['user_id'],!empty($this->user->data['is_registered']),$this->client_ip());
		$receipt=$this->receipt($key,$file_id,$nonce);
		return (bool) $this->row("SELECT receipt FROM $this->table WHERE receipt='$receipt' AND status=" . self::ACCEPTED);
	}

	private function text($value,$limit,$single_line,$required = true)
	{
		if (!is_string($value) || strpos($value,"\0")!==false || !preg_match('//u',$value)) { throw new \DomainException('PLUS_DL_MAIL_INPUT'); }
		if ($single_line) { $value=preg_replace('/[\x01-\x1f\x7f]+/',' ',$value); }
		$value=trim($value);
		if (($required && $value==='') || strlen($value)>4*$limit || utf8_strlen($value)>$limit) { throw new \DomainException('PLUS_DL_MAIL_INPUT'); }
		return $value;
	}

	private function actor($input)
	{
		$id=(int) $this->user->data['user_id'];
		// Serialize short reservations from this account (or the anonymous account); no mail I/O holds this lock.
		$this->db->sql_query('UPDATE ' . USERS_TABLE . " SET user_emailtime=user_emailtime WHERE user_id=$id");
		$sid=$this->db->sql_escape($this->user->session_id);
		$fresh=$this->row('SELECT u.* FROM ' . USERS_TABLE . ' u JOIN ' . SESSIONS_TABLE . " s ON s.session_user_id=u.user_id
			WHERE u.user_id=$id AND s.session_id='$sid' AND s.session_time>=" . (time()-(int) $this->config['session_length']));
		$logged=!empty($this->user->data['is_registered']);
		if (!$fresh || ($logged ? !in_array((int) $fresh['user_type'],array(USER_NORMAL,USER_FOUNDER),true) : $id!==ANONYMOUS))
		{ throw new \DomainException('FORM_INVALID'); }
		$fresh['is_registered']=$logged;
		$fresh['is_bot']=false;
		$this->auth->acl($fresh);
		$ip=$this->client_ip();
		if ($ip==='') { throw new \DomainException('PLUS_DL_MAIL_UNAVAILABLE'); }
		$name=$this->text($logged ? html_entity_decode($fresh['username'],ENT_QUOTES,'UTF-8') : $input['sname'],100,true);
		$address=$this->address($logged ? $fresh['user_email'] : $input['semail']);
		if (!$logged)
		{
			require_once $this->root . 'includes/functions_user.' . $this->ext;
			if (validate_username(utf8_htmlspecialchars($name),'')) { throw new \DomainException('PLUS_DL_MAIL_NAME'); }
		}
		if ($this->user->check_ban($id,$ip,$address,true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
		return array('id'=>$id,'name'=>$name,'address'=>$address,'emailtime'=>(int) $fresh['user_emailtime'],'logged'=>$logged,
			'key'=>$this->sender_key($id,$logged,$ip));
	}

	private function reserve($file_id,$nonce,$input)
	{
		$this->db->sql_transaction('begin');
		try
		{
			// Lock the file and then the account before the first snapshot read. Parallel
			// recommendations of different files must both observe the same sender's cooldown.
			$this->access->lock_file($file_id);
			$actor=$this->actor($input);
			$file=$this->access->file($file_id);
			$board=$this->row('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='email_enable'");
			if (!$this->can($file) || empty($board['config_value'])) { throw new NotFoundHttpException(); }
			$claim=$this->claim($file_id,$nonce,$actor);
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('Download mail reservation could not commit'); }
			return $claim;
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
	}

	private function claim($file_id,$nonce,$actor)
	{
		$receipt=$this->receipt($actor['key'],$file_id,$nonce);
		$where=" WHERE receipt='$receipt'";
		$existing=$this->row('SELECT status FROM ' . $this->table . $where);
		if ($existing && (int) $existing['status']===self::ACCEPTED) { return null; }
		if ($existing && (int) $existing['status']===self::IN_FLIGHT) { throw new \DomainException('PLUS_DL_MAIL_WAIT'); }
		$now=time();
		$flood=max(1,(int) $this->config['flood_interval']);
		$enabled=$this->access->setting('massmail_protection','1');
		$minutes=$this->access->setting('massmail_time','20');
		if (!in_array($enabled,array('0','1'),true) || !preg_match('/^[0-9]{1,6}$/D',$minutes)) { throw new \DomainException('PLUS_DL_MAIL_UNAVAILABLE'); }
		$cooldown=$enabled==='0' ? 0 : max(1,min(180,(int) $minutes))*60;
		$recent=$this->row("SELECT receipt FROM $this->table WHERE sender_key='" . $actor['key'] . "' AND (attempted_at>" . ($now-$flood) .
			' OR (status=' . self::IN_FLIGHT . ' AND attempted_at>' . ($now-self::LEASE) . ') OR (status=' . self::ACCEPTED . ' AND finished_at>' . ($now-$cooldown) . '))');
		if ($recent || ($actor['logged'] && $actor['emailtime']>$now-$flood)) { throw new \DomainException('PLUS_DL_MAIL_WAIT'); }
		$data=array('attempted_at'=>$now,'finished_at'=>0,'status'=>self::IN_FLIGHT);
		if ($existing) { $this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE',$data) . $where); }
		else { $this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT',$data+array('receipt'=>$receipt,'sender_key'=>$actor['key']))); }
		if ($actor['logged']) { $this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_emailtime=' . ($now+self::LEASE) . ' WHERE user_id=' . $actor['id']); }
		return array('receipt'=>$receipt,'at'=>$now,'actor'=>$actor);
	}

	private function finish($claim,$success)
	{
		$now=time();
		$actor=$claim['actor'];
		$this->db->sql_transaction('begin');
		try
		{
			if ($actor['logged'])
			{
				$this->db->sql_query('UPDATE ' . USERS_TABLE . " SET user_emailtime=$now WHERE user_id=" . $actor['id'] . ' AND user_emailtime=' . ($claim['at']+self::LEASE));
			}
			$this->db->sql_query('UPDATE ' . $this->table . ' SET finished_at=' . $now . ',status=' . ($success ? self::ACCEPTED : self::FAILED) .
				" WHERE receipt='" . $claim['receipt'] . "' AND attempted_at=" . $claim['at'] . ' AND status=' . self::IN_FLIGHT);
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
	}

	public function send($id,$nonce,$input)
	{
		if (!preg_match('/^[a-f0-9]{32}$/D',$nonce)) { throw new \DomainException('FORM_INVALID'); }
		$to=$this->address($input['femail']);
		$name=$this->text($input['fname'],100,true,false);
		$subject=$this->text($input['subject'],200,true);
		$message=$this->text($input['message'],10000,false);
		$url=$this->url($id);
		try { $from=$this->address($this->config['board_email']); }
		catch (\DomainException $e) { throw new \DomainException('PLUS_DL_MAIL_UNAVAILABLE'); }
		if (!$url) { throw new \DomainException('PLUS_DL_MAIL_UNAVAILABLE'); }
		$claim=$this->reserve($id,$nonce,$input);
		if (!$claim) { return; }
		$sent=false;
		try
		{
			require_once $this->root . 'includes/functions_messenger.' . $this->ext;
			$mail=new \messenger(false);
			$mail->template('@phpbb2plus_downloads/recommendation',$this->config['default_lang']);
			$mail->from($from);
			$mail->replyto($claim['actor']['address']);
			$mail->to($to,$name);
			$mail->subject($subject);
			$mail->headers('X-AntiAbuse: Board servername - ' . mail_encode($this->config['server_name']));
			$mail->headers('X-AntiAbuse: Username - ' . mail_encode($claim['actor']['name']));
			// Quote the personal note in the plain-text template, separating it from native mail headers.
			$mail->assign_vars(array('SUBJECT'=>$subject,'FROM_NAME'=>$claim['actor']['name'],'FILE_URL'=>$url,
				'PERSONAL_NOTE'=>'> ' . preg_replace('/\r\n|\r|\n/',"\n> ",$message)));
			$sent=(bool) $mail->send(NOTIFY_EMAIL);
		}
		catch (\Throwable $e) { $sent=false; }
		try { $this->finish($claim,$sent); }
		catch (\Throwable $e) { error_log('Download mail delivery receipt could not be finalized'); }
		if (!$sent) { throw new \DomainException('PLUS_DL_MAIL_UNAVAILABLE'); }
	}
}
