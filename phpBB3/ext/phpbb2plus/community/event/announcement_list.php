<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\announcements;
use phpbb2plus\community\service\forum_display;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class announcement_list implements EventSubscriberInterface
{
	private $settings, $activity, $schedule, $db, $visibility, $config, $request, $user, $language, $template, $dispatcher, $root, $ext;
	private $pagination, $attachments, $listed, $readable;

	public function __construct($settings, $activity, $schedule, $db, $visibility, $config, $request, $user, $language, $template, $dispatcher, $pagination, $attachments, $root, $ext)
	{
		$this->settings=$settings; $this->activity=$activity; $this->schedule=$schedule; $this->db=$db; $this->visibility=$visibility;
		$this->config=$config; $this->request=$request; $this->user=$user; $this->language=$language; $this->template=$template;
		$this->dispatcher=$dispatcher; $this->root=$root; $this->ext=$ext;
		$this->pagination=$pagination; $this->attachments=$attachments;
	}

	public static function getSubscribedEvents()
	{
		return array('core.index_modify_page_title'=>'index', 'core.viewforum_modify_page_title'=>'forum',
			'core.viewforum_get_announcement_topic_ids_data'=>array('native_list', -10), 'core.viewforum_modify_topicrow'=>'dates');
	}

	public function index() { $this->show(null); }
	public function forum($event) { $this->show($event['forum_data']); }

	private function load_forums()
	{
		if ($this->listed === null)
		{
			$this->listed = $this->activity->listed_forums();
			$this->readable = $this->activity->forums($this->listed);
		}
	}

	private function native_scope($id)
	{
		// The current forum keeps its native announcements and news. Other globals use exactly one list.
		$allowed = 't.forum_id=' . (int) $id;
		if ($this->settings->get('announce_forum')) { return $allowed; }
		$this->load_forums();
		return $allowed . ' OR ' . $this->db->sql_in_set('t.forum_id', $this->readable, false, true);
	}

	public function native_list($event)
	{
		if (!isset($this->config['plus_announce_index'])) { return; }
		$sql = $event['sql_ary'];
		$sql['WHERE'] = '(' . $sql['WHERE'] . ') AND (' . $this->native_scope($event['forum_id']) . ')';
		$event['sql_ary'] = $sql;
	}

	private function range($row)
	{
		if (!$this->settings->get('announce_dates') || !announcements::is_announcement($row['topic_type']) || empty($row['topic_time'])) { return ''; }
		$days = announcements::editor_days($row['topic_type'], intdiv((int) $row['topic_time_limit'], 86400), $row['plus_announce_days']);
		if ($days < 0) { return ''; }
		if (!$days) { $days = max(0, (int) $this->config['plus_announce_default']); }
		$start = $this->user->create_datetime('@' . (int) $row['topic_time']);
		$start->setTimezone(new \DateTimeZone($this->config['board_timezone'] ?: 'UTC'));
		$end = clone $start;
		$end->modify('+' . $days . ' days');
		$format = $this->language->lang('PLUS_ANNOUNCE_DATE_FORMAT');
		return $this->language->lang('PLUS_ANNOUNCE_FROM_TO', $start->format($format, true), $end->format($format, true));
	}

	public function dates($event)
	{
		if (!isset($this->config['plus_announce_index']) || !announcements::is_announcement($event['row']['topic_type'])) { return; }
		$this->language->add_lang('forum_display', 'phpbb2plus/community');
		$row = $event['topic_row'];
		$row['PLUS_ANNOUNCE_DATES'] = $this->range($event['row']);
		$event['topic_row'] = $row;
	}

	private function topic_rows($forum, $limit, $offset)
	{
		$where = $this->db->sql_in_set('t.forum_id', $this->readable) . ' AND f.forum_type=' . FORUM_POST .
			' AND t.topic_moved_id=0 AND t.topic_status<>' . ITEM_MOVED . ' AND t.topic_type IN (' . POST_ANNOUNCE . ',' . POST_GLOBAL . ')
			AND t.plus_legacy_removed=0 AND t.topic_visibility<>' . ITEM_DELETED . ' AND p.post_visibility<>' . ITEM_DELETED .
			' AND ' . $this->visibility->get_forums_visibility_sql('topic', $this->readable, 't.') .
			' AND ' . $this->visibility->get_forums_visibility_sql('post', $this->readable, 'p.');
		if ($forum)
		{
			$left=(int) $forum['left_id']; $right=(int) $forum['right_id'];
			$where .= ' AND t.forum_id<>' . (int) $forum['forum_id'] . ' AND (t.topic_type=' . POST_GLOBAL .
				" OR (f.left_id>$left AND f.right_id<$right) OR (f.left_id<$left AND f.right_id>$right))";
		}
		$tracking = $joins = '';
		if ($this->user->data['is_registered'])
		{
			$actor = (int) $this->user->data['user_id'];
			if ($this->config['load_db_lastread'])
			{
				$tracking = ',tt.mark_time,ft.mark_time AS forum_mark_time';
				$joins = ' LEFT JOIN ' . TOPICS_TRACK_TABLE . " tt ON tt.topic_id=t.topic_id AND tt.user_id=$actor
					LEFT JOIN " . FORUMS_TRACK_TABLE . " ft ON ft.forum_id=t.forum_id AND ft.user_id=$actor";
			}
			if ($this->config['load_db_track'])
			{
				$tracking .= ',tp.topic_posted';
				$joins .= ' LEFT JOIN ' . TOPICS_POSTED_TABLE . " tp ON tp.topic_id=t.topic_id AND tp.user_id=$actor";
			}
		}
		// Optional extensions select their own installed columns in the same visibility-scoped query.
		$columns = $this->dispatcher->trigger_event('phpbb2plus.community.announcement_columns', array('columns'=>array()))['columns'];
		$extra = $columns ? ',' . implode(',', $columns) : '';
		$r = $this->db->sql_query_limit('SELECT t.topic_id,t.forum_id,t.topic_title,t.topic_type,t.topic_time,t.topic_status,t.poll_start,t.topic_time_limit,
			t.topic_poster,t.topic_first_post_id,t.plus_description,t.icon_id,t.topic_attachment,f.enable_icons,
			t.plus_announce_days,t.topic_visibility,t.topic_posts_approved,t.topic_posts_unapproved,t.topic_posts_softdeleted,t.topic_views,
			p.post_id,p.post_time,p.post_username,p.poster_id AS user_id,u.username,u.user_colour,
			b.post_id AS first_id,b.poster_id AS first_user,b.post_username AS first_guest,bu.username AS first_name,bu.user_colour AS first_colour' . $tracking . $extra . '
			FROM ' . TOPICS_TABLE . ' t JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=t.forum_id
			JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_last_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id=p.poster_id
			LEFT JOIN ' . POSTS_TABLE . ' b ON b.post_id=t.topic_first_post_id AND b.topic_id=t.topic_id AND b.forum_id=t.forum_id
				AND b.post_visibility<>' . ITEM_DELETED . ' AND ' . $this->visibility->get_forums_visibility_sql('post', $this->readable, 'b.') . '
			LEFT JOIN ' . USERS_TABLE . ' bu ON bu.user_id=b.poster_id' . $joins . '
			WHERE ' . $where . ' ORDER BY t.topic_type DESC,t.topic_last_post_id DESC,t.topic_id DESC', $limit, $offset);
		$rows = $this->db->sql_fetchrowset($r);
		$this->db->sql_freeresult($r);
		return $rows;
	}

	private function paths($rows)
	{
		$ids = array_unique(array_column($rows, 'forum_id'));
		if (!$ids || !$this->settings->get('announce_path')) { return array(); }
		$result = $this->db->sql_query('SELECT f.forum_id AS origin,a.forum_id,a.forum_name FROM ' . FORUMS_TABLE . ' f
			JOIN ' . FORUMS_TABLE . ' a ON a.left_id<=f.left_id AND a.right_id>=f.right_id
			WHERE ' . $this->db->sql_in_set('f.forum_id', $ids) . ' AND ' . $this->db->sql_in_set('a.forum_id', $this->listed) . ' ORDER BY f.forum_id,a.left_id');
		$paths = array();
		while ($r=$this->db->sql_fetchrow($result))
		{
			$paths[$r['origin']][] = array('NAME'=>$r['forum_name'], 'URL'=>append_sid($this->root . 'viewforum.' . $this->ext, 'f=' . (int) $r['forum_id']));
		}
		$this->db->sql_freeresult($result);
		return $paths;
	}

	private function show($forum)
	{
		if (!isset($this->config['plus_announce_index'])) { return; }
		if ($this->schedule->should_sweep()) { $this->schedule->sweep(); }
		$key = !$forum || (int) $forum['forum_type'] === FORUM_CAT ? 'announce_index' : 'announce_forum';
		if (!$this->settings->get($key)) { return; }
		$this->load_forums();
		if (!$this->readable || ($forum && !in_array((int) $forum['forum_id'], $key === 'announce_index' ? $this->listed : $this->readable))) { return; }
		$size = max(1, min(100, (int) $this->config['topics_per_page']));
		$offset = max(0, $this->request->variable('announce_start', 0));
		$rows = $this->topic_rows($forum, $size+1, $offset);
		if (!$rows && !$offset) { return; }
		$more = count($rows)>$size;
		if ($more) { array_pop($rows); }
		$paths = $this->paths($rows);
		$attachments = $this->attachments->topic_markers($rows, $this->visibility);
		$this->language->add_lang(array('forum_display','community'), 'phpbb2plus/community');
		$this->language->add_lang('viewtopic');
		include_once $this->root . 'includes/functions_display.' . $this->ext;
		$tracked = $this->activity->tracking($rows, true);
		$url = build_url(array('announce_start'));
		$url .= strpos($url, '?') === false ? '?' : '&amp;';
		$this->template->assign_vars(array('S_PLUS_ANNOUNCEMENTS'=>true,
			'U_PLUS_ANNOUNCE_PREV'=>$offset ? $url . 'announce_start=' . max(0, $offset-$size) . '#plus-announcements' : '',
			'U_PLUS_ANNOUNCE_NEXT'=>$more ? $url . 'announce_start=' . ($offset+$size) . '#plus-announcements' : ''));
		$groups = $this->settings->topic_groups();
		$split = $this->settings->get('announce_groups') && $groups['global'] !== 'normal';
		$previous = null;
		// The same list editor resolves all eligible first posts together, using their own forum.
		$rows = $this->dispatcher->trigger_event('phpbb2plus.community.announcement_rows', array('rowset'=>$rows))['rowset'];
		foreach ($this->activity->with_authors($rows) as $r)
		{
			$id = (int) $r['topic_id']; $fid = (int) $r['forum_id']; $post = (int) $r['post_id'];
			$type = (int) $r['topic_type'] === POST_GLOBAL ? 'global' : 'announce';
			$group = $groups[$type];
			$replies = max(0, $this->visibility->get_count('topic_posts',$r,$fid)-1);
			$unread = isset($tracked[$id]) && (int) $r['post_time'] > $tracked[$id];
			topic_status($r, $replies, $unread, $icon, $alt, $native_type);
			$topic_url = append_sid($this->root . 'viewtopic.' . $this->ext, "f=$fid&amp;t=$id");
			$display = array('ID'=>$id,
				'HEADING'=>$split && $group!==$previous ? $this->language->lang(forum_display::GROUP_LABELS[$group]) : '',
				'TYPE'=>$split && $group===$type && !$r['poll_start'] ? '' : $native_type,
				'TITLE'=>censor_text($r['topic_title']), 'DATES'=>$this->range($r), 'HAS_ATTACHMENT'=>isset($attachments[$id]),
				'ICON'=>$icon, 'ALT'=>$this->language->lang($alt), 'UNREAD'=>$unread ? $topic_url . '&amp;view=unread#unread' : '',
				'PENDING'=>(int) $r['topic_visibility'] !== ITEM_APPROVED, 'FIRST_AUTHOR'=>$r['first_author'] ?? $this->language->lang('PLUS_REMOVED_POSTS', 1),
				'LAST_AUTHOR'=>$r['last_author'], 'LAST_TIME'=>$this->user->format_date((int) $r['post_time']),
				'REPLIES'=>$replies, 'VIEWS'=>(int) $r['topic_views'], 'PATH'=>$paths[$fid] ?? array(), 'U_VIEW_TOPIC'=>$topic_url,
				'LAST_URL'=>append_sid($this->root . 'viewtopic.' . $this->ext, "p=$post") . '#p' . $post);
			$display = $this->dispatcher->trigger_event('phpbb2plus.community.announcement_topic_row', array('row'=>$r, 'topic_row'=>$display))['topic_row'];
			$this->template->assign_block_vars('plus_announcement', $display);
			$this->pagination->generate_template_pagination($topic_url, 'plus_announcement.pagination', 'start',
				$replies+1, max(1, (int) $this->config['posts_per_page']), 1, true, true);
			$previous = $group;
		}
	}
}
