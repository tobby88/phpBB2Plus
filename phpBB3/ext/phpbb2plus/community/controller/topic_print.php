<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\print_options;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class topic_print
{
	private $reader;
	private $language;
	private $request;
	private $template;
	private $helper;
	private $ext;

	public function __construct($reader, $language, $request, $template, $helper, $ext)
	{
		$this->reader = $reader;
		$this->language = $language;
		$this->request = $request;
		$this->template = $template;
		$this->helper = $helper;
		$this->ext = $ext;
	}

	public function show($topic_id, $post_id = 0)
	{
		$filters = print_options::filters($this->request->variable('postdays', 0), $this->request->variable('postorder', ''), true);
		$route = $post_id ? 'phpbb2plus_community_post_print' : 'phpbb2plus_community_topic_print';
		$params = ($post_id ? array('post_id' => (int) $post_id) : array('topic_id' => (int) $topic_id)) + $filters;
		$topic = $this->reader->open($topic_id, $post_id, $route, $params);
		$forum = (int) $topic['forum_id'];
		$headers = array('Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'Content-Type' => 'text/html; charset=UTF-8');
		if ($this->request->server('REQUEST_METHOD') === 'HEAD') { return new Response('', 200, $headers); }
		$title = censor_text($topic['topic_title']);
		page_header($title, false, $forum, 'forum', false);
		$this->helper->display_footer(false);
		$this->template->assign_vars(array('TOPIC_TITLE' => $title, 'FORUM_NAME' => $topic['forum_name'],
			'U_FORUM' => generate_board_url() . '/viewforum.' . $this->ext . '?f=' . $forum,
			'U_TOPIC' => generate_board_url() . '/viewtopic.' . $this->ext . '?t=' . (int) $topic['topic_id'],
			'PRINT_POSTS' => $this->reader->posts($topic, $filters), 'PRINT_FILTERED' => isset($filters['postdays']),
			'PRINT_REMOVED' => $this->language->lang('PLUS_REMOVED_POSTS', 1)));
		$this->template->set_filenames(array('plus_print' => '@phpbb2plus_community/topic_print.html'));
		return new StreamedResponse(function () { $this->template->display('plus_print'); }, 200, $headers);
	}
}
