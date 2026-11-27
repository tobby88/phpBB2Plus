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
		if (($row['author_removed'] || (int) $row['author_id'] > ANONYMOUS) && $this->hide_former())
		{
			return $this->language->lang('PLUS_ALBUM_FORMER');
		}
		return $row['author_name'] !== '' ? utf8_htmlspecialchars($row['author_name']) : $this->language->lang('GUEST');
	}

	private function hide_former()
	{
		return $this->names ? $this->names->hidden() : empty($this->user->data['is_registered']);
	}

	/** The search joins only current member accounts. Stored guest names are plain text. */
	public function search_condition($native_like, $plain_match)
	{
		$visible = $this->hide_former() ? ' AND p.author_removed=0 AND p.author_id<=' . ANONYMOUS : '';
		return '((u.user_id IS NOT NULL AND LOWER(u.username) ' . $native_like . ') OR (u.user_id IS NULL' .
			$visible . ' AND ' . $plain_match . '))';
	}

	public function sort_expression($db)
	{
		$registered = 'p.author_id>' . ANONYMOUS;
		$sql = 'CASE WHEN ' . $registered . ' AND u.user_id IS NOT NULL THEN u.username';
		if ($this->hide_former())
		{
			$sql .= ' WHEN p.author_removed=1 OR ' . $registered . " THEN '" . $db->sql_escape($this->language->lang('PLUS_ALBUM_FORMER')) . "'";
		}
		return $sql . " WHEN p.author_name<>'' THEN p.author_name ELSE '" . $db->sql_escape($this->language->lang('GUEST')) . "' END";
	}
}
