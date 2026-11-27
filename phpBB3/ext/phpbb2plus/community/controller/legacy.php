<?php
namespace phpbb2plus\community\controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class legacy
{
	private $db;
	private $auth;
	private $user;
	private $root;
	private $ext;
	private $topic_reader;

	public function __construct($db, $auth, $user, $root, $ext, $topic_reader)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->root = $root;
		$this->ext = $ext;
		$this->topic_reader = $topic_reader;
	}

	private function destination($script, array $params = array(), $fragment = '')
	{
		$query = $params ? http_build_query($params, '', '&', PHP_QUERY_RFC3986) : false;
		$url = append_sid($this->root . $script . '.' . $this->ext, $query, false);
		return new RedirectResponse($url . $fragment, 302, array('Cache-Control'=>'private, no-store'));
	}

	public function forum_link($alias)
	{
		if ($alias === 'forums') { return $this->destination('index'); }
		if (preg_match('/^forum(c?)([1-9][0-9]{0,9})(?:,[a-z0-9-]+)?$/D', $alias, $m))
		{
			$id = (int) $m[2];
			if ($m[1] === 'c')
			{
				$result = $this->db->sql_query('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE plus_legacy_category=' . $id . ' AND forum_type=' . FORUM_CAT);
				$id = (int) $this->db->sql_fetchfield('forum_id');
				$this->db->sql_freeresult($result);
				if (!$id || !$this->auth->acl_gets('f_list', 'f_list_topics', 'f_read', $id)) { throw new NotFoundHttpException(); }
			}
			return $this->destination('viewforum', array('f'=>$id));
		}
		if (preg_match('/^viewforum([1-9][0-9]{0,9})-([0-9]{0,10})-([0-9]{0,10})(?:,[a-z0-9-]+)?$/D', $alias, $m))
		{
			return $this->destination('viewforum', array('f'=>(int) $m[1], 'st'=>(int) $m[2], 'start'=>(int) $m[3]));
		}
		if (preg_match('/^([pn])topic([1-9][0-9]{0,9})$/D', $alias, $m))
		{
			return $this->destination('viewtopic', array('t'=>(int) $m[2], 'view'=>$m[1] === 'p' ? 'previous' : 'next'));
		}
		if (preg_match('/^ftopic([1-9][0-9]{0,9})(?:-([0-9]{0,10})-(asc|desc)-([0-9]{0,10})|-([0-9]{0,10}))?$/D', $alias, $m))
		{
			$params = array('t'=>(int) $m[1]);
			if (!empty($m[3]))
			{
				$params += array('st'=>(int) $m[2], 'sd'=>$m[3] === 'asc' ? 'a' : 'd', 'start'=>(int) $m[4]);
			}
			elseif (isset($m[5])) { $params['start'] = (int) $m[5]; }
			return $this->destination('viewtopic', $params);
		}
		if (preg_match('/^fpost([1-9][0-9]{0,9})$/D', $alias, $m))
		{
			return $this->destination('viewtopic', array('p'=>(int) $m[1]), '#p' . $m[1]);
		}
		throw new NotFoundHttpException();
	}

	public function member_id($source)
	{
		$source = (int) $source;
		if ($source <= 0) { return 0; }
		$r = $this->db->sql_query('SELECT user_id FROM ' . USERS_TABLE . ' WHERE plus_legacy_id=' . $source);
		$id = (int) $this->db->sql_fetchfield('user_id');
		$this->db->sql_freeresult($r);
		return $id > ANONYMOUS ? $id : 0;
	}

	private function moderation_forum($id, $permission)
	{
		// A bare merge bookmark opens the first readable source in board order.
		$ids = $id ? array($id) : array_keys($this->auth->acl_getf($permission, true));
		if (!$ids) { throw new NotFoundHttpException(); }
		$result = $this->db->sql_query('SELECT f.forum_id FROM ' . FORUMS_TABLE . ' f LEFT JOIN ' . FORUMS_ACCESS_TABLE .
			" fa ON fa.forum_id=f.forum_id AND fa.session_id='" . $this->db->sql_escape($this->user->session_id) .
			"' AND fa.user_id=" . (int) $this->user->data['user_id'] . ' WHERE f.forum_type=' . FORUM_POST .
			" AND (f.forum_password='' OR fa.user_id IS NOT NULL) AND " . $this->db->sql_in_set('f.forum_id', $ids) . ' ORDER BY f.left_id');
		$forum = 0;
		while ($row = $this->db->sql_fetchrow($result))
		{
			$candidate = (int) $row['forum_id'];
			if ($this->auth->acl_get('f_list', $candidate) && $this->auth->acl_get('f_read', $candidate) &&
				$this->auth->acl_get($permission, $candidate)) { $forum = $candidate; break; }
		}
		$this->db->sql_freeresult($result);
		if (!$forum) { throw new NotFoundHttpException(); }
		return $forum;
	}

	public function moderation($view, $kind, $id, $start)
	{
		$id = (int) $id;
		$start = (int) $start;
		if (($kind === 'none') !== ($id === 0) || ($view === 'ip' && $kind !== 'post') ||
			(in_array($view, array('split', 'topic'), true) && !in_array($kind, array('topic', 'post'), true)))
		{
			throw new NotFoundHttpException();
		}
		if (empty($this->user->data['is_registered']) || !empty($this->user->data['is_bot']))
		{
			$return = 'app.' . $this->ext . '/legacy/moderation/' . $view . '/' . $kind . '/' . $id . '/' . $start;
			return $this->destination('ucp', array('mode' => 'login', 'redirect' => $return));
		}
		$permissions = array('ip' => 'm_info', 'merge' => 'm_merge', 'split' => 'm_split');
		$permission = $permissions[$view] ?? 'm_';
		$params = array('i' => 'main');
		if ($kind === 'none' && $view === 'forum')
		{
			if (!$this->auth->acl_getf_global('m_')) { throw new NotFoundHttpException(); }
			return $this->destination('mcp', $params + array('mode' => 'front'));
		}
		if ($kind === 'forum' || $kind === 'none')
		{
			$params += array('mode' => 'forum_view', 'f' => $this->moderation_forum($id, $permission));
		}
		else
		{
			try
			{
				$row = $this->topic_reader->open($kind === 'topic' ? $id : 0, $kind === 'post' ? $id : 0, '', array(), false, false);
			}
			catch (AccessDeniedHttpException $e) { throw new NotFoundHttpException(); }
			$forum = (int) $row['forum_id'];
			// A public deletion placeholder gives no access to the original post's moderation data.
			if (!$this->auth->acl_get($permission, $forum) || (int) $row['topic_visibility'] === ITEM_DELETED ||
				($kind === 'post' && (int) $row['post_visibility'] === ITEM_DELETED)) { throw new NotFoundHttpException(); }
			if ($view === 'ip')
			{
				return $this->destination('mcp', $params + array('mode' => 'post_details', 'p' => $id), '#ip');
			}
			$params += array('f' => $forum, 'mode' => $view === 'forum' ? 'forum_view' : 'topic_view');
			if ($view !== 'forum') { $params['t'] = (int) $row['topic_id']; }
			if ($view === 'split' || $view === 'merge') { $params['action'] = $view; }
		}
		if ($start) { $params['start'] = $start; }
		return $this->destination('mcp', $params);
	}

	public function member_link($source, $mode)
	{
		$source = (int) $source;
		$registered = !empty($this->user->data['is_registered']);
		if ($mode === 'email' ? !$registered : !$this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel'))
		{
			if ($registered) { throw new AccessDeniedHttpException(); }
			return $this->destination('ucp', array('mode'=>'login', 'redirect'=>'profile.' . $this->ext . '?mode=' . $mode . '&u=' . $source));
		}
		$id = $this->member_id($source);
		if (!$id) { throw new NotFoundHttpException(); }
		return $this->destination('memberlist', array('mode'=>$mode, 'u'=>$id));
	}

	public function group_link($source, $view, $start)
	{
		$group = (int) $source;
		$start = (int) $start;
		if (empty($this->user->data['is_registered']))
		{
			$return = 'groupcp.' . $this->ext . '?g=' . $group . ($view === 'requests' ? '&validate=1' : '') . ($start ? '&start=' . $start : '');
			return $this->destination('ucp', array('mode' => 'login', 'redirect' => $return));
		}
		// Converted named groups retain their ID. A new group can reuse a retired number.
		$result = $this->db->sql_query('SELECT g.group_type, g.group_founder_manage, ug.user_id, ug.group_leader, ug.user_pending FROM ' . GROUPS_TABLE . ' g
			LEFT JOIN ' . USER_GROUP_TABLE . ' ug ON ug.group_id=g.group_id AND ug.user_id=' . (int) $this->user->data['user_id'] . '
			WHERE g.plus_legacy_group=1 AND g.group_id=' . $group);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if ($row && $row['group_leader'] && $row['user_pending'] && (int) $row['group_type'] !== GROUP_SPECIAL &&
			(!$row['group_founder_manage'] || (int) $this->user->data['user_type'] === USER_FOUNDER) && empty($this->user->data['user_perm_from']))
		{
			$params = array('i'=>'ucp_groups', 'mode'=>'manage', 'action'=>'list', 'g'=>$group);
			if ($start) { $params['start'] = $start; }
			return $this->destination('ucp', $params);
		}
		if (!$row || ((int) $row['group_type'] === GROUP_HIDDEN && (!$row['user_id'] || $row['user_pending']) && !$this->auth->acl_gets('a_group', 'a_groupadd', 'a_groupdel')))
		{
			throw new NotFoundHttpException();
		}
		if ($view === 'requests' && $row['group_leader'] && !$row['user_pending'])
		{
			return $this->destination('ucp', array('i' => 'ucp_groups', 'mode' => 'manage', 'action' => 'list', 'g' => $group));
		}
		$params = array('mode' => 'group', 'g' => $group);
		if ($start) { $params['start'] = $start; }
		return $this->destination('memberlist', $params);
	}

	private function mail_login($query)
	{
		return $this->destination('ucp', array('mode' => 'login', 'redirect' => 'privmsg.' . $this->ext . '?' . $query));
	}

	public function message($mode, $source)
	{
		$source = (int) $source;
		if (empty($this->user->data['is_registered'])) { return $this->mail_login('mode=' . $mode . ($mode === 'post' ? '&u=' : '&p=') . $source); }
		if ($mode === 'post')
		{
			if (!$this->auth->acl_get('u_sendpm')) { throw new AccessDeniedHttpException(); }
			$member = $this->member_id($source);
			if (!$member) { throw new NotFoundHttpException(); }
			return $this->destination('ucp', array('i' => 'pm', 'mode' => 'compose', 'u' => $member));
		}
		$result = $this->db->sql_query('SELECT p.msg_id FROM ' . PRIVMSGS_TABLE . ' p JOIN ' . PRIVMSGS_TO_TABLE . " t ON t.msg_id=p.msg_id
			WHERE p.msg_id=$source AND p.plus_legacy_pm=1 AND t.pm_deleted=0 AND t.user_id=" . (int) $this->user->data['user_id']);
		$found = $this->db->sql_fetchfield('msg_id');
		$this->db->sql_freeresult($result);
		if (!$found) { throw new NotFoundHttpException(); }
		$params = $mode === 'read' ? array('i' => 'pm', 'mode' => 'view') : array('i' => 'pm', 'mode' => 'compose', 'action' => $mode);
		$params['p'] = $source;
		return $this->destination('ucp', $params);
	}

	public function savebox($start)
	{
		$start = (int) $start;
		if (empty($this->user->data['is_registered'])) { return $this->mail_login('folder=savebox&start=' . $start); }
		$result = $this->db->sql_query('SELECT folder_id FROM ' . PRIVMSGS_FOLDER_TABLE . ' WHERE plus_legacy_savebox=1 AND user_id=' .
			(int) $this->user->data['user_id']);
		$folder = (int) $this->db->sql_fetchfield('folder_id');
		$this->db->sql_freeresult($result);
		// The member may have deleted their converted folder and created new ones.
		$params = array('i' => 'pm', 'mode' => 'view');
		if ($folder) { $params['folder'] = $folder; if ($start) { $params['start'] = $start; } }
		return $this->destination('ucp', $params);
	}
}
