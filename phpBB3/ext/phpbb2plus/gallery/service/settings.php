<?php
namespace phpbb2plus\gallery\service;

class settings
{
	const DEFAULTS = array('personal_gallery' => '0', 'personal_gallery_view' => '-1', 'personal_gallery_limit' => '10',
		'personal_allow_gallery_mod' => '1', 'personal_allow_sub_categories' => '1', 'personal_sub_category_limit' => '10',
		'show_personal_gallery_link' => '1', 'show_all_in_personal_gallery' => '0');
	const MEDIA = array('max_pics' => '1024', 'user_pics_limit' => '50', 'mod_pics_limit' => '250', 'desc_length' => '512',
		'jpg_allowed' => '1', 'png_allowed' => '1', 'gif_allowed' => '0', 'max_file_size' => '128000', 'max_width' => '800',
		'max_height' => '600', 'resize_pic' => '0', 'resize_width' => '600', 'resize_height' => '600', 'resize_quality' => '70',
		'multiple_uploads' => '1', 'max_uploads' => '10', 'zip_uploads' => '1', 'gd_version' => '2',
		'thumbnail_size' => '125', 'thumbnail_quality' => '75', 'thumbnail_cache' => '1');
	const SPECIAL_MEDIA = array('midthumb_use' => '1', 'midthumb_width' => '800', 'midthumb_height' => '600', 'midthumb_cache' => '1',
		'use_watermark' => '0', 'wut_users' => '0', 'disp_watermark_at' => '3');
	const MEDIA_GROUPS = array('max_pics' => 'UPLOAD_LIMITS', 'gd_version' => 'THUMBNAILS', 'midthumb_use' => 'MEDIUM', 'use_watermark' => 'WATERMARK');
	const DISPLAY = array('show_recent_in_subcats' => '1', 'show_recent_instead_of_nopics' => '1',
		'personal_show_recent_in_subcats' => '1', 'personal_show_recent_instead_of_nopics' => '1',
		'album_category_sorting' => 'cat_order', 'album_category_sorting_direction' => 'ASC',
		'last_pic_title_length' => '25', 'new_pic_check_interval' => '1M');
	const DIRECTORY_SWITCHES = array('show_index_subcats' => '1', 'personal_show_subcats_in_index' => '1', 'line_break_subcats' => '0',
		'show_index_pics' => '0', 'show_index_total_pics' => '0', 'show_index_comments' => '0', 'show_index_total_comments' => '0',
		'show_index_last_pic' => '0', 'show_index_last_comment' => '0', 'show_index_thumb' => '0', 'fullpic_popup' => '0');
	const BOUNDS = array('thumbnail_size' => array(1,10000), 'max_file_size' => array(1,67108864), 'max_width' => array(1,10000),
		'max_height' => array(1,10000), 'resize_width' => array(1,10000), 'resize_height' => array(1,10000), 'resize_quality' => array(0,100),
		'max_uploads' => array(1,100), 'last_pic_title_length' => array(1,200), 'desc_length' => array(0,65535), 'thumbnail_quality' => array(0,100),
		'midthumb_width' => array(1,10000), 'midthumb_height' => array(1,10000));
	const MEDIA_SWITCHES = array('jpg_allowed','png_allowed','gif_allowed','thumbnail_cache','midthumb_use','midthumb_cache','use_watermark','wut_users');
	const OPTIONS = array('personal_gallery' => array(0 => 'MEMBERS', 3 => 'SELECTED', 1 => 'ADMINS'),
		'show_all_in_personal_gallery' => array(1 => 'YES', 0 => 'NO'),
		'personal_gallery_view' => array(-1 => 'GUESTS', 0 => 'MEMBERS', 3 => 'PRIVATE', 2 => 'MODS', 1 => 'ADMINS'),
		'personal_allow_gallery_mod' => array(1 => 'YES', 0 => 'NO'), 'personal_allow_sub_categories' => array(1 => 'YES', 0 => 'NO'),
		'show_personal_gallery_link' => array(1 => 'YES', 0 => 'NO'), 'resize_pic' => array(1 => 'YES', 0 => 'NO'),
		'gd_version' => array(2 => 'AUTO', 1 => 'GD1', 0 => 'MANUAL'), 'multiple_uploads' => array(1 => 'YES', 0 => 'NO'),
		'zip_uploads' => array(1 => 'YES', 0 => 'NO'), 'show_recent_in_subcats' => array(1 => 'YES', 0 => 'NO'),
		'show_recent_instead_of_nopics' => array(1 => 'YES', 0 => 'NO'), 'personal_show_recent_in_subcats' => array(1 => 'YES', 0 => 'NO'),
		'personal_show_recent_instead_of_nopics' => array(1 => 'YES', 0 => 'NO'),
		'album_category_sorting' => array('cat_order' => 'MANUAL_ORDER', 'cat_title' => 'ORDER_TITLE', 'cat_id' => 'ORDER_ID'),
		'album_category_sorting_direction' => array('ASC' => 'ASCENDING', 'DESC' => 'DESCENDING'),
		'disp_watermark_at' => array(1 => 'TOP_LEFT', 5 => 'TOP_CENTER', 2 => 'TOP_RIGHT', 8 => 'CENTER_LEFT', 0 => 'CENTER',
			6 => 'CENTER_RIGHT', 4 => 'BOTTOM_LEFT', 7 => 'BOTTOM_CENTER', 3 => 'BOTTOM_RIGHT'));
	private $db;
	private $access;
	private $admin;
	private $table;
	private $config;
	private $root;
	private $ini;

