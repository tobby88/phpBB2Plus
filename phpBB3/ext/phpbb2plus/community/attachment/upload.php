<?php
namespace phpbb2plus\community\attachment;

class upload extends \phpbb\attachment\upload
{
	private $native;
	private $policy;
	private $deletion;
	private $actor;
	private $lang;

	public function __construct($native,$policy,$deletion,$actor,$lang)
	{
		$this->native = $native;
		$this->policy = $policy;
		$this->deletion = $deletion;
		$this->actor = $actor;
		$this->lang = $lang;
	}

	public function upload($form_name, $forum_id, $local = false, $local_storage = '', $is_message = false, $local_filedata = array())
	{
		$file = $this->native->upload($form_name,$forum_id,$local,$local_storage,$is_message,$local_filedata);
		if (!$this->policy->enabled() || empty($file['post_attach']) || !empty($file['error'])) { return $file; }
		$name = $file['physical_filename'];
		$allowed = false;
		try { $allowed = $this->policy->reserve($this->actor->data['user_id'],$is_message,$name,$file['filesize']); }
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
			$this->lang->add_lang('attachment_quotas','phpbb2plus/community');
			$file['post_attach'] = false;
			$file['error'][] = $this->lang->lang($is_message ? 'PLUS_ATTACH_SENT_QUOTA' : 'PLUS_ATTACH_POST_QUOTA');
		}
		return $file;
	}
}
