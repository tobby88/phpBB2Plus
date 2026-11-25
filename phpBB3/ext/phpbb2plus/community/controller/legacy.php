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

	public function __construct($db, $auth, $user, $root, $ext)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->root = $root;
		$this->ext = $ext;
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
}