	public function __construct($db, $access, $admin, $table, $config, $root, $ini)
	{
		$this->db = $db;
		$this->access = $access;
		$this->admin = $admin;
		$this->table = $table;
		$this->config = $config;
		$this->root = $root;
		$this->ini = $ini;
	}

	public static function defaults($mode)
	{
		if ($mode === 'display') { return self::DISPLAY + self::DIRECTORY_SWITCHES; }
		return $mode === 'media' ? self::MEDIA + self::SPECIAL_MEDIA : self::DEFAULTS;
	}

	public static function options($name)
	{
		return isset(self::DIRECTORY_SWITCHES[$name]) || in_array($name, self::MEDIA_SWITCHES, true) ? array(1 => 'YES', 0 => 'NO') : (self::OPTIONS[$name] ?? array());
	}

	public function watermark_available($decode = false)
	{
		$file = raster::watermark_path(realpath($this->root . $this->config['upload_path']), $this->config['plus_gallery_media_key']);
		$size = raster::dimensions($file, 'image/png');
		if (!$size || !function_exists('imagecreatefrompng')) { return false; }
		if (!$decode) { return true; }
		// Verify the source once when enabling watermarking. A partially restored PNG can still have intact dimensions.
		$needed = 12.0 * $size[0] * $size[1] + 4194304;
		return (ini_get('memory_limit') === '-1' || $needed <= $this->ini->getBytes('memory_limit') - memory_get_usage(true))
			&& (bool) @imagecreatefrompng($file);
	}

	private static function scope($name) { return isset(self::SPECIAL_MEDIA[$name]) ? 'sp' : 'album'; }

	private static function valid($name, $value)
	{
		if (!is_string($value)) { return false; }
		$options = self::options($name);
		if ($options) { return isset($options[$value]); }
		if ($name === 'new_pic_check_interval') { return (bool) preg_match('/^(?:0|[1-9][0-9]{0,9}[HDWM]?)$/iD', $value); }
		$bounds = self::BOUNDS[$name] ?? array(-1, 2147483647);
		return preg_match('/^(?:-1|0|[1-9][0-9]{0,9})$/D', $value) && (float) $value >= $bounds[0] && (float) $value <= $bounds[1];
	}

	public function snapshot($mode = 'settings')
	{
		$values = array();
		foreach (self::defaults($mode) as $name => $default) { $values[$name] = $this->access->setting($name, null, self::scope($name)); }
		return $values;
	}

	public static function revision($values) { return hash('sha256', serialize($values)); }

	public function save($values, $revision, $mode = 'settings')
	{
		return $this->access->write(function () use ($values, $revision, $mode) {
			$this->admin->authorize('settings', true);
			$old = $this->snapshot($mode);
			if (!hash_equals(self::revision($old), $revision)) { throw new \DomainException('PLUS_ALBUM_CONFIG_CHANGED'); }
			$changed = false;
			foreach (self::defaults($mode) as $name => $default)
			{
				$value = $values[$name] ?? '';
				// An unchanged imported value is retained even if an old mod used a different scale.
				if ($value === (string) ($old[$name] ?? $default)) { continue; }
				if (!self::valid($name, $value)) { throw new \InvalidArgumentException($name); }
				if ($name === 'use_watermark' && $value === '1' && !$this->watermark_available(true)) { throw new \DomainException('PLUS_ALBUM_CFG_MARK_MISSING'); }
				$scope = self::scope($name);
				$row = array('setting_value' => $value);
				if ($old[$name] === null)
				{
					$this->db->sql_query("INSERT INTO $this->table " . $this->db->sql_build_array('INSERT',
						$row + array('scope' => $scope, 'setting_name' => $name)));
				}
				else
				{
					$this->db->sql_query("UPDATE $this->table SET " . $this->db->sql_build_array('UPDATE', $row) .
						" WHERE scope='$scope' AND setting_name='" . $this->db->sql_escape($name) . "'");
				}
				$changed = true;
			}
			$this->access->refresh();
			return $changed;
		});
	}
}
