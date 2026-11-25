<?php
namespace phpbb2plus\gallery\service;

class byline
{
	private $user;
	private $language;
	private $names;

	public function __construct($user, $language, $names = null)
	{
		$this->user = $user;
		$this->language = $language;
		$this->names = $names;
	}

	public function display($row)
	{
		if ((int) $row['author_id'] > ANONYMOUS && isset($row['username']))
		{
			return get_username_string('full', $row['author_id'], $row['username'], $row['user_colour']);
		}
		if (($row['author_removed'] || (int) $row['author_id'] > ANONYMOUS) &&
			($this->names ? $this->names->hidden() : empty($this->user->data['is_registered'])))
		{
			return $this->language->lang('PLUS_ALBUM_FORMER');
		}
		return $row['author_name'] !== '' ? utf8_htmlspecialchars($row['author_name']) : $this->language->lang('GUEST');
	}
}
