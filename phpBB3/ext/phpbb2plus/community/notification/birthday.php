<?php
namespace phpbb2plus\community\notification;

class birthday extends \phpbb\notification\type\base
{
	const TYPE = 'phpbb2plus.community.notification.birthday';
	public static $notification_option = array('lang' => 'PLUS_BIRTHDAY_OPTION');
	private $config;

	public function set_config($config) { $this->config = $config; }
	public function get_type() { return self::TYPE; }
	public static function get_item_id($data) { return (int) $data['user_id']; }
	public static function get_item_parent_id($data) { return 0; }
	public function users_to_query() { return array(); }
	public function get_email_template() { return false; }
	public function get_email_template_variables() { return array(); }

	public function is_available()
	{
		$this->language->add_lang('birthday', 'phpbb2plus/community');
		return !empty($this->config['allow_birthdays']) && !empty($this->config['plus_birthday_greeting']) && !empty($this->user->data['is_registered']);
	}

	public function find_users_for_notification($data, $options = array())
	{
		$id = self::get_item_id($data);
		$methods = $this->check_user_notification_options(array($id), $options);
		$board = 'notification.method.board';
		return isset($methods[$id]) && in_array($board, $methods[$id], true) ? array($id => array($board)) : array();
	}

	public function get_title()
	{
		$this->language->add_lang('birthday', 'phpbb2plus/community');
		return $this->language->lang('PLUS_BIRTHDAY_TITLE');
	}

	public function get_url()
	{
		return append_sid($this->phpbb_root_path . 'birthday_popup.' . $this->php_ext);
	}
}
