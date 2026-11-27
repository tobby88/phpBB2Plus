<?php
namespace phpbb2plus\community\service;

class account_references
{
	private $db;
	private $language_dir;
	private $php_ext;

	public function __construct($db, $language_dir, $php_ext)
	{
		$this->db = $db;
		$this->language_dir = $language_dir;
		$this->php_ext = $php_ext;
	}

	private function definition($section)
	{
		switch ($section)
		{
			case 'mailboxes':
				return array(PRIVMSGS_TO_TABLE, array('msg_id','user_id','folder_id','author_id'),
					'm.msg_id AS message_id, m.author_id AS sender, u.user_id AS owner, a.user_id AS author, f.user_id AS folder_owner',
					' LEFT JOIN ' . PRIVMSGS_TABLE . ' m ON m.msg_id=k.msg_id
					LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=k.user_id
					LEFT JOIN ' . USERS_TABLE . ' a ON a.user_id=k.author_id
					LEFT JOIN ' . PRIVMSGS_FOLDER_TABLE . ' f ON f.folder_id=k.folder_id');
			case 'messages':
				return array(PRIVMSGS_TABLE, array('msg_id'), 'm.author_id, a.user_id AS author',
					' JOIN ' . PRIVMSGS_TABLE . ' m ON m.msg_id=k.msg_id LEFT JOIN ' . USERS_TABLE . ' a ON a.user_id=m.author_id');
			case 'folders':
				return array(PRIVMSGS_FOLDER_TABLE, array('folder_id'), 'f.user_id, u.user_id AS owner',
					' JOIN ' . PRIVMSGS_FOLDER_TABLE . ' f ON f.folder_id=k.folder_id LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=f.user_id');
			case 'memberships':
				return array(USER_GROUP_TABLE, array('user_id','group_id'), 'u.user_id AS member, g.group_id AS member_group',
					' LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=k.user_id LEFT JOIN ' . GROUPS_TABLE . ' g ON g.group_id=k.group_id');
			case 'profiles':
				return array(USERS_TABLE, array('user_id'), 'u.user_rank, r.rank_special, u.user_style, s.style_active, u.user_lang',
					' JOIN ' . USERS_TABLE . ' u ON u.user_id=k.user_id
					LEFT JOIN ' . RANKS_TABLE . ' r ON r.rank_id=u.user_rank
					LEFT JOIN ' . STYLES_TABLE . ' s ON s.style_id=u.user_style');
			default:
				return array(USERS_TABLE, array('user_id'), 'u.group_id, g.group_id AS member_group,
					EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' ug WHERE ug.user_id=k.user_id AND ug.group_id=u.group_id AND ug.user_pending=0) AS approved',
					' JOIN ' . USERS_TABLE . ' u ON u.user_id=k.user_id LEFT JOIN ' . GROUPS_TABLE . ' g ON g.group_id=u.group_id');
		}
	}

	private function position($value, $keys)
	{
		if ($value === null || $value === '') { return null; }
		$parts = explode('.', (string) $value);
		if (count($parts) !== count($keys)) { return null; }
		foreach ($keys as $i=>$column)
		{
			$id = (int) $parts[$i];
			if ((string) $id !== $parts[$i] || ($id < 0 && $column !== 'folder_id') || $id < -2147483648 || $id > 4294967295) { return null; }
		}
		return array_map('intval', $parts);
	}

	/** Explicit leading equalities also enable range scans on older MariaDB compound indexes. */
	private function bound($keys, $position, $upper)
	{
		$parts = array(); $same = '';
		foreach ($keys as $i=>$column)
		{
			$value = $position[$i];
			$op = $upper ? ($i === count($keys)-1 ? '<=' : '<') : '>';
			$parts[] = '(' . $same . $column . $op . $value . ')';
			$same .= $column . '=' . $value . ' AND ';
		}
		return '(' . implode(' OR ', $parts) . ')';
	}

	/** The profile selector uses the installed packs; session setup independently requires common.php. */
	private function profile_languages()
	{
		$packs = array();
		$result = $this->db->sql_query('SELECT lang_iso FROM ' . LANG_TABLE);
		while ($row = $this->db->sql_fetchrow($result)) { $packs[$row['lang_iso']] = 'language_files'; }
		$this->db->sql_freeresult($result);
		// Read each immediate pack directory once, using only paths discovered on disk.
		foreach (new \FilesystemIterator($this->language_dir) as $entry)
		{
			if ($entry->isDir() && is_file($entry->getPathname() . '/common.' . $this->php_ext))
			{
				$code = $entry->getFilename();
				$packs[$code] = isset($packs[$code]) ? '' : 'language_unregistered';
			}
		}
		return $packs;
	}

	public function page($section, $after, $through)
	{
		list($table, $keys, $details, $joins) = $this->definition($section);
		$columns = implode(',', $keys);
		$lower = $this->position($after, $keys);
		$upper = $this->position($through, $keys);
		if ($upper === null)
		{
			$result = $this->db->sql_query_limit("SELECT $columns FROM $table ORDER BY " . implode(' DESC,', $keys) . ' DESC', 1);
			$last = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			$upper = $last ? array_values(array_map('intval', $last)) : null;
		}
		$output = array('checked'=>0, 'first'=>'', 'last'=>'', 'through'=>$upper ? implode('.', $upper) : '', 'next'=>'', 'findings'=>array());
		if ($upper === null) { return $output; }
		$languages = $section === 'profiles' ? $this->profile_languages() : array();
		$where = $this->bound($keys, $upper, true) . ($lower === null ? '' : ' AND ' . $this->bound($keys, $lower, false));
		// Native mailbox and membership tables have no unique row ID. Group identical references before paging;
		// a mass delivery can span many pages without losing recipients or materialising the whole mailing.
		$distinct = count($keys) > 1 ? 'DISTINCT ' : '';
		$window = "SELECT $distinct$columns FROM $table WHERE $where ORDER BY $columns LIMIT " . (post_references::PAGE_SIZE + 1);
		$result = $this->db->sql_query("SELECT k.*, $details FROM ($window) k" . $joins . ' ORDER BY k.' . implode(',k.', $keys));
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($output['checked'] === post_references::PAGE_SIZE) { $output['next'] = $output['last']; break; }
			$position = array();
			foreach ($keys as $key) { $position[] = (int) $row[$key]; }
			$id = implode('.', $position);
			if (!$output['checked']) { $output['first'] = $id; }
			$output['last'] = $id;
			$output['checked']++;
			$issues = $section === 'profiles' ? $this->profile_issues($row, $languages) : $this->issues($section, $row);
			if ($issues) { $output['findings'][$id] = $issues; }
		}
		$this->db->sql_freeresult($result);
		return $output;
	}

	private function profile_issues($row, $languages)
	{
		$issues = array();
		$rank = (int) $row['user_rank'];
		if ($rank && $row['rank_special'] === null) { $issues['rank_missing'] = array($rank); }
		elseif ($rank && !$row['rank_special']) { $issues['rank_automatic'] = array($rank); }

		if ((int) $row['user_id'] !== ANONYMOUS)
		{
			$style = (int) $row['user_style'];
			if ($style && $row['style_active'] === null) { $issues['style_missing'] = array($style); }
			elseif ($style && !$row['style_active']) { $issues['style_inactive'] = array($style); }

			$code = (string) $row['user_lang'];
			if ($code !== '')
			{
				$problem = $languages[$code] ?? 'language_missing';
				if ($problem !== '') { $issues[$problem] = array(); }
			}
		}
		return $issues;
	}

	private function issues($section, $row)
	{
		$issues = array();
		if ($section === 'mailboxes' || $section === 'messages')
		{
			// Native account deletion assigns the anonymous sender to surviving copies.
			if ((int) $row['author_id'] !== ANONYMOUS && $row['author'] === null) { $issues['pm_sender'] = array((int) $row['author_id']); }
		}
		if ($section === 'mailboxes' || $section === 'folders')
		{
			if ($row['owner'] === null || (int) $row['user_id'] === ANONYMOUS) { $issues['mail_owner'] = array((int) $row['user_id']); }
		}
		if ($section === 'mailboxes')
		{
			if ($row['message_id'] === null) { $issues['mail_message'] = array((int) $row['msg_id']); }
			elseif ((int) $row['author_id'] !== (int) $row['sender']) { $issues['mail_sender'] = array((int) $row['author_id'], (int) $row['sender']); }
			$folder = (int) $row['folder_id'];
			if ($folder > 0)
			{
				if ($row['folder_owner'] === null) { $issues['mail_folder'] = array($folder); }
				elseif ((int) $row['folder_owner'] !== (int) $row['user_id']) { $issues['foreign_folder'] = array($folder); }
			}
			elseif (!in_array($folder, array(PRIVMSGS_INBOX, PRIVMSGS_SENTBOX, PRIVMSGS_OUTBOX, PRIVMSGS_NO_BOX, PRIVMSGS_HOLD_BOX), true))
			{ $issues['mail_folder'] = array($folder); }
		}
		if ($section === 'memberships' || $section === 'defaults')
		{
			if ($row['member_group'] === null) { $issues['member_group'] = array((int) $row['group_id']); }
			if ($section === 'memberships' && $row['member'] === null) { $issues['member_user'] = array((int) $row['user_id']); }
			if ($section === 'defaults' && $row['member_group'] !== null && !$row['approved']) { $issues['default_member'] = array((int) $row['group_id']); }
		}
		return $issues;
	}
}
