<?php
namespace phpbb2plus\downloads\service;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class search_results implements EventSubscriberInterface
{
	private $db;
	private $config;
	private $user;
	private $table;

	public function __construct($db,$config,$user,$table)
	{
		$this->db=$db;
		$this->config=$config;
		$this->user=$user;
		$this->table=$table;
	}

	public static function getSubscribedEvents() { return array('core.session_gc_after'=>'expire'); }

	private function owner()
	{
		$sid = $this->user->session_id;
		return is_string($sid) && preg_match('/^[0-9a-f]{32}$/D',$sid) ?
			hash_hmac('sha256',$sid . ':' . (int) $this->user->data['user_id'],$this->config['plus_download_media_key']) : '';
	}

	private function cutoff() { return time()-max(1,(int) $this->config['session_length']); }

	public function expire()
	{
		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE created_at<' . $this->cutoff());
	}

	public function remember($criteria,$ids)
	{
		$owner = $this->owner();
		if (!$owner || !$ids) { return 0; }
		$this->expire();
		$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT',array('session_key'=>$owner,
			'created_at'=>time(),'payload'=>json_encode(array('criteria'=>$criteria,'ids'=>array_values($ids))))));
		return (int) $this->db->sql_nextid();
	}

	public function recall($id)
	{
		$owner = $this->owner();
		if (!$owner || $id<1) { return false; }
		$result = $this->db->sql_query('SELECT payload FROM ' . $this->table . ' WHERE search_id=' . (int) $id .
			" AND session_key='" . $this->db->sql_escape($owner) . "' AND created_at>=" . $this->cutoff());
		$raw = $this->db->sql_fetchfield('payload');
		$this->db->sql_freeresult($result);
		$data = $raw ? json_decode($raw,true) : null;
		return isset($data['criteria'],$data['ids']) && is_array($data['criteria']) && is_array($data['ids']) ? $data : false;
	}
}
