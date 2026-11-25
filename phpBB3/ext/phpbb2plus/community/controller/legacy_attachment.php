<?php
namespace phpbb2plus\community\controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class legacy_attachment
{
	private $db;
	private $auth;
	private $visibility;
	private $config;
	private $user;
	private $refs;
	private $root;
	private $ext;

	public function __construct($db, $auth, $visibility, $config, $user, $refs, $root, $ext)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->visibility = $visibility;
		$this->config = $config;
		$this->user = $user;
		$this->refs = $refs;
		$this->root = $root;
		$this->ext = $ext;
	}

	private function readable_post($row, $locked)
	{
		$forum = (int) $row['forum_id'];
		return $forum && !isset($locked[$forum]) && $this->config['allow_attachments'] && $this->auth->acl_get('u_download')
			&& $this->auth->acl_get('f_download', $forum) && $this->auth->acl_get('f_list', $forum) && $this->auth->acl_get('f_read', $forum)
			&& $this->visibility->is_visible('topic', $forum, $row) && $this->visibility->is_visible('post', $forum, $row);
	}

	public function show($source, $format)
	{
		$source = (int) $source;
		$viewer = (int) $this->user->data['user_id'];
		$locked = $this->user->get_passworded_forums();
		// One legacy file could belong to several posts or mailboxes with different audiences.
		$result = $this->db->sql_query("SELECT r.in_message AS original_message,r.parent_id,a.attach_id,a.physical_filename,a.in_message,a.extension,
			t.forum_id,t.topic_poster,t.topic_visibility,p.poster_id,p.post_visibility,pm.user_id AS mailbox_owner
			FROM {$this->refs} r JOIN " . ATTACHMENTS_TABLE . ' a ON a.attach_id=r.attach_id
			LEFT JOIN ' . POSTS_TABLE . ' p ON a.in_message=0 AND p.post_id=a.post_msg_id
			LEFT JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.topic_id=a.topic_id AND t.forum_id=p.forum_id AND t.topic_moved_id=0
			LEFT JOIN ' . PRIVMSGS_TO_TABLE . " pm ON a.in_message=1 AND pm.msg_id=a.post_msg_id AND pm.user_id=$viewer AND pm.pm_deleted=0
			WHERE r.source_id=$source AND a.is_orphan=0" . ($format === 'thumb' ? ' AND a.thumbnail=1' : '') . ' ORDER BY r.in_message,r.parent_id');
		$id = 0;
		while ($row = $this->db->sql_fetchrow($result))
		{
			$physical = 'plus_' . hash_hmac('sha256', $source . ':' . $row['original_message'] . ':' . $row['parent_id'], $this->config['plus_media_key']);
			if ($row['physical_filename'] !== $physical) { continue; }
			$mail = (bool) $row['in_message'];
			if ($mail)
			{
				if (!$this->user->data['is_registered'] || !$row['mailbox_owner'] || !$this->config['allow_pm_attach'] || !$this->auth->acl_get('u_pm_download')) { continue; }
			}
			elseif (!$this->readable_post($row, $locked)) { continue; }
			$extensions = array();
			if (extension_allowed($mail ? false : (int) $row['forum_id'], $row['extension'], $extensions))
			{
				$id = (int) $row['attach_id'];
				break;
			}
		}
		$this->db->sql_freeresult($result);
		if (!$id) { throw new NotFoundHttpException(); }
		$query = 'id=' . $id . ($format === 'thumb' ? '&t=1' : '');
		return new RedirectResponse(append_sid($this->root . 'download/file.' . $this->ext, $query, false), 302, array('Cache-Control' => 'private, no-store'));
	}
}
