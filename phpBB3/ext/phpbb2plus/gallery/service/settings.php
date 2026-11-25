<?php
namespace phpbb2plus\gallery\service;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class settings
{
	const DEFAULTS = array('personal_gallery' => '0', 'personal_gallery_view' => '-1', 'personal_gallery_limit' => '10',
		'personal_allow_gallery_mod' => '1', 'personal_allow_sub_categories' => '1', 'personal_sub_category_limit' => '10',
		'show_personal_gallery_link' => '1');
	const MEDIA = array('gd_version' => '2', 'thumbnail_size' => '125', 'max_file_size' => '128000', 'max_width' => '800',
		'max_height' => '600', 'resize_pic' => '0', 'resize_width' => '600', 'resize_height' => '600', 'resize_quality' => '70');
	const BOUNDS = array('thumbnail_size' => array(1,10000), 'max_file_size' => array(1,67108864), 'max_width' => array(1,10000),
		'max_height' => array(1,10000), 'resize_width' => array(1,10000), 'resize_height' => array(1,10000), 'resize_quality' => array(0,100));
	const OPTIONS = array('personal_gallery' => array(0 => 'MEMBERS', 3 => 'SELECTED', 1 => 'ADMINS'),
		'personal_gallery_view' => array(-1 => 'GUESTS', 0 => 'MEMBERS', 3 => 'PRIVATE', 2 => 'MODS', 1 => 'ADMINS'),
		'personal_allow_gallery_mod' => array(1 => 'YES', 0 => 'NO'), 'personal_allow_sub_categories' => array(1 => 'YES', 0 => 'NO'),
		'show_personal_gallery_link' => array(1 => 'YES', 0 => 'NO'), 'resize_pic' => array(1 => 'YES', 0 => 'NO'),
		'gd_version' => array(2 => 'AUTO', 1 => 'GD1', 0 => 'MANUAL'));
	private $db;
	private $access;
	private $table;

	public function __construct($db, $access, $table)
	{
		$this->db = $db;
		$this->access = $access;
		$this->table = $table;
	}

	public static function defaults($mode) { return $mode === 'media' ? self::MEDIA : self::DEFAULTS; }

	public function snapshot($mode = 'settings')
	{
		$values = array();
		foreach (self::defaults($mode) as $name => $default) { $values[$name] = $this->access->setting($name, null); }
		return $values;
	}

	public static function revision($values) { return hash('sha256', serialize($values)); }

	public function save($values, $revision, $mode = 'settings')
	{
		return $this->access->write(function () use ($values, $revision, $mode) {
			if (!$this->access->administrator()) { throw new AccessDeniedHttpException(); }
			$old = $this->snapshot($mode);
			if (!hash_equals(self::revision($old), $revision)) { throw new \DomainException('PLUS_ALBUM_CONFIG_CHANGED'); }
			$changed = false;
			foreach (self::defaults($mode) as $name => $default)
			{
				$value = $values[$name] ?? '';
				// An unchanged imported value is retained even if an old mod used a different scale.
				if ($value === (string) ($old[$name] ?? $default)) { continue; }
				$bounds = self::BOUNDS[$name] ?? array(-1, 2147483647);
				$valid = isset(self::OPTIONS[$name]) ? isset(self::OPTIONS[$name][$value]) :
					(is_string($value) && preg_match('/^(?:-1|0|[1-9][0-9]{0,9})$/D', $value) && (float) $value >= $bounds[0] && (float) $value <= $bounds[1]);
				if (!$valid) { throw new \InvalidArgumentException($name); }
				$row = array('setting_value' => $value);
				if ($old[$name] === null)
				{
					$this->db->sql_query("INSERT INTO $this->table " . $this->db->sql_build_array('INSERT',
						$row + array('scope' => 'album', 'setting_name' => $name)));
				}
				else
				{
					$this->db->sql_query("UPDATE $this->table SET " . $this->db->sql_build_array('UPDATE', $row) .
						" WHERE scope='album' AND setting_name='" . $this->db->sql_escape($name) . "'");
				}
				$changed = true;
			}
			$this->access->refresh();
			return $changed;
		});
	}
}
