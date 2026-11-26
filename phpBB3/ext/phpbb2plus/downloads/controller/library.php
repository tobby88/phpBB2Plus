<?php
namespace phpbb2plus\downloads\controller;

use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\field_data;
use phpbb2plus\downloads\service\file_display;
use phpbb2plus\downloads\service\mirrors;

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
	private $paths;
	private $listing;
	private $mail;

	public function __construct($access, $db, $user, $language, $template, $helper, $request, $pagination, $feedback, $prefix, $paths, $listing, $mail)
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
		$this->paths = $paths;
		$this->listing = $listing;
		$this->mail = $mail;
	}

	public function file($file)
	{
		$id = (int) $file;
		$f = $this->access->file($id, true);
		$cat = (int) $f['cat_id'];
		$this->language->add_lang('downloads','phpbb2plus/downloads');
		$this->listing->navigation('',$cat);
		$raw = json_decode($f['source_json'], true) ?: array();
		$bytes = $f['asset_status'] === 'ready' ? (int) $f['file_bytes'] :
			filter_var($raw['file_size'] ?? 0, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1)));
		$updated = (int) $f['updated_at'];
		$title = access::plain($f['title']);
		$download = $this->access->allows($cat, 'download');
		if ($this->mail->can($f)) { $this->template->assign_var('U_DL_RECOMMEND',$this->helper->route('phpbb2plus_downloads_recommend',array('file'=>$id))); }
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
			'DL_PREVIEW_LINK'=>(bool) $f['preview_link'],'DL_ICON'=>file_display::icon_url($f['icon_name'],$this->paths->get_web_root_path()),
			'DL_CREATOR'=>access::plain($raw['file_creator'] ?? ''), 'DL_VERSION'=>access::plain($raw['file_version'] ?? ''), 'DL_COUNT'=>(int) $f['downloads'],
			'DL_PUBLISHER'=>$f['username'] === null ? $this->language->lang('GUEST') : get_username_string('full',(int) $f['owner_id'],$f['username'],$f['user_colour']),
			'DL_DATE'=>$this->user->format_date((int) $f['added_at']),
			'DL_UPDATED'=>$updated && $updated !== (int) $f['added_at'] ? $this->user->format_date($updated) : $this->language->lang('NEVER'),
			'DL_LAST_DOWNLOAD'=>$f['last_download'] ? $this->user->format_date((int) $f['last_download']) : $this->language->lang('NEVER'),
			'DL_SIZE'=>$bytes === false ? $this->language->lang('PLUS_DL_UNKNOWN_SIZE') : get_formatted_filesize($bytes),
			'DL_PENDING'=>!$f['approved'], 'DL_BROKEN'=>(bool) $f['broken'],
			'U_DL_DOCS'=>utf8_htmlspecialchars(access::external($raw['file_docsurl'] ?? '')), 'U_DL_PREVIEW'=>$f['preview_name'] ?
				$this->helper->route('phpbb2plus_downloads_preview', array('file'=>$id)) : '', 'U_DL_REMOTE_PREVIEW'=>$f['preview_name'] ? '' : utf8_htmlspecialchars(access::external($raw['file_ssurl'] ?? ''))));
		if ($download)
		{
			if (in_array($f['asset_status'],array('ready','remote'),true)) { $this->template->assign_var('U_DL_MAIN',$this->helper->route('phpbb2plus_downloads_media',array('file'=>$id,'mirror'=>0))); }
			else { $this->template->assign_var('DL_UNAVAILABLE',true); }
			$result = $this->db->sql_query('SELECT mirror_id,location,source_json FROM ' . $this->prefix .
				"mirrors WHERE file_id=$id AND asset_status IN ('ready','remote') ORDER BY mirror_id");
			while ($r = $this->db->sql_fetchrow($result))
			{
				$label = mirrors::label($r);
				$this->template->assign_block_vars('dl_mirrors',array('LABEL'=>trim($label) === '' ?
					$this->language->lang('PLUS_DL_MIRROR_NUMBER',(int) $r['mirror_id']) : $label,
					'URL'=>$this->helper->route('phpbb2plus_downloads_media',array('file'=>$id,'mirror'=>(int) $r['mirror_id']))));
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
			$this->template->assign_vars(array('S_DL_RATINGS'=>true, 'DL_SCORE'=>$this->listing->score($rating['average']),
				'DL_VOTES'=>$rating['votes'] ? $this->language->lang('PLUS_DL_VOTE_COUNT',(int) $rating['votes']) : ''));
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
