<?php
namespace phpbb2plus\community\controller;

use phpbb2plus\community\service\rank_image;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ranks
{
	private $db;
	private $cache;
	private $config;
	private $user;
	private $auth;
	private $helper;
	private $paths;
	private $template;
	private $language;
	private $request;
	private $pagination;
	private $root;
	private $ext;

	public function __construct($db, $cache, $config, $user, $auth, $helper, $paths, $template, $language, $request, $pagination, $root, $ext)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->helper = $helper;
		$this->paths = $paths;
		$this->template = $template;
		$this->language = $language;
		$this->request = $request;
		$this->pagination = $pagination;
		$this->root = $root;
		$this->ext = $ext;
	}

	private function catalogue()
	{
		$source = $this->cache->obtain_ranks();
		$normal = $source['normal'] ?? array();
		$special = $source['special'] ?? array();
		// The first eligible threshold in phpBB's own descending cache wins ties.
		$assignment = 'CASE WHEN user_rank <> 0 THEN user_rank';
		$upper = null;
		foreach ($normal as $id => &$rank)
		{
			$minimum = (int) $rank['rank_min'];
			$assignment .= ' WHEN user_posts >= ' . $minimum . ' THEN ' . (int) $id;
			$rank['condition'] = 'user_rank = 0 AND user_posts >= ' . $minimum . ($upper === null ? '' : ' AND user_posts < ' . $upper);
			$upper = $minimum;
		}
		unset($rank);
		foreach ($special as $id => &$rank) { $rank['condition'] = 'user_rank = ' . (int) $id; }
		unset($rank);
		uasort($special, static function ($a, $b) {
			return strnatcasecmp($a['rank_title'], $b['rank_title']) ?: (int) $a['rank_id'] <=> (int) $b['rank_id'];
		});
		return array(array_reverse($normal, true) + $special, $assignment . ' ELSE 0 END');
	}

	public function show()
	{
		$route = 'phpbb2plus_community_ranks';
		$selected = $this->request->variable('rank', 0);
		$start = max(0, $this->request->variable('start', 0));
		if (empty($this->user->data['is_registered']))
		{
			login_box($this->helper->route($route, array('rank' => $selected, 'start' => $start), false));
		}
		if (!$this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel'))
		{
			throw new \phpbb\exception\http_exception(403, 'NO_VIEW_USERS');
		}
		$this->language->add_lang('community', 'phpbb2plus/community');
		list($catalogue, $assignment) = $this->catalogue();
		if ($selected && !isset($catalogue[$selected])) { throw new NotFoundHttpException(); }
		$active = $this->db->sql_in_set('user_type', array(USER_NORMAL, USER_FOUNDER)) . ' AND user_id <> ' . ANONYMOUS;
		$counts = array();
		if ($selected)
		{
			$result = $this->db->sql_query('SELECT COUNT(*) AS members FROM ' . USERS_TABLE . ' WHERE ' .
				$active . ' AND ' . $catalogue[$selected]['condition']);
			$counts[$selected] = (int) $this->db->sql_fetchfield('members');
			$this->db->sql_freeresult($result);
		}
		elseif ($catalogue)
		{
			// Aggregate the member table once. Only fetch names shown on this page.
			$result = $this->db->sql_query('SELECT ' . $assignment . ' AS assigned_rank, COUNT(*) AS members FROM ' .
				USERS_TABLE . ' WHERE ' . $active . ' GROUP BY ' . $assignment);
			while ($row = $this->db->sql_fetchrow($result)) { $counts[(int) $row['assigned_rank']] = (int) $row['members']; }
			$this->db->sql_freeresult($result);
		}
		$sections = array();
		foreach ($catalogue as $id => $rank)
		{
			if ($selected && $selected !== (int) $id) { continue; }
			$special = !empty($rank['rank_special']);
			$count = $counts[$id] ?? 0;
			$limit = $selected ? 25 : 10;
			$detail = $this->helper->route($route, array('rank' => $id));
			$offset = $selected ? $this->pagination->validate_start($start, $limit, $count) : 0;
			if ($selected && $count > $limit) { $this->pagination->generate_template_pagination($detail, 'pagination', 'start', $count, $limit, $offset); }
			$names = array();
			if ($count)
			{
				$result = $this->db->sql_query_limit('SELECT user_id, username FROM ' . USERS_TABLE . ' WHERE ' .
					$active . ' AND ' . $rank['condition'] . ' ORDER BY username_clean, user_id', $limit, $offset);
				while ($member = $this->db->sql_fetchrow($result))
				{
					$names[] = array('NAME' => htmlspecialchars_decode($member['username'], ENT_QUOTES),
						'URL' => append_sid($this->root . 'memberlist.' . $this->ext, 'mode=viewprofile&u=' . (int) $member['user_id'], false));
				}
				$this->db->sql_freeresult($result);
			}
			$img = '';
			if ($rank['rank_image'] !== '')
			{
				$src = $this->paths->update_web_root_path($this->root . $this->config['ranks_path'] . '/' . $rank['rank_image']);
				$img = rank_image::localize($src, $this->user->lang_name);
			}
			$key = $special ? 'PLUS_RANKS_SPECIAL' : 'PLUS_RANKS_STANDARD';
			$sections[$key][] = array('ID' => $id, 'TITLE' => htmlspecialchars_decode($rank['rank_title'], ENT_QUOTES),
				'MIN' => $special ? null : (int) $rank['rank_min'], 'IMAGE' => $img, 'COUNT' => $count, 'MEMBERS' => $names,
				'MORE' => !$selected && $count > $limit ? $detail : '');
		}
		$this->template->assign_vars(array('PLUS_RANK_SECTIONS' => $sections, 'S_PLUS_RANK_DETAIL' => (bool) $selected,
			'U_PLUS_ALL_RANKS' => $this->helper->route($route)));
		return $this->helper->render('@phpbb2plus_community/ranks.html', $this->language->lang('PLUS_RANKS'));
	}
}
