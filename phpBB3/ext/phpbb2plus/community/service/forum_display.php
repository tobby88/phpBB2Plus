<?php
namespace phpbb2plus\community\service;

class forum_display
{
	public const DEFAULTS = array('sub_forum'=>1, 'split_cat'=>1, 'last_topic_title'=>1, 'sub_level_links'=>2,
		'split_global_announce'=>1, 'split_announce'=>1, 'split_news'=>1, 'split_sticky'=>1, 'split_topic_split'=>0);
	public const LINKS = array('NO', 'PLUS_DISPLAY_LINKS_TEXT', 'PLUS_DISPLAY_LINKS_ICONS');
	public const PACKING = array('PLUS_DISPLAY_EXPANDED', 'PLUS_DISPLAY_MEDIUM', 'PLUS_DISPLAY_COMPACT');
	private $config;
	private $user;

	public function __construct($config, $user) { $this->config = $config; $this->user = $user; }

	public static function choices($name)
	{
		if ($name === 'sub_forum') { return self::PACKING; }
		if ($name === 'sub_level_links') { return self::LINKS; }
		return array('NO', 'YES');
	}

	public static function valid($name, $raw)
	{
		return is_scalar($raw) && ctype_digit((string) $raw) && isset(self::choices($name)[(int) $raw]);
	}

	public function enabled() { return isset($this->config['plus_last_topic_title_length']); }

	public function personal($name)
	{
		return $this->enabled() && empty($this->config['plus_' . $name . '_over']) &&
			!empty($this->user->data['is_registered']) && isset($this->user->data['plus_' . $name]);
	}

	public function selected($name) { return (int) $this->user->data['plus_' . $name]; }

	public function get($name)
	{
		if ($this->personal($name) && self::valid($name, $this->selected($name))) { return $this->selected($name); }
		$value = $this->config['plus_' . $name] ?? null;
		return self::valid($name, $value) ? (int) $value : self::DEFAULTS[$name];
	}
}
