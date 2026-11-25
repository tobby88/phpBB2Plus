<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\removed_topics;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class removed_topic
{
	private $reader;
	private $db;
	private $auth;
	private $config;
	private $request;
	private $language;
	private $template;
	private $pagination;
	private $helper;
	private $root;
	private $ext;

	public function __construct($reader, $db, $auth, $config, $request, $language, $template, $pagination, $helper, $root, $ext)
	{
		$this->reader = $reader;
		$this->db = $db;
		$this->auth = $auth;
		$this->config = $config;
		$this->request = $request;
		$this->language = $language;
		$this->template = $template;
		$this->pagination = $pagination;
		$this->helper = $helper;
		$this->root = $root;
		$this->ext = $ext;
	}

	private function count($from)
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		return $count;
	}

	public function show($topic_id, $post_id)
	{
		$route = $post_id ? 'phpbb2plus_community_removed_post' : 'phpbb2plus_community_removed_topic';
		$params = $post_id ? array('post_id' => (int) $post_id) : array('topic_id' => (int) $topic_id);
		$descending = $this->request->variable('sd', '') === 'd';
		$offset = max(0, $this->request->variable('start', 0));
		if ($descending) { $params['sd'] = 'd'; }
		if ($offset) { $params['start'] = $offset; }
		$topic = $this->reader->open($topic_id, $post_id, $route, $params, true, false);
		if (!removed_topics::marker($topic)) { throw new NotFoundHttpException(); }
		$id = (int) $topic['topic_id'];
		$forum = (int) $topic['forum_id'];
		$from = ' FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id
			JOIN ' . FORUMS_TABLE . " f ON f.forum_id=t.forum_id WHERE p.topic_id=$id AND p.forum_id=$forum AND p.post_visibility=" .
			ITEM_DELETED . ' AND p.plus_public_post=1 AND ' . removed_topics::condition('t.') . " AND f.forum_password='" .
			$this->db->sql_escape($topic['forum_password']) . "'";
		$total = $this->count($from);
		if (!$total) { throw new NotFoundHttpException(); }
		$limit = max(1, (int) $this->config['posts_per_page']);
		if ($post_id)
		{
			$before = $descending ? '>' : '<';
			$time = (int) $topic['post_time'];
			$position = $this->count($from . " AND (p.post_time $before $time OR (p.post_time=$time AND p.post_id $before " . (int) $post_id . '))');
			$offset = intdiv($position, $limit) * $limit;
		}
		$start = $this->pagination->validate_start($offset, $limit, $total);
		$args = array('topic_id' => $id);
		$page_url = $this->helper->route('phpbb2plus_community_removed_topic', $args + ($descending ? array('sd' => 'd') : array()));
		$this->pagination->generate_template_pagination($page_url, 'pagination', 'start', $total, $limit, $start);
		$order = $descending ? 'DESC' : 'ASC';
		// A retained Plus placeholder contains a position only, even for moderators.
		$result = $this->db->sql_query_limit('SELECT p.post_id' . $from . " ORDER BY p.post_time $order,p.post_id $order", $limit, $start);
		while ($row = $this->db->sql_fetchrow($result)) { $this->template->assign_block_vars('marker', array('ID' => (int) $row['post_id'])); }
		$this->db->sql_freeresult($result);
		$print = $this->auth->acl_get('f_print', $forum);
		$this->template->assign_vars(array('REMOVED_TOPIC' => $topic['topic_title'], 'REMOVED_POST' => $this->language->lang('PLUS_REMOVED_POSTS', 1),
			'FORUM_NAME' => $topic['forum_name'], 'U_REMOVED_FORUM' => append_sid($this->root . 'viewforum.' . $this->ext, 'f=' . $forum),
			'U_REMOVED_PRINT' => $print ? $this->helper->route('phpbb2plus_community_topic_print', $args) : '',
			'U_REMOVED_EXPORT' => $print ? $this->helper->route('phpbb2plus_community_topic_export', $args) : ''));
		$response = $this->helper->render('@phpbb2plus_community/removed_topic.html', $topic['topic_title'], 200, false, $forum);
		$response->headers->set('Cache-Control', 'private, no-store');
		$response->setVary('Cookie');
		return $response;
	}
}
