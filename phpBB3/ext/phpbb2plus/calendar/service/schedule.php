<?php
namespace phpbb2plus\calendar\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class schedule
{
	private $db;
	private $auth;
	private $visibility;
	private $user;
	private $config;
	private $settings;
	private $root;
	private $ext;

	public function __construct($db, $auth, $visibility, $user, $config, $settings, $root, $ext)
	{
		$this->db = $db; $this->auth = $auth; $this->visibility = $visibility; $this->user = $user;
		$this->config = $config; $this->settings = $settings; $this->root = $root; $this->ext = $ext;
	}

	public function scope($filter, $old_cat)
	{
		if ($old_cat) { $filter = 0; }
		$list = array_keys($this->auth->acl_getf('f_list', true));
		$read = array_diff(array_intersect($list, array_keys($this->auth->acl_getf('f_read',true))),$this->user->get_passworded_forums());
		$posts = $choices = array();
		if ($list)
		{
			$result = $this->db->sql_query('SELECT forum_id,forum_type,forum_name,left_id,right_id,plus_cal_src_cat FROM ' . FORUMS_TABLE .
				' WHERE ' . $this->db->sql_in_set('forum_id',$list) . ' ORDER BY left_id');
			$rows = $this->db->sql_fetchrowset($result);
			$this->db->sql_freeresult($result);
			$read = array_fill_keys($read,true);
			foreach ($rows as $f)
			{
				$id = (int) $f['forum_id'];
				if ((int) $f['forum_type'] === FORUM_POST && isset($read[$id])) { $posts[$id] = (int) $f['left_id']; }
			}
			foreach ($rows as $f)
			{
				$id = (int) $f['forum_id'];
				if (!isset($posts[$id]) && (int) $f['forum_type'] !== FORUM_CAT) { continue; }
				$choices[$id] = $f;
				if ($old_cat && (int) $f['plus_cal_src_cat'] === $old_cat) { $filter = $id; }
			}
		}
		if ($old_cat && !$filter || $filter && !isset($choices[$filter])) { throw new NotFoundHttpException(); }
		if ($filter)
		{
			$parent = $choices[$filter];
			$posts = array_filter($posts, static function ($left) use ($parent) {
				return $left >= (int) $parent['left_id'] && $left <= (int) $parent['right_id'];
			});
		}
		return array($filter, array_keys($posts), $choices);
	}

	public function topics($start, $end, $forums, $limit = 0)
	{
		if (!$forums) { return; }
		$sql = 'SELECT t.topic_id,t.topic_title,t.plus_cal_start,t.plus_cal_end,f.forum_name FROM ' . TOPICS_TABLE .
			' t JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id WHERE ' . $this->db->sql_in_set('t.forum_id',$forums) .
			' AND ' . $this->visibility->get_forums_visibility_sql('topic',$forums,'t.') . '
			AND t.topic_moved_id=0 AND t.plus_cal_start>0' . ($end === null ? '' : ' AND t.plus_cal_start<' . (int) $end) .
			' AND t.plus_cal_end>=' . (int) $start . ($limit ? ' AND t.topic_visibility<>' . ITEM_DELETED : '') .
			' ORDER BY t.plus_cal_start,' . ($limit ? '' : 't.plus_cal_end DESC,') . 't.topic_id';
		$result = $limit ? $this->db->sql_query_limit($sql, $limit) : $this->db->sql_query($sql);
		try
		{
			while ($r = $this->db->sql_fetchrow($result))
			{
				yield array('TOPIC_ID'=>(int) $r['topic_id'], 'TITLE'=>censor_text($r['topic_title']), 'FORUM'=>$this->settings->get('forum') ? $r['forum_name'] : '',
					'FROM'=>(int) $r['plus_cal_start'],'UNTIL'=>(int) $r['plus_cal_end'], 'BIRTHDAY'=>false,
					'URL'=>append_sid($this->root . 'viewtopic.' . $this->ext, 't=' . (int) $r['topic_id']));
			}
		}
		finally { $this->db->sql_freeresult($result); }
	}

