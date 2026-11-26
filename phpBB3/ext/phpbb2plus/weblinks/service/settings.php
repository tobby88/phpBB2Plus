<?php
namespace phpbb2plus\weblinks\service;

class settings
{
	public const RANGES = array('width'=>array(1,1000),'height'=>array(1,1000),'linkspp'=>array(1,100),
		'display_interval'=>array(250,2147483647),'display_logo_num'=>array(1,1000));
	private $db;
	private $directory;
	private $cache;
	private $root;

	public function __construct($db, $directory, $cache, $root)
	{
		$this->db = $db;
		$this->directory = $directory;
		$this->cache = $cache;
		$this->root = $root;
	}

	public function read($lock = false)
	{
		$keys = array_keys(directory::DEFAULTS);
		$keys[] = 'local_logo';
		$keys[] = 'inputs_valid';
		$names = array_map(static function ($key) { return 'plus_wl_' . $key; },$keys);
		$result = $this->db->sql_query('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' WHERE ' .
			$this->db->sql_in_set('config_name',$names) . ' ORDER BY config_name' . ($lock ? ' FOR UPDATE' : ''));
		$values = array();
		while ($r = $this->db->sql_fetchrow($result)) { $values[substr($r['config_name'],8)] = $r['config_value']; }
		$this->db->sql_freeresult($result);
		if (count($values) !== count($keys)) { throw new \DomainException('WL_ADMIN_BAD_SETTINGS'); }
		return $values;
	}

	public static function version($values) { return hash('sha256',serialize($values)); }

	public static function board_logo($root, $relative)
	{
		// Existing migrated artwork stays in the protected content-addressed store. Newly selected local
		// artwork must belong to an explicitly public image directory, never a private upload directory.
		if (!preg_match('~^(images/(?:links/|ranks/|icons/(?:[a-z0-9_-]+/)*|smilies/)?|styles/[a-z0-9_-]+/theme/images/)([a-z0-9_.-]+\.(gif|png|jpe?g|webp))$~iD',$relative,$m))
		{ return false; }
		$directory = realpath($root . $m[1]);
		$board = realpath($root);
		if (!$directory || !$board || str_replace('\\','/',$directory) !== rtrim(str_replace('\\','/',$board),'/') . '/' . rtrim($m[1],'/'))
		{ return false; }
		$file = $directory . '/' . $m[2];
		if (!is_file($file) || is_link($file)) { return false; }
		$info = @getimagesize($file);
		$ext = strtolower($m[3]);
		$mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
		return $info && $info['mime'] === $mime ? array($file,$mime) : false;
	}

	public function save($input, $version)
	{
		$this->db->sql_transaction('begin');
		try
		{
			$this->directory->acp_actor('a_plus_wl_settings');
			$old = $this->read(true);
			if (!hash_equals(self::version($old),$version)) { throw new \DomainException('WL_CHANGED'); }
			$values = array('inputs_valid'=>'1');
			foreach (directory::DEFAULTS as $key=>$default)
			{
				if (!isset($input[$key])) { throw new \DomainException('WL_ADMIN_BAD_SETTINGS'); }
				$value = trim($input[$key]);
				if (isset(self::RANGES[$key]))
				{
					list($min,$max) = self::RANGES[$key];
					if (!preg_match('/^[0-9]{1,10}$/D',$value) || (int) $value<$min || (int) $value>$max) { throw new \DomainException('WL_ADMIN_BAD_SETTINGS'); }
					$value = (string) (int) $value;
				}
				elseif ($key === 'site_url' || $key === 'site_logo')
				{
					if ($value === directory::plain($old[$key])) { continue; }
					if (strlen($value)>255) { throw new \DomainException('WL_ADMIN_BAD_SETTINGS'); }
					$local = '';
					if ($value === '' && $key === 'site_logo') { $local = 'default'; }
					elseif ($value !== '' && directory::external($value) === '')
					{
						if ($key !== 'site_logo') { throw new \DomainException('WL_ADMIN_BAD_SETTINGS'); }
						if (strpos($value,'./') === 0) { $value = substr($value,2); }
						elseif (strpos($value,'/') === 0 && strpos($value,'//') !== 0) { $value = substr($value,1); }
						if (!self::board_logo($this->root,$value)) { throw new \DomainException('WL_ADMIN_BAD_IMAGE'); }
						$local = 'board';
					}
					if ($key === 'site_logo') { $values['local_logo'] = $local; }
					$value = directory::encode($value);
				}
				elseif (!in_array($value,array('0','1'),true)) { throw new \DomainException('WL_ADMIN_BAD_SETTINGS'); }
				$values[$key] = $value;
			}
			foreach (array_diff_assoc($values,$old) as $key=>$value)
			{
				$this->db->sql_query('UPDATE ' . CONFIG_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE',array('config_value'=>$value)) .
					" WHERE config_name='plus_wl_" . $key . "'");
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e) { $this->db->sql_transaction('rollback'); throw $e; }
		$this->cache->destroy('config');
	}
}
