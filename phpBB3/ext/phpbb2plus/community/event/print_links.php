<?php
namespace phpbb2plus\community\event;

use phpbb2plus\community\service\print_options;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class print_links implements EventSubscriberInterface
{
	private $auth;
	private $helper;
	private $request;
	private $template;
	private $user;

	public function __construct($auth, $helper, $request, $template, $user)
	{
		$this->auth = $auth;
		$this->helper = $helper;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
	}

	public static function getSubscribedEvents()
	{
		return array('core.viewtopic_modify_page_title' => 'topic');
	}

	public function url($topic, $filters = array())
	{
		return $this->helper->route('phpbb2plus_community_topic_print', array('topic_id' => (int) $topic) + $filters);
	}

	public function topic($event)
	{
		$forum = (int) $event['forum_id'];
		$url = '';
		if ($this->auth->acl_get('f_list', $forum) && $this->auth->acl_get('f_read', $forum) && $this->auth->acl_get('f_print', $forum) &&
			(int) $event['topic_data']['topic_visibility'] !== ITEM_DELETED)
		{
			$days = $this->request->variable('st', (int) $this->user->data['user_post_show_days']);
			$direction = $this->request->variable('sd', (string) $this->user->data['user_post_sortby_dir']);
			$url = $this->url($event['topic_data']['topic_id'], print_options::filters($days, $direction === 'd' ? 'desc' : 'asc', true));
		}
		$this->template->assign_var('U_PRINT_TOPIC', $url);
	}
}
