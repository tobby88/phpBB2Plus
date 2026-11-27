<?php
namespace phpbb2plus\community\attachment;

class upload extends \phpbb\attachment\upload
{
	private $native;
	private $policy;
	private $deletion;
	private $actor;
	private $lang;
	private $blocks;
	private $request;

	public function __construct($native,$policy,$deletion,$actor,$lang,$blocks,$request)
	{
		$this->native = $native;
		$this->policy = $policy;
		$this->deletion = $deletion;
		$this->actor = $actor;
		$this->lang = $lang;
		$this->blocks = $blocks;
		$this->request = $request;
	}

	public function upload($form_name, $forum_id, $local = false, $local_storage = '', $is_message = false, $local_filedata = array())
	{
		if ($local) { $filename = $local_filedata['realname'] ?? utf8_basename($local_storage); }
		elseif ($this->request->variable('chunks', 0) >= 2) { $filename = $this->request->variable('real_filename', '', true); }
		else { $filename = $this->request->file($form_name)['name'] ?? ''; }
		$extension = \phpbb\files\filespec::get_extension($filename);
		if ($filename !== '' && $this->blocks->blocked($extension))
		{
			return array('post_attach'=>false, 'error'=>array($this->lang->lang('DISALLOWED_EXTENSION', utf8_htmlspecialchars($extension))));
		}
		$file = $this->native->upload($form_name,$forum_id,$local,$local_storage,$is_message,$local_filedata);
		if (empty($file['post_attach']) || !empty($file['error'])) { return $file; }
		$name = $file['physical_filename'];
		$allowed = false;
		try
		{
			// Other native upload listeners can change the final extension. Check it again before the file becomes an attachment.
			$blocked = $this->blocks->blocked($file['extension']);
			$allowed = !$blocked && (!$this->policy->enabled() || $this->policy->reserve($this->actor->data['user_id'],$is_message,$name,$file['filesize']));
		}
		finally
		{
			if (!$allowed)
			{
				if (!empty($file['thumbnail'])) { $this->deletion->unlink_attachment($name,'thumbnail',true); }
				$this->deletion->unlink_attachment($name,'file',true);
			}
		}
		if (!$allowed)
		{
			$file['post_attach'] = false;
			if ($blocked) { $file['error'][] = $this->lang->lang('DISALLOWED_EXTENSION', utf8_htmlspecialchars($file['extension'])); }
			else
			{
				$this->lang->add_lang('attachment_quotas','phpbb2plus/community');
				$file['error'][] = $this->lang->lang($is_message ? 'PLUS_ATTACH_SENT_QUOTA' : 'PLUS_ATTACH_POST_QUOTA');
			}
		}
		return $file;
	}
}