	public function posting_forums($readable)
	{
		$forums = array_intersect($readable, array_keys($this->auth->acl_getf('f_post', true)), array_keys($this->auth->acl_getf('f_plus_calendar', true)));
		if (!$forums || !empty($this->user->data['is_bot'])) { return array(); }
		$result = $this->db->sql_query('SELECT forum_id,forum_name,forum_status FROM ' . FORUMS_TABLE . ' WHERE forum_type=' . FORUM_POST .
			' AND ' . $this->db->sql_in_set('forum_id',$forums) . ' ORDER BY left_id');
		$choices = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ((int) $row['forum_status'] !== ITEM_LOCKED || $this->auth->acl_get('m_edit', (int) $row['forum_id'])) { $choices[] = $row; }
		}
		$this->db->sql_freeresult($result);
		return $choices;
	}

	public function birthdays($first, $until)
	{
		if (!$this->settings->get('birthday') || !$this->config['allow_birthdays'] || empty($this->user->data['is_registered']) || !$this->auth->acl_get('u_viewprofile')) { return; }
		$last = $until->modify('-1 second');
		$months = array();
		for ($m = $first->modify('first day of this month'); $m->format('Y-m') <= $last->format('Y-m'); $m = $m->modify('first day of next month'))
		{
			$n = (int) $m->format('n');
			$months[sprintf('%2d',$n)] = true;
			if ($n === 3) { $months[' 2'] = true; } // Historical February 29 birthdays fall on March 1 in ordinary years.
		}
		$sql = 'SELECT user_id,username,user_birthday FROM ' . USERS_TABLE . ' WHERE ' . $this->db->sql_in_set('user_type',array(USER_NORMAL,USER_FOUNDER)) .
			' AND ' . $this->db->sql_in_set('SUBSTRING(user_birthday,4,2)',array_map('strval',array_keys($months))) . ' ORDER BY username_clean,user_id';
		$result = $this->db->sql_query($sql);
		try
		{
			while ($r = $this->db->sql_fetchrow($result))
			{
				$parts = array_map('intval',explode('-',$r['user_birthday']));
				if (count($parts) !== 3 || !checkdate($parts[1],$parts[0],$parts[2] ?: 2000)) { continue; }
				for ($year = max((int) $first->format('Y'),$parts[2]); $year <= (int) $last->format('Y'); ++$year)
				{
					$date = $first->setDate($year,$parts[1],$parts[0])->setTime(0,0);
					$end = $date->modify('+1 day');
					if ($date >= $until || $end <= $first) { continue; }
					yield array('TITLE'=>$r['username'], 'AGE'=>$parts[2] ? $year - $parts[2] : null,
						'BIRTHDAY'=>true,'FROM'=>$date->getTimestamp(),'UNTIL'=>$end->getTimestamp()-1, 'FORUM'=>'',
						'URL'=>append_sid($this->root . 'memberlist.' . $this->ext, 'mode=viewprofile&amp;u=' . (int) $r['user_id']));
				}
			}
		}
		finally { $this->db->sql_freeresult($result); }
	}

	/** Only fetch first posts whose entries are actually visible in the compact grid. */
	public function previews($topics, $forums)
	{
		$length = $this->settings->get('text_length');
		$previews = array();
		if (!$length || !$topics || !$forums) { return $previews; }
		foreach (array_chunk($topics,200) as $chunk)
		{
			$sql = 'SELECT t.topic_id,p.post_text,p.bbcode_uid,p.bbcode_bitfield,p.enable_bbcode,p.enable_smilies,p.enable_magic_url
				FROM ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id
				WHERE ' . $this->db->sql_in_set('t.topic_id',$chunk) . ' AND ' . $this->db->sql_in_set('t.forum_id',$forums) . ' AND ' .
				$this->visibility->get_forums_visibility_sql('topic',$forums,'t.') . ' AND ' . $this->visibility->get_forums_visibility_sql('post',$forums,'p.');
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$flags = ($row['enable_bbcode'] ? OPTION_FLAG_BBCODE : 0) | ($row['enable_smilies'] ? OPTION_FLAG_SMILIES : 0) |
					($row['enable_magic_url'] ? OPTION_FLAG_LINKS : 0);
				$html = generate_text_for_display($row['post_text'],$row['bbcode_uid'],$row['bbcode_bitfield'],$flags);
				$plain = strip_tags(str_ireplace(array('<br>','<br/>','<br />','</p>','</div>'),' ',$html));
				$previews[(int) $row['topic_id']] = $this->excerpt($plain,$length);
			}
			$this->db->sql_freeresult($result);
		}
		return $previews;
	}

	public function excerpt($text, $length)
	{
		$plain = trim(preg_replace('/\s+/u',' ',html_entity_decode($text,ENT_QUOTES | ENT_HTML5,'UTF-8')));
		if (utf8_strlen($plain) > $length) { $plain = utf8_substr($plain,0,$length) . '…'; }
		return htmlspecialchars($plain,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
	}
}
