<?php
namespace phpbb2plus\news\service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class articles
{
	private $db;
	private $config;
	private $user;
	private $auth;
	private $visibility;
	private $helper;
	private $paths;
	private $feed;
	private $dispatcher;
	private $table;
	private $root;
	private $ext;
	private $printer;

	public function __construct($db, $config, $user, $auth, $visibility, $helper, $paths, $feed, $dispatcher, $table, $root, $ext, $printer = null)
	{
		$this->db = $db; $this->config = $config; $this->user = $user; $this->auth = $auth;
		$this->visibility = $visibility; $this->helper = $helper; $this->paths = $paths; $this->feed = $feed;
		$this->dispatcher = $dispatcher; $this->table = $table; $this->root = $root; $this->ext = $ext;
		$this->printer = $printer;
	}

	public function forums()
	{
		return array_diff(array_intersect(array_keys($this->auth->acl_getf('f_list',true)),
			array_keys($this->auth->acl_getf('f_read',true))), $this->user->get_passworded_forums());
	}

	public function scope($forums, $category = 0, $year = 0, $month = 0, $day = 0, $keyword = '')
	{
		$sql = ' FROM ' . TOPICS_TABLE . ' t JOIN ' . $this->table . ' c ON c.category_id=t.plus_news_cat
			JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.poster_id WHERE c.active=1 AND t.topic_moved_id=0';
		if (!$forums) { return $sql . ' AND 1=0'; }
		// Publication always uses an approved topic and first post, including for a moderator.
		$sql .= ' AND t.topic_visibility=' . ITEM_APPROVED . ' AND p.post_visibility=' . ITEM_APPROVED . ' AND ' .
			$this->db->sql_in_set('t.forum_id',array_map('intval',$forums)) . ' AND ' .
			$this->visibility->get_forums_visibility_sql('topic',$forums,'t.') . ' AND ' .
			$this->visibility->get_forums_visibility_sql('post',$forums,'p.');
		if ($category) { $sql .= ' AND c.category_id=' . (int) $category; }
		if ($year || $month || $day)
		{
			if ($year < 1970 || $year > 9999 || ($day && !$month) || !checkdate($month ?: 1, $day ?: 1, $year)) { throw new NotFoundHttpException(); }
			$start = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month ?: 1, $day ?: 1), $this->user->create_datetime()->getTimezone());
			$next = $start->modify($day ? '+1 day' : ($month ? '+1 month' : '+1 year'));
			$sql .= ' AND t.topic_time>=' . (int) $start->format('U') . ' AND t.topic_time<' . (int) $next->format('U');
		}
		if ($keyword !== '')
		{
			$sql .= ' AND t.topic_title ' . $this->db->sql_like_expression($this->db->get_any_char() . $keyword . $this->db->get_any_char());
		}
		return $sql;
	}

	public function count($scope)
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $scope);
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		return $count;
	}

	private function category_data($row)
	{
		return array('CATEGORY'=>$row['name'], 'IMAGE'=>$row['image'] ? $this->paths->get_web_root_path() . 'images/plusnews/' . rawurlencode($row['image']) : '',
			'U_CATEGORY'=>$this->helper->route('phpbb2plus_news_category',array('category'=>(int) $row['category_id'])));
	}

	public function categories($scope)
	{
		$sql = 'SELECT c.category_id,c.name,c.image,COUNT(*) AS total' . $scope .
			' GROUP BY c.category_id,c.name,c.image ORDER BY c.name,c.category_id';
		$result = $this->db->sql_query($sql);
		try
		{
			while ($row = $this->db->sql_fetchrow($result)) { yield $this->category_data($row) + array('COUNT'=>(int) $row['total']); }
		}
		finally { $this->db->sql_freeresult($result); }
	}

	private function attachments($rows)
	{
		$files = $posts = array();
		if (!$this->auth->acl_get('u_download')) { return $files; }
		foreach ($rows as $row)
		{
			if ($row['post_attachment'] && $this->auth->acl_get('f_download',(int) $row['forum_id'])) { $posts[(int) $row['post_id']] = (int) $row['topic_id']; }
		}
		if (!$posts) { return $files; }
		$sql = 'SELECT * FROM ' . ATTACHMENTS_TABLE . ' WHERE in_message=0 AND is_orphan=0 AND ' .
			$this->db->sql_in_set('post_msg_id',array_keys($posts)) . ' ORDER BY attach_id DESC,post_msg_id ASC';
		$result = $this->db->sql_query($sql);
		while ($file = $this->db->sql_fetchrow($result))
		{
			$id = (int) $file['post_msg_id'];
			if ($posts[$id] === (int) $file['topic_id']) { $files[$id][] = $file; }
		}
		$this->db->sql_freeresult($result);
		return $files;
	}

	private function body($row, $files, $feed, $limit)
	{
		$options = ($row['enable_bbcode'] ? OPTION_FLAG_BBCODE : 0) | ($row['enable_smilies'] ? OPTION_FLAG_SMILIES : 0) |
			($row['enable_magic_url'] ? OPTION_FLAG_LINKS : 0);
		if ($feed)
		{
			// phpBB's attachment renderer calls append_sid() even inside the feed helper.
			global $_SID;
			$previous = $_SID;
			$_SID = '';
			try { $html = $this->feed->generate_content($row['post_text'],$row['bbcode_uid'],$row['bbcode_bitfield'],$options,(int) $row['forum_id'],$files); }
			finally { $_SID = $previous; }
		}
		else
		{
			$html = generate_text_for_display($row['post_text'],$row['bbcode_uid'],$row['bbcode_bitfield'],$options);
			if ($files)
			{
				$counts = array();
				parse_attachments((int) $row['forum_id'],$html,$files,$counts);
				if ($files) { $html .= '<div class="attachments">' . implode('<br>',$files) . '</div>'; }
			}
		}
		if ($limit > 0)
		{
			$plain = strip_tags(str_ireplace(array('<br>','<br/>','<br />','</p>','</div>'),' ',$html));
			$plain = trim(preg_replace('/\s+/u',' ',html_entity_decode($plain,ENT_QUOTES | ENT_HTML5,'UTF-8')));
			if (utf8_strlen($plain) > $limit) { return utf8_htmlspecialchars(utf8_substr($plain,0,$limit)) . '…'; }
		}
		return $html;
	}

	public function listing($scope, $limit, $start = 0, $feed = false)
	{
		$sql = 'SELECT c.category_id,c.name,c.image,t.topic_id,t.topic_title,t.topic_time,t.topic_posts_approved,t.topic_views,
			p.post_id,p.post_attachment,p.forum_id,p.poster_id AS user_id,p.post_username,p.post_text,p.bbcode_uid,p.bbcode_bitfield,
			p.enable_bbcode,p.enable_smilies,p.enable_magic_url,u.username,u.user_colour' . $scope . ' ORDER BY t.topic_time DESC,t.topic_id DESC';
		$result = $this->db->sql_query_limit($sql,$limit,$start);
		$rows = array();
		while ($row = $this->db->sql_fetchrow($result)) { $rows[(int) $row['post_id']] = $row; }
		$this->db->sql_freeresult($result);
		$event = $this->dispatcher->trigger_event('phpbb2plus.community.preview_bylines',array('rowset'=>$rows,'post_list'=>array_keys($rows)));
		$files = $this->attachments($rows);
		$trim = !$feed || $this->config['plus_news_feed_abstract'] ? max(0,(int) $this->config['plus_news_trim']) : 0;
		$title_limit = max(0,(int) $this->config['plus_news_title_trim']);
		foreach ($event['rowset'] as $post=>$row)
		{
			$id = (int) $row['topic_id'];
			$title = censor_text($row['topic_title']);
			if ($title_limit)
			{
				$plain = html_entity_decode($title,ENT_QUOTES,'UTF-8');
				if (utf8_strlen($plain) > $title_limit) { $title = utf8_htmlspecialchars(utf8_substr($plain,0,$title_limit)) . '…'; }
			}
			$print = '';
			if (!$feed && $this->auth->acl_get('f_print', (int) $row['forum_id']))
			{
				$print = $this->printer ? $this->printer->url($id) : append_sid($this->root . 'viewtopic.' . $this->ext, 't=' . $id . '&amp;view=print');
			}
			$email = !$feed && $this->config['email_enable'] && (int) ($this->config['plus_friend_protection'] ?? 0) !== 2
				&& !empty($this->user->data['is_registered']) && empty($this->user->data['user_perm_from']) && $this->auth->acl_get('u_sendemail')
				&& $this->auth->acl_get('f_email',(int) $row['forum_id'])
				? append_sid($this->root . 'memberlist.' . $this->ext,'mode=email&amp;t=' . $id) : '';
			yield $this->category_data($row) + array('ID'=>$id, 'TITLE'=>$title, 'U_EMAIL'=>$email, 'U_PRINT'=>$print, 'BODY'=>$this->body($row,$files[$post] ?? array(),$feed,$trim),
				'AUTHOR'=>get_username_string('full',(int) $row['user_id'],$row['username'],$row['user_colour'],$row['post_username']),
				'DATE'=>$this->user->format_date($row['topic_time']), 'TIME'=>(int) $row['topic_time'],
				'REPLIES'=>max(0,(int) $row['topic_posts_approved']-1), 'VIEWS'=>(int) $row['topic_views'],
				'U_TOPIC'=>$feed ? $this->feed->get_board_url() . '/viewtopic.' . $this->ext . '?t=' . $id :
					append_sid($this->root . 'viewtopic.' . $this->ext, 't=' . $id));
		}
	}
}
