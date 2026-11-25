<?php
namespace phpbb2plus\downloads\controller;

use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\field_data;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class library
{
	private $access;
	private $db;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $pagination;
	private $feedback;
	private $prefix;

	public function __construct($access, $db, $user, $language, $template, $helper, $request, $pagination, $feedback, $prefix)
	{
		$this->access = $access;
		$this->db = $db;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->request = $request;
		$this->pagination = $pagination;
		$this->feedback = $feedback;
		$this->prefix = $prefix;
	}

	private function nav($category = 0)
	{
		$this->access->open();
		$this->language->add_lang('downloads', 'phpbb2plus/downloads');
		$this->template->assign_block_vars('navlinks', array('FORUM_NAME'=>$this->language->lang('PLUS_DL'),
			'U_VIEW_FORUM'=>$this->helper->route('phpbb2plus_downloads_index')));
		if (!$category) { return; }
		if (!$this->access->visible($category)) { throw new NotFoundHttpException(); }
		$cats = $this->access->categories();
		$path = array();
		for ($id = $category; $id; $id = (int) $cats[$id]['parent_id']) { $path[] = $id; }
		foreach (array_reverse($path) as $id)
		{
			$this->template->assign_block_vars('navlinks', array('FORUM_NAME'=>utf8_htmlspecialchars(access::plain($cats[$id]['title'])),
				'U_VIEW_FORUM'=>$this->helper->route('phpbb2plus_downloads_category', array('category'=>$id))));
		}
	}

	public function index($category = 0)
	{
		$id = (int) $category;
		$this->nav($id);
		$cats = $this->access->categories();
		$query = trim(htmlspecialchars_decode($this->request->variable('q', '', true), ENT_QUOTES));
		$can_search = $this->access->allows(0, 'search');
		if ($query !== '' && !$can_search) { throw new NotFoundHttpException(); }
		$scope = array();
		$can_submit = false;
		foreach ($cats as $key => $c)
		{
			if (!$this->access->visible($key)) { continue; }
			if (!$id && !$can_submit && $this->access->can_submit($key)) { $can_submit = true; }
			if ((int) $c['parent_id'] === $id && $query === '')
			{
				$this->template->assign_block_vars('dl_cats', array('TITLE'=>access::plain($c['title']), 'SUMMARY'=>access::plain($c['summary']),
					'URL'=>$this->helper->route('phpbb2plus_downloads_category', array('category'=>$key))));
			}
			if (!$this->access->visible($key, true)) { continue; }
			if ($id && $key !== $id)
			{
				if ($query === '') { continue; }
				$parent = (int) $c['parent_id'];
				while ($parent && $parent !== $id) { $parent = (int) $cats[$parent]['parent_id']; }
				if (!$parent) { continue; }
			}
			$scope[] = $key;
		}
		$route = $id ? 'phpbb2plus_downloads_category' : 'phpbb2plus_downloads_index';
		$params = $id ? array('category'=>$id) : array();
		$url = $this->helper->route($route, $params);
		$title = $id ? access::plain($cats[$id]['title']) : access::plain($this->access->setting('settings_dbname', $this->language->lang('PLUS_DL')));
		$this->template->assign_vars(array('DL_TITLE'=>$title, 'DL_QUERY'=>$query, 'S_DL_SEARCH'=>$can_search, 'U_DL_SEARCH'=>$url));
		if ($can_submit || ($id && $this->access->can_submit($id)))
		{
			$this->template->assign_var('U_DL_SUBMIT', $id ? $this->helper->route('phpbb2plus_downloads_submit', array('category'=>$id)) :
				$this->helper->route('phpbb2plus_downloads_destinations'));
		}
		if ($id && $this->access->can_review($id))
		{ $this->template->assign_var('U_DL_QUEUE', $this->helper->route('phpbb2plus_downloads_queue',array('category'=>$id))); }
		if ($scope && ($id || $query !== '' || ((int) $this->access->setting('settings_viewall', 1) && $this->access->allows(0,'viewall'))))
		{
			$filter = $this->db->sql_in_set('cat_id', $scope) . ' AND approved=1';
			if ($query !== '')
			{
				$pattern = $this->db->sql_like_expression($this->db->get_any_char() . utf8_encode_ucr($query) . $this->db->get_any_char());
				$filter .= " AND (title $pattern OR summary $pattern)";
			}
			$from = ' FROM ' . $this->prefix . 'files WHERE ' . $filter;
			$result = $this->db->sql_query('SELECT COUNT(*) AS total' . $from);
			$total = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);
			$limit = max(1, min(100, (int) $this->access->setting('settings_file_page', 20)));
			$start = $this->pagination->validate_start(max(0,$this->request->variable('start',0)), $limit, $total);
			$this->pagination->generate_template_pagination($this->helper->route($route, $params + ($query !== '' ? array('q'=>$query) : array())), 'pagination', 'start', $total, $limit, $start);
			$result = $this->db->sql_query_limit('SELECT file_id,title,summary,downloads,pinned' . $from . ' ORDER BY pinned DESC,added_at DESC,file_id DESC', $limit, $start);
			while ($f = $this->db->sql_fetchrow($result))
			{
				$this->template->assign_block_vars('dl_files', array('TITLE'=>access::plain($f['title']), 'SUMMARY'=>access::plain($f['summary']), 'DOWNLOADS'=>(int) $f['downloads'],
					'URL'=>$this->helper->route('phpbb2plus_downloads_file', array('file'=>(int) $f['file_id']))));
			}
			$this->db->sql_freeresult($result);
		}
		return $this->helper->render('@phpbb2plus_downloads/index.html', utf8_htmlspecialchars($title));
	}

	public function file($file)
	{
		$id = (int) $file;
		$f = $this->access->file($id);
		$cat = (int) $f['cat_id'];
		$this->nav($cat);
		$raw = json_decode($f['source_json'], true) ?: array();
		$title = access::plain($f['title']);
		$download = $this->access->allows($cat, 'download');
		if ($this->access->can_manage($f, 'edit_file'))
		{ $this->template->assign_var('U_DL_EDIT', $this->helper->route('phpbb2plus_downloads_edit',array('file'=>$id))); }
		if ($this->access->can_manage($f, 'delete_file'))
		{ $this->template->assign_var('U_DL_DELETE', $this->helper->route('phpbb2plus_downloads_delete',array('file'=>$id))); }
		if ($this->access->can_review($cat))
		{
			$this->template->assign_var('U_DL_QUEUE', $this->helper->route('phpbb2plus_downloads_queue',
				array('category'=>$cat, 'status'=>$f['approved'] ? 'published' : 'pending')));
		}
		$this->template->assign_vars(array('DL_TITLE'=>$title, 'DL_SUMMARY'=>access::plain($f['summary']), 'DL_DETAIL'=>access::plain($raw['file_longdesc'] ?? ''),
			'DL_CREATOR'=>access::plain($raw['file_creator'] ?? ''), 'DL_VERSION'=>access::plain($raw['file_version'] ?? ''), 'DL_COUNT'=>(int) $f['downloads'],
			'DL_DATE'=>$this->user->format_date((int) $f['added_at']), 'DL_BYTES'=>(int) $f['file_bytes'], 'DL_PENDING'=>!$f['approved'], 'DL_BROKEN'=>(bool) $f['broken'],
			'U_DL_DOCS'=>utf8_htmlspecialchars(access::external($raw['file_docsurl'] ?? '')), 'U_DL_PREVIEW'=>$f['preview_name'] ?
				$this->helper->route('phpbb2plus_downloads_preview', array('file'=>$id)) : '', 'U_DL_REMOTE_PREVIEW'=>$f['preview_name'] ? '' : utf8_htmlspecialchars(access::external($raw['file_ssurl'] ?? ''))));
		if ($download)
		{
			if (in_array($f['asset_status'],array('ready','remote'),true)) { $this->template->assign_var('U_DL_MAIN',$this->helper->route('phpbb2plus_downloads_media',array('file'=>$id,'mirror'=>0))); }
			else { $this->template->assign_var('DL_UNAVAILABLE',true); }
			$result = $this->db->sql_query('SELECT mirror_id,location,asset_status FROM ' . $this->prefix . "mirrors WHERE file_id=$id ORDER BY mirror_id");
			while ($r = $this->db->sql_fetchrow($result))
			{
				if (in_array($r['asset_status'],array('ready','remote'),true)) { $this->template->assign_block_vars('dl_mirrors',array('LABEL'=>access::plain($r['location']),
					'URL'=>$this->helper->route('phpbb2plus_downloads_media',array('file'=>$id,'mirror'=>(int) $r['mirror_id'])))); }
			}
			$this->db->sql_freeresult($result);
		}
		$result = $this->db->sql_query('SELECT d.title,d.field_type,v.values_json,v.source_json FROM ' . $this->prefix . 'fields d JOIN ' . $this->prefix .
			"field_values v ON v.field_id=d.field_id WHERE v.file_id=$id ORDER BY d.position,d.field_id,v.row_id");
		while ($r = $this->db->sql_fetchrow($result))
		{
			$values = field_data::values($r);
			if ((int) $r['field_type'] > 5 || !$values || $values === array('')) { continue; }
			$this->template->assign_block_vars('dl_fields', array('TITLE'=>access::plain($r['title']),'VALUE'=>implode(', ',$values),
				'MULTILINE'=>(int) $r['field_type'] === 1));
		}
		$this->db->sql_freeresult($result);
		$cats = $this->access->categories();
		if ($cats[$cat]['allow_ratings'])
		{
			$result = $this->db->sql_query('SELECT COUNT(*) AS votes,AVG(points) AS average FROM ' . $this->prefix . "votes WHERE file_id=$id");
			$rating = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($rating['votes']) { $this->template->assign_vars(array('DL_VOTES'=>(int) $rating['votes'], 'DL_AVERAGE'=>round((float) $rating['average'], 2))); }
		}
		$feedback = array();
		foreach (array('rate','comment') as $operation)
		{
			if ($this->feedback->can($f,$operation))
			{
				$feedback['U_DL_' . strtoupper($operation)] = $this->helper->route('phpbb2plus_downloads_feedback',array('file'=>$id,'operation'=>$operation));
			}
		}
		if ($feedback) { add_form_key('plus_dl_feedback_' . $id); $this->template->assign_vars($feedback + array('DL_NONCE'=>bin2hex(random_bytes(16)))); }
		if ($cats[$cat]['allow_comments'] && $this->access->allows($cat,'view_comment'))
		{
			$table = $this->prefix . 'comments';
			$result = $this->db->sql_query("SELECT COUNT(*) AS total FROM $table WHERE file_id=$id");
			$count = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);
			$start = $this->pagination->validate_start(max(0,$this->request->variable('start',0)), 20, $count);
			$this->pagination->generate_template_pagination($this->helper->route('phpbb2plus_downloads_file',array('file'=>$id)), 'pagination','start',$count,20,$start);
			$result = $this->db->sql_query_limit("SELECT c.*,u.username,u.user_colour FROM $table c LEFT JOIN " . USERS_TABLE .
				" u ON u.user_id=c.owner_id AND c.owner_id>0 WHERE c.file_id=$id ORDER BY c.posted_at,c.comment_id", 20, $start);
			while ($r = $this->db->sql_fetchrow($result))
			{
				$this->template->assign_block_vars('dl_comments',array('ID'=>(int) $r['comment_id'],'TITLE'=>access::plain($r['title']), 'BODY'=>$this->feedback->render($r['body']),
					'U_DELETE'=>$this->feedback->can_delete($f,$r) ? $this->helper->route('phpbb2plus_downloads_remove',array('file'=>$id,'comment'=>(int) $r['comment_id'])) : '',
					'DATE'=>$this->user->format_date((int) $r['posted_at']), 'AUTHOR'=>$r['username'] === null ? $this->language->lang('GUEST') : get_username_string('full',$r['owner_id'],$r['username'],$r['user_colour'])));
			}
			$this->db->sql_freeresult($result);
		}
		return $this->helper->render('@phpbb2plus_downloads/file.html', utf8_htmlspecialchars($title));
	}
}
