<?php
namespace phpbb2plus\community\event;

use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class topic_descriptions implements EventSubscriberInterface
{
	private $db;
	private $request;
	private $language;
	private $auth;
	private $user;
	private $visibility;
	private $config;
	private $passworded;
	private $change;

	public function __construct($db,$request,$language,$auth,$user,$visibility,$config)
	{
		$this->db=$db; $this->request=$request; $this->language=$language; $this->auth=$auth;
		$this->user=$user; $this->visibility=$visibility; $this->config=$config;
	}

	public static function getSubscribedEvents()
	{
		return array('core.posting_modify_template_vars'=>'editor','core.posting_modify_submission_errors'=>'validate',
			'core.submit_post_modify_sql_data'=>'store','core.viewforum_modify_topicrow'=>'listing',
			'core.mcp_main_modify_fork_sql'=>'forked','core.mcp_main_modify_shadow_sql'=>'shadow',
			'phpbb2plus.community.announcement_topic_row'=>'listing');
	}

	private function first_post($event)
	{
		return $event['mode'] === 'post' || ($event['mode'] === 'edit' && (int) $event['post_id'] > 0 &&
			(int) $event['post_id'] === (int) ($event['post_data']['topic_first_post_id'] ?? 0));
	}

	private function submitted()
	{
		return htmlspecialchars_decode($this->request->variable('plus_description','',true,request_interface::POST),ENT_QUOTES);
	}

	private function revision($event,$value)
	{
		return hash('sha256',implode(':',array((int) $event['forum_id'],(int) $event['topic_id'],(int) $event['post_id'],$value)));
	}

	public function editor($event)
	{
		if (!$this->first_post($event)) { return; }
		$this->language->add_lang('topic_descriptions','phpbb2plus/community');
		$old = $event['post_data']['plus_description'] ?? '';
		$page = $event['page_data'];
		$page['S_PLUS_DESCRIPTION'] = true;
		$new = $this->request->is_set_post('plus_description') ? $this->submitted() : $old;
		$revision = $this->request->variable('plus_description_revision','',false,request_interface::POST);
		$page['PLUS_DESCRIPTION'] = hash_equals($this->revision($event,$new),$revision) ? $old : $new;
		$page['PLUS_DESCRIPTION_REVISION'] = $this->revision($event,$old);
		$event['page_data'] = $page;
	}

	public function validate($event)
	{
		$this->change = null;
		if (!$this->first_post($event) || !$this->request->is_set_post('plus_description')) { return; }
		$old = $event['post_data']['plus_description'] ?? '';
		$new = $this->submitted();
		$revision = $this->request->variable('plus_description_revision','',false,request_interface::POST);
		// A different editor may have changed only the description while this member edited the message body.
		if ($old === $new || hash_equals($this->revision($event,$new),$revision)) { return; }
		$error = '';
		if (utf8_strlen($new) > 255) { $error = 'PLUS_DESCRIPTION_LENGTH'; }
		elseif (!hash_equals($this->revision($event,$old),$revision)) { $error = 'PLUS_DESCRIPTION_CHANGED'; }
		if ($error)
		{
			$this->language->add_lang('topic_descriptions','phpbb2plus/community');
			$event['error'] = array_merge($event['error'],array($this->language->lang($error)));
		}
		elseif ($event['submit'])
		{
			$this->change = array((int) $event['forum_id'],(int) $event['topic_id'],(int) $event['post_id'],$old,$new);
		}
	}

	public function store($event)
	{
		$change = $this->change;
		$this->change = null;
		if (!$change || !in_array($event['post_mode'],array('post','edit_topic','edit_first_post'),true)) { return; }
		list($forum,$topic,$post,$old,$new) = $change;
		$data = $event['data'];
		if ((int) $data['forum_id'] !== $forum || (int) ($data['topic_id'] ?? 0) !== $topic || (int) ($data['post_id'] ?? 0) !== $post) { return; }
		if ($topic)
		{
			$result = $this->db->sql_query('SELECT plus_description FROM ' . TOPICS_TABLE .
				" WHERE topic_id=$topic AND forum_id=$forum AND topic_first_post_id=$post FOR UPDATE");
			$current = $this->db->sql_fetchfield('plus_description');
			$this->db->sql_freeresult($result);
			if ($current !== $old)
			{
				$this->db->sql_transaction('rollback');
				$this->language->add_lang('topic_descriptions','phpbb2plus/community');
				throw new ConflictHttpException($this->language->lang('PLUS_DESCRIPTION_CHANGED'));
			}
		}
		$sql = $event['sql_data'];
		$sql[TOPICS_TABLE]['sql']['plus_description'] = $new;
		$event['sql_data'] = $sql;
	}

	public function listing($event)
	{
		$row = $event['row'];
		$text = $row['plus_description'] ?? '';
		$forum = (int) $row['forum_id'];
		if ($text === '' || (!empty($row['topic_moved_id']) && (int) $row['topic_moved_id'] !== (int) $row['topic_id']) ||
			!$this->auth->acl_get('f_list',$forum) || !$this->auth->acl_get('f_read',$forum) || !$this->visibility->is_visible('topic',$forum,$row)) { return; }
		if ($this->passworded === null) { $this->passworded = $this->user->get_passworded_forums(); }
		if (isset($this->passworded[$forum])) { return; }
		$text = utf8_htmlspecialchars($text);
		$html = censor_text($text);
		if ($this->config['allow_smilies'] && $this->user->optionget('viewsmilies'))
		{
			// Recognise the board's current smileys, as the old topic list did.
			// The description is a plain text field; links, HTML and BBCodes stay literal.
			generate_text_for_storage($text,$uid,$bits,$flags,false,false,true,false,false,false,false);
			if (preg_match('#^<[rt][ >]#',$text)) { $html = generate_text_for_display($text,$uid,$bits,$flags); }
		}
		$vars = $event['topic_row'];
		$vars['S_PLUS_DESCRIPTION'] = true;
		$vars['PLUS_DESCRIPTION'] = $html;
		$event['topic_row'] = $vars;
	}

	public function forked($event)
	{
		$sql = $event['sql_ary'];
		$sql['plus_description'] = $event['topic_row']['plus_description'];
		$event['sql_ary'] = $sql;
	}

	public function shadow($event)
	{
		$sql = $event['shadow'];
		$sql['plus_description'] = $event['row']['plus_description'];
		$event['shadow'] = $sql;
	}
}
