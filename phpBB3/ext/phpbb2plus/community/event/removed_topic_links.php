<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\removed_topics;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class removed_topic_links implements EventSubscriberInterface
{
	private $auth;
	private $request;
	private $helper;
	private $visibility;
	private $language;
	private $announcements = 0;
	private $search = false;
	private $native_search = false;

	public function __construct($auth, $request, $helper, $visibility, $language)
	{
		$this->auth = $auth;
		$this->request = $request;
		$this->helper = $helper;
		$this->visibility = $visibility;
		$this->language = $language;
	}
	public static function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_forum_id' => 'topic', 'core.viewforum_modify_sql' => 'count_for_pagination',
			'core.viewforum_modify_sort_data_sql' => 'filtered_count', 'core.viewforum_get_topic_ids_data' => 'topic_ids',
			'core.viewforum_get_announcement_topic_ids_data' => 'before_announcements',
			'core.phpbb_content_visibility_is_visible' => 'announcement', 'core.viewforum_modify_topicrow' => 'caption',
			'core.viewtopic_post_rowset_data' => 'source_flag', 'core.viewtopic_modify_post_row' => array('erased_post', -100),
			'core.search_modify_submit_parameters' => 'search_start', 'core.prune_sql' => 'retain_erased_topics',
			'core.phpbb_content_visibility_get_global_visibility_before' => array('search_scope', -100));
	}
	private function readable($forum)
	{
		return $forum && $this->auth->acl_get('f_list', $forum) && $this->auth->acl_get('f_read', $forum);
	}

	public function retain_erased_topics($event)
	{
		// These records preserve historical links. Plus age pruning skipped
		// topics with no remaining posts, even when permanent removal was enabled.
		$event['sql_and'] .= ' AND plus_legacy_removed=0';
	}

	public function topic($event)
	{
		$row = $event['topic_data'];
		if (!removed_topics::marker($row) || !$this->readable((int) $event['forum_id'])) { return; }
		$post = $this->request->variable('p', 0);
		$print = $this->request->variable('view', '') === 'print';
		$route = $post ? ($print ? 'phpbb2plus_community_post_print' : 'phpbb2plus_community_removed_post')
			: ($print ? 'phpbb2plus_community_topic_print' : 'phpbb2plus_community_removed_topic');
		// Until the separate forum-password challenge succeeds a post bookmark
		// must not reveal its parent topic ID in a redirect.
		$params = $post ? array('post_id' => $post) : array('topic_id' => (int) $row['topic_id']);
		if (!$post && !$print && ($start = $this->request->variable('start', 0)) > 0) { $params['start'] = $start; }
		if ($this->request->variable('sd', '') === 'd')
		{
			if ($print) { $params['postorder'] = 'desc'; } else { $params['sd'] = 'd'; }
		}
		redirect($this->helper->route($route, $params, false));
	}

	public function count_for_pagination($event)
	{
		$forum = $this->request->variable('f', 0);
		if (!$this->readable($forum) || $this->auth->acl_get('m_approve', $forum)) { return; }
		// Only this forum page counts the visible marker rows when slicing its list.
		// Board statistics keep the native totals of approved topics and posts.
		$sql = $event['sql_ary'];
		$sql['SELECT'] .= ', f.forum_topics_approved + (SELECT COUNT(*) FROM ' . TOPICS_TABLE .
			' r WHERE r.forum_id=f.forum_id AND ' . removed_topics::condition('r.') . ') AS forum_topics_approved';
		$event['sql_ary'] = $sql;
	}

	private function with_markers($query, $forum)
	{
		if (!$this->readable($forum) || $this->auth->acl_get('m_approve', $forum)) { return $query; }
		$native = $this->visibility->get_visibility_sql('topic', $forum, 't.');
		// Change only the native visibility operand in the current forum's topic
		// list. Keep its forum, age, type and any other extension conditions.
		$query['WHERE'] = str_replace($native, '(' . $native . " OR (t.forum_id=$forum AND " . removed_topics::condition('t.') . '))', $query['WHERE']);
		return $query;
	}
	public function filtered_count($event)
	{
		$event['sql_array'] = $this->with_markers($event['sql_array'], (int) $event['forum_id']);
	}
	public function topic_ids($event)
	{
		$this->announcements = 0;
		$event['sql_ary'] = $this->with_markers($event['sql_ary'], (int) $event['forum_data']['forum_id']);
	}
	public function before_announcements($event) { $this->announcements = (int) $event['forum_id']; }
	public function announcement($event)
	{
		if ($this->announcements && $event['mode'] === 'topic' && removed_topics::marker($event['data']))
		{
			$event['is_visible'] = (int) $event['forum_id'] === $this->announcements && $this->readable($this->announcements);
		}
	}
	public function caption($event)
	{
		if (!removed_topics::marker($event['row'])) { return; }
		$this->language->add_lang('community', 'phpbb2plus/community');
		$row = $event['topic_row'];
		$row['TOPIC_TITLE'] = $this->language->lang('PLUS_REMOVED_TOPIC');
		$row['LAST_POST_SUBJECT'] = '';
		$row['S_HAS_POLL'] = $row['S_TOPIC_DELETED'] = false;
		$row['U_MCP_QUEUE'] = '';
		$row['REPLIES'] = max(0, (int) $event['row']['topic_posts_softdeleted'] - 1);
		$event['topic_row'] = $row;
	}

	public function search_start() { $this->search = true; }
	public function source_flag($event)
	{
		if (!empty($event['row']['plus_legacy_erased']))
		{
			$row = $event['rowset_data'];
			$row['plus_legacy_erased'] = true;
			$event['rowset_data'] = $row;
		}
	}
	public function erased_post($event)
	{
		$row = $event['row'];
		if (empty($row['plus_legacy_erased']) || (int) $row['post_visibility'] !== ITEM_DELETED) { return; }
		$this->language->add_lang('community', 'phpbb2plus/community');
		// A retained Plus placeholder has no recoverable text or editor.
		$event['post_row'] = array('POST_ID' => (int) $row['post_id'],
			'MESSAGE' => '<span class="plus-erased-caption">' . htmlspecialchars($this->language->lang('PLUS_REMOVED_POSTS', 1), ENT_QUOTES, 'UTF-8') . '</span>');
	}
	public function search_scope($event)
	{
		// Ordinary searches already select only approved content. A moderator's
		// search includes native soft deletions, including predefined/unread lists.
		if (!$this->search || $this->native_search || !$event['approve_forums']) { return; }
		$mode = $event['mode'];
		$alias = $event['table_alias'];
		if ($mode === 'topic' && $alias === 't.') { $exclude = 'NOT (' . removed_topics::condition('t.') . ')'; }
		elseif ($mode === 'post' && $alias === 'p.')
		{
			$exclude = 'p.plus_legacy_erased=0 AND NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' r WHERE r.topic_id=p.topic_id AND ' . removed_topics::condition('r.') . ')';
		}
		else { return; }
		// Keep phpBB's own forum exclusions and other extensions' approval rules.
		$this->native_search = true;
		try { $native = $event['visibility_sql_overwrite'] ?: $this->visibility->get_global_visibility_sql($mode, $event['exclude_forum_ids'], $alias); }
		finally { $this->native_search = false; }
		$event['visibility_sql_overwrite'] = '(' . $native . ' AND ' . $exclude . ')';
	}
}
