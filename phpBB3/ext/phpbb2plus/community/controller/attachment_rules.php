<?php
namespace phpbb2plus\community\controller;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class attachment_rules implements EventSubscriberInterface
{
	private $db;
	private $cache;
	private $config;
	private $auth;
	private $user;
	private $language;
	private $helper;
	private $template;
	private $composer_forum;

	public function __construct($db, $cache, $config, $auth, $user, $language, $helper, $template)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->config = $config;
		$this->auth = $auth;
		$this->user = $user;
		$this->language = $language;
		$this->helper = $helper;
		$this->template = $template;
	}

	public static function getSubscribedEvents()
	{
		return array('core.posting_modify_template_vars' => 'post_form', 'core.ucp_pm_compose_template' => 'message_form',
			'core.modify_default_attachments_template_vars' => 'attach_box');
	}

	public function post_form($event) { $this->composer_forum = (int) $event['forum_id']; }
	public function message_form() { $this->composer_forum = 0; }

	private function permitted($forum)
	{
		if (!filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN)) { return false; }
		if (!$forum)
		{
			return $this->user->data['is_registered'] && $this->config['allow_privmsg'] && $this->config['allow_pm_attach'] &&
				$this->auth->acl_get('u_sendpm') && $this->auth->acl_get('u_pm_attach');
		}
		return $this->config['allow_attachments'] && $this->auth->acl_get('u_attach') && $this->auth->acl_get('f_attach', $forum) &&
			$this->auth->acl_get('f_list', $forum) && $this->auth->acl_get('f_read', $forum);
	}

	public function attach_box($event)
	{
		$vars = $event['default_vars'];
		if (empty($vars['S_SHOW_ATTACH_BOX']) || $this->composer_forum === null || !$this->permitted($this->composer_forum)) { return; }
		$this->language->add_lang('attachment_help', 'phpbb2plus/community');
		$vars['U_PLUS_ATTACH_RULES'] = $this->helper->route('phpbb2plus_community_attachment_rules', array('forum' => $this->composer_forum));
		// The core hint uses the post default even for PMs or groups with a different size.
		$vars['MAX_ATTACHMENT_FILESIZE'] = '';
		$event['default_vars'] = $vars;
	}

	public function show($forum)
	{
		$forum = (int) $forum;
		if (!$this->permitted($forum))
		{
			if ($forum) { throw new NotFoundHttpException(); }
			throw new AccessDeniedHttpException();
		}
		if ($forum)
		{
			$result = $this->db->sql_query('SELECT f.forum_id FROM ' . FORUMS_TABLE . ' f WHERE f.forum_id=' . $forum .
				' AND f.forum_type=' . FORUM_POST . " AND (f.forum_password='' OR EXISTS (SELECT 1 FROM " . FORUMS_ACCESS_TABLE .
				' a WHERE a.forum_id=f.forum_id AND a.user_id=' . (int) $this->user->data['user_id'] .
				" AND a.session_id='" . $this->db->sql_escape($this->user->session_id) . "'))");
			$readable = $this->db->sql_fetchfield('forum_id');
			$this->db->sql_freeresult($result);
			if (!$readable) { throw new NotFoundHttpException(); }
		}
		$this->language->add_lang('attachment_help', 'phpbb2plus/community');
		$this->language->add_lang('acp/attachments');
		$extensions = $this->cache->obtain_attach_extensions($forum ?: false);
		$exempt = $this->auth->acl_get('a_') || $this->auth->acl_get('m_', $forum);
		$default = (int) $this->config[$forum ? 'max_filesize' : 'max_filesize_pm'];
		$groups = array();
		foreach (array_keys($extensions['_allowed_']) as $extension)
		{
			$row = $extensions[$extension];
			$limit = $exempt ? 0 : ($row['max_filesize'] ?: $default);
			$name = htmlspecialchars_decode($row['group_name'], ENT_QUOTES);
			$label = 'EXT_GROUP_' . utf8_strtoupper($name);
			if ($this->language->is_set($label)) { $name = $this->language->lang($label); }
			$key = $name . "\0" . $limit;
			if (!isset($groups[$key]))
			{
				$groups[$key] = array('NAME' => $name, 'SIZE' => $limit ? get_formatted_filesize($limit) : $this->language->lang('PLUS_ATTACH_NO_LIMIT'),
					'EXTENSIONS' => array());
			}
			$groups[$key]['EXTENSIONS'][] = '.' . $extension;
		}
		uksort($groups, 'strnatcasecmp');
		foreach ($groups as &$group) { sort($group['EXTENSIONS'], SORT_NATURAL | SORT_FLAG_CASE); }
		unset($group);
		$title = $this->language->lang($forum ? 'PLUS_ATTACH_POST_RULES' : 'PLUS_ATTACH_PM_RULES');
		$this->template->assign_vars(array('PLUS_ATTACH_TITLE' => $title, 'PLUS_ATTACH_GROUPS' => array_values($groups)));
		$response = $this->helper->render('@phpbb2plus_community/attachment_rules.html', $title);
		$response->headers->set('Cache-Control', 'private, no-store');
		return $response;
	}
}
