<?php
namespace phpbb2plus\downloads\service;

class settings
{
	const SECTIONS = array(
		'library'=>array('settings_dbname','settings_disable','settings_viewall','settings_file_page','settings_topnumber','sort_method','sort_order','settings_newdays'),
		'files'=>array('max_file_size','forbidden_extensions','need_validation','validator','hotlink_prevent','hotlink_allowed'),
		'comments'=>array('allow_bbcode','allow_smilies','allow_comment_links','no_comment_link_message','allow_comment_images','no_comment_image_message','max_comment_chars'),
		'mail'=>array('massmail_protection','massmail_time'),
		'access'=>array('auth_search','auth_stats','auth_toplist','auth_viewall'));
	const DEFAULTS = array('settings_dbname'=>'','settings_disable'=>'0','settings_viewall'=>'1','settings_file_page'=>'20','settings_topnumber'=>'10','sort_method'=>'file_time','sort_order'=>'DESC',
		'settings_newdays'=>'0','max_file_size'=>'262144','forbidden_extensions'=>'','need_validation'=>'1','validator'=>'validator_admin',
		'hotlink_prevent'=>'0','hotlink_allowed'=>'','allow_bbcode'=>'1','allow_smilies'=>'1','allow_comment_links'=>'1','no_comment_link_message'=>'',
		'allow_comment_images'=>'1','no_comment_image_message'=>'','max_comment_chars'=>'5000','auth_search'=>'5','auth_stats'=>'5','auth_toplist'=>'5','auth_viewall'=>'5',
		'massmail_protection'=>'1','massmail_time'=>'20');
	const ARCHIVED = array('upload_dir','screenshots_dir','pm_notify','allow_html','tpl_php');
	const SORT = array('file_name'=>'title','file_time'=>'added_at','file_rating'=>'rating','file_dls'=>'downloads','file_update_time'=>'updated_at');
	const BOOL = array('settings_disable','settings_viewall','need_validation','hotlink_prevent','allow_bbcode','allow_smilies','allow_comment_links','allow_comment_images','massmail_protection');
	const TEXT = array('settings_dbname','no_comment_link_message','no_comment_image_message');
	const RANGES = array('settings_file_page'=>array(1,100),'settings_topnumber'=>array(0,1000),'settings_newdays'=>array(0,1000000),'max_file_size'=>array(0,2147483647),'max_comment_chars'=>array(0,65535),'massmail_time'=>array(1,180));
	private $db;
	private $access;
	private $user;
	private $log;
	private $table;

	public function __construct($db,$access,$user,$log,$prefix)
	{
		$this->db = $db;
		$this->access = $access;
		$this->user = $user;
		$this->log = $log;
		$this->table = $prefix . 'settings';
	}

	public static function options($name)
	{
		if (in_array($name,self::BOOL,true)) { return array('0'=>'NO','1'=>'YES'); }
		if (substr($name,0,5) === 'auth_')
		{
			$levels = array();
			foreach (access::LEVELS as $level) { $levels[(string) $level] = 'ACP_PLUS_DL_LEVEL_' . $level; }
			return $levels;
		}
		switch ($name)
		{
			case 'sort_method': return array('file_name'=>'PLUS_DL_TITLE','file_time'=>'ACP_PLUS_DL_SORT_ADDED','file_rating'=>'PLUS_DL_RATING',
				'file_dls'=>'PLUS_DL_COUNT','file_update_time'=>'ACP_PLUS_DL_SORT_UPDATED');
			case 'sort_order': return array('ASC'=>'ACP_PLUS_DL_ORDER_ASC','DESC'=>'ACP_PLUS_DL_ORDER_DESC');
			case 'validator': return array('validator_admin'=>'ACP_PLUS_DL_LEVEL_5','validator_mod'=>'ACP_PLUS_DL_VALIDATORS');
		}
		return array();
	}

