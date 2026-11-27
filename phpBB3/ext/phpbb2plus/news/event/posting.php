<?php
namespace phpbb2plus\news\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class posting implements EventSubscriberInterface
{
	private $db;
	private $auth;
	private $user;
	private $config;
	private $request;
	private $template;
	private $language;
	private $table;
	private $change;

	public function __construct($db, $auth, $user, $config, $request, $template, $language, $table)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->config = $config;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->table = $table;
	}

	public static function getSubscribedEvents()
	{
		return array('core.permissions'=>'permission', 'core.posting_modify_template_vars'=>'editor',
			'core.posting_modify_submission_errors'=>'validate', 'core.submit_post_modify_sql_data'=>'store');
	}

	public function permission($event)
	{
		$this->language->add_lang('news','phpbb2plus/news');
		$permissions = $event['permissions'];
		$permissions['f_plus_news'] = array('lang'=>'ACL_F_PLUS_NEWS','cat'=>'post');
		$event['permissions'] = $permissions;
	}

	private function can_publish($forum)
	{
		return $this->config['plus_news_publish'] && $this->user->data['is_registered'] &&
			$this->auth->acl_get('f_post',$forum) && $this->auth->acl_get('f_plus_news',$forum);
	}

	private function first_post($event)
	{
		return $this->can_publish((int) $event['forum_id']) && ($event['mode'] === 'post' ||
			($event['mode'] === 'edit' && (int) $event['post_id'] > 0 &&
			(int) $event['post_id'] === (int) ($event['post_data']['topic_first_post_id'] ?? 0)));
	}

	private function current($forum, $topic, $lock = false)
	{
		if (!$topic) { return 0; }
		$sql = 'SELECT plus_news_cat FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . (int) $topic . ' AND forum_id=' . (int) $forum;
		$result = $this->db->sql_query($sql . ($lock ? ' FOR UPDATE' : ''));
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ? (int) $row['plus_news_cat'] : -1;
	}

	private function available($category, $lock = false)
	{
		if ($category === 0) { return true; }
		$result = $this->db->sql_query('SELECT category_id FROM ' . $this->table . ' WHERE active=1 AND category_id=' . (int) $category . ($lock ? ' FOR UPDATE' : ''));
		$found = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $found;
	}

	private function selected()
	{
		// A malformed or missing selection must never turn into "unpublish".
		$value = $this->request->variable('plus_news_category','',false,request_interface::POST);
		return preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$value) ? (int) $value : -1;
	}

	public function editor($event)
	{
		if (!$this->first_post($event)) { return; }
		$this->language->add_lang('news','phpbb2plus/news');
		$current = $this->current($event['forum_id'], $event['mode'] === 'post' ? 0 : $event['topic_id']);
		if ($current < 0) { return; }
		$selected = $this->request->is_set_post('plus_news_category') ? $this->selected() : $current;
		$categories = array(0=>$this->language->lang('PLUS_NEWS_NONE'));
		$result = $this->db->sql_query('SELECT category_id,name FROM ' . $this->table . ' WHERE active=1 ORDER BY name,category_id');
		while ($r = $this->db->sql_fetchrow($result)) { $categories[(int) $r['category_id']] = $r['name']; }
		$this->db->sql_freeresult($result);
		if (!isset($categories[$current])) { $categories[$current] = $this->language->lang('PLUS_NEWS_RETIRED',$current); }
		foreach ($categories as $id=>$label)
		{
			$this->template->assign_block_vars('plus_news_choices',array('ID'=>$id,'LABEL'=>$label,'SELECTED'=>$id === $selected));
		}
		$this->template->assign_vars(array('S_PLUS_NEWS_EDITOR'=>true,'PLUS_NEWS_PREVIOUS'=>$current));
	}

	public function validate($event)
	{
		$this->change = null;
		if (!$this->request->is_set_post('plus_news_category') || !$this->first_post($event)) { return; }
		$this->language->add_lang('news','phpbb2plus/news');
		$forum = (int) $event['forum_id'];
		$topic = $event['mode'] === 'post' ? 0 : (int) $event['topic_id'];
		$current = $this->current($forum,$topic);
		$previous = $this->request->variable('plus_news_previous','',false,request_interface::POST);
		$selected = $this->selected();
		$error = '';
		if ($current < 0 || (string) $current !== $previous) { $error = 'PLUS_NEWS_CONFLICT'; }
		elseif ($selected !== $current)
		{
			if ($selected < 0 || !$this->available($selected)) { $error = 'PLUS_NEWS_UNKNOWN'; }
			elseif ($event['submit']) { $this->change = array($forum,$topic,(int) $event['post_id'],$current,$selected); }
		}
		if ($error)
		{
			$errors = $event['error'];
			$errors[] = $this->language->lang($error);
			$event['error'] = $errors;
		}
	}

	public function store($event)
	{
		$change = $this->change;
		$this->change = null;
		if (!$change || !in_array($event['post_mode'],array('post','edit_topic','edit_first_post'),true)) { return; }
		list($forum,$topic,$post,$old,$new) = $change;
		$data = $event['data'];
		if ((int) $data['forum_id'] !== $forum || (int) ($data['topic_id'] ?? 0) !== $topic ||
			(int) ($data['post_id'] ?? 0) !== $post || !$this->can_publish($forum)) { return; }
		if (!$this->available($new,true) || $this->current($forum,$topic,true) !== $old)
		{
			$this->db->sql_transaction('rollback');
			throw new ConflictHttpException($this->language->lang('PLUS_NEWS_CONFLICT'));
		}
		$sql = $event['sql_data'];
		$sql[TOPICS_TABLE]['sql']['plus_news_cat'] = $new;
		if (!empty($this->config['plus_topic_groups_ready']))
		{
			// Write the listing choice in the same update that publishes or withdraws the category.
			$sql[TOPICS_TABLE]['sql']['plus_list_news'] = (int) ($new > 0 && (int) $event['topic_type'] === POST_NORMAL);
		}
		$event['sql_data'] = $sql;
	}
}
