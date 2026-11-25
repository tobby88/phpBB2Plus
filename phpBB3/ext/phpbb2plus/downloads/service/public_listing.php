<?php
namespace phpbb2plus\downloads\service;

class public_listing
{
	private $access;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $paths;

	public function __construct($access,$user,$language,$template,$helper,$paths)
	{
		$this->access=$access;
		$this->user=$user;
		$this->language=$language;
		$this->template=$template;
		$this->helper=$helper;
		$this->paths=$paths;
	}

	public function score($value)
	{
		if ($value === null) { return $this->language->lang('PLUS_DL_UNRATED'); }
		$points = rtrim(rtrim(number_format((float) $value,2,'.',''),'0'),'.');
		return str_replace('.',$this->language->lang('PLUS_DL_DECIMAL'),$points) . ' / 10';
	}

	public function assign($rows)
	{
		$cats = $this->access->categories();
		$days = max(0,min(1000000,(int) $this->access->setting('settings_newdays',0)));
		$recent = time()-$days*86400;
		foreach ($rows as $row)
		{
			$cat = (int) $row['cat_id'];
			$this->template->assign_block_vars('ranked',array('TITLE'=>access::plain($row['title']),'SUMMARY'=>access::plain($row['summary']),
				'PUBLISHER'=>$row['user_id'] ? get_username_string('full',(int) $row['user_id'],$row['username'],$row['user_colour']) : $this->language->lang('GUEST'),
				'DATE'=>$this->user->format_date((int) $row['added_at']),'SCORE'=>$this->score($row['rating']),'COUNT'=>(int) $row['downloads'],
				'RECENT'=>$days && (int) $row['added_at']>$recent,'PINNED'=>(bool) $row['pinned'],'ICON'=>file_display::icon_url($row['icon_name'],$this->paths->get_web_root_path()),
				'CATEGORY'=>access::plain($cats[$cat]['title']),'CATEGORY_URL'=>$this->helper->route('phpbb2plus_downloads_category',array('category'=>$cat)),
				'URL'=>$this->helper->route('phpbb2plus_downloads_file',array('file'=>(int) $row['file_id']))));
		}
	}
}