	public function review()
	{
		if (!$this->access->settings_admin()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		$names = array_merge(array_keys(self::DEFAULTS),self::ARCHIVED);
		$res = $this->db->sql_query('SELECT config_name,config_value FROM ' . $this->table . ' WHERE ' . $this->db->sql_in_set('config_name',$names) . ' ORDER BY config_name');
		$raw = $archived = array();
		while ($row = $this->db->sql_fetchrow($res))
		{
			if (in_array($row['config_name'],self::ARCHIVED,true)) { $archived[$row['config_name']] = $row['config_value']; }
			else { $raw[$row['config_name']] = $row['config_value']; }
		}
		$this->db->sql_freeresult($res);
		$values = $raw + self::DEFAULTS;
		foreach (self::TEXT as $name) { $values[$name] = access::plain($values[$name]); }
		return array('state'=>hash('sha256',serialize($raw)),'values'=>$values,'raw'=>$raw,'archived'=>$archived);
	}

	private function validated($name,$value)
	{
		if (!is_string($value) || strlen($value)>1020 || !preg_match('//u',$value) || utf8_strlen($value)>255 ||
			preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) { throw new \DomainException('ACP_PLUS_DL_SETTING_INVALID'); }
		$options = self::options($name);
		if ($options && !array_key_exists($value,$options)) { throw new \DomainException('ACP_PLUS_DL_SETTING_INVALID'); }
		if (isset(self::RANGES[$name]))
		{
			list($min,$max) = self::RANGES[$name];
			if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$value) || $value<$min || $value>$max)
			{ throw new \DomainException('ACP_PLUS_DL_SETTING_RANGE'); }
		}
		if (in_array($name,self::TEXT,true)) { return utf8_encode_ucr(utf8_htmlspecialchars($value)); }
		if ($name === 'forbidden_extensions')
		{
			$names = array();
			foreach (preg_split('/[\s,;]+/',strtolower($value),-1,PREG_SPLIT_NO_EMPTY) as $ext)
			{
				if (!preg_match('/^\.?([a-z0-9]{1,16})$/D',$ext,$parts)) { throw new \DomainException('ACP_PLUS_DL_BAD_EXTENSIONS'); }
				$names[$parts[1]] = true;
			}
			return implode(', ',array_keys($names));
		}
		if ($name === 'hotlink_allowed')
		{
			foreach (preg_split('/[\s,;]+/',$value,-1,PREG_SPLIT_NO_EMPTY) as $entry)
			{
				$uri = parse_url(strpos($entry,'://') === false ? 'http://' . $entry : $entry);
				$host = rtrim($uri['host'] ?? '','.');
				if (!isset($uri['scheme']) || !in_array(strtolower($uri['scheme']),array('http','https'),true) ||
					isset($uri['user']) || isset($uri['pass']) || (!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) &&
					!filter_var(trim($host,'[]'),FILTER_VALIDATE_IP))) { throw new \DomainException('ACP_PLUS_DL_BAD_HOSTS'); }
			}
		}
		return $value;
	}

	public function save($state,$input)
	{
		if (!$this->access->settings_admin()) { throw new \DomainException('NO_AUTH_OPERATION'); }
		if (!is_array($input) || array_diff_key(self::DEFAULTS,$input) || array_diff_key($input,self::DEFAULTS)) { throw new \DomainException('FORM_INVALID'); }
		$this->db->sql_transaction('begin');
		try
		{
			$this->access->lock_catalogue(true);
			if (!$this->access->settings_admin(true)) { throw new \DomainException('NO_AUTH_OPERATION'); }
			$current = $this->review();
			if (!hash_equals($current['state'],$state)) { throw new \DomainException('ACP_PLUS_DL_SETTINGS_CHANGED'); }
			$updates = array();
			foreach (self::DEFAULTS as $name=>$default)
			{
				// Preserve original bytes, including historical exceptions and absent defaults.
				if ($input[$name] === $current['values'][$name]) { continue; }
				$value = $this->validated($name,$input[$name]);
				if ($value !== ($current['raw'][$name] ?? $default)) { $updates[$name] = $value; }
			}
			foreach ($updates as $name=>$value)
			{
				$data = array('config_value'=>$value);
				if (array_key_exists($name,$current['raw']))
				{ $this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE',$data) . " WHERE config_name='" . $this->db->sql_escape($name) . "'"); }
				else
				{ $this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT',$data + array('config_name'=>$name))); }
			}
			if ($updates)
			{ $this->log->add('admin',$this->user->data['user_id'],$this->user->ip,'LOG_PLUS_DL_SETTINGS',false,array(implode(', ',array_keys($updates)))); }
			if (!$this->db->sql_transaction('commit')) { throw new \RuntimeException('The download settings did not commit.'); }
		}
		catch (\Throwable $e) { if ($this->db->get_transaction()) { $this->db->sql_transaction('rollback'); } throw $e; }
		finally { $this->access->refresh(); }
	}
}
