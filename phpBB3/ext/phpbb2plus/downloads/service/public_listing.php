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

	public function navigation($active = '',$category = 0)
	{
		$cats = $this->access->categories();
		if ($category && !$this->access->visible($category)) { throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(); }
		$home = $this->helper->route('phpbb2plus_downloads_index');
		$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>$this->language->lang('PLUS_DL'),
			'U_VIEW_FORUM'=>$home));
		$this->template->assign_var('U_DL_HOME',$home);
		$locale = substr($this->language->get_used_language(),0,2);
		if ($this->user->style['style_path'] === 'fisubsilversh' && in_array($locale,array('de','en'),true))
		{
			$this->template->assign_var('DL_ART_LANG',$locale);
		}
		$can_upload_here = $category && $this->access->can_submit($category);
		$choose_upload = false;
		foreach ($this->access->directory() as $id=>$depth)
		{
			if (!$can_upload_here && !$choose_upload && $active !== 'category' && $active !== 'submit' && $this->access->can_submit($id)) { $choose_upload=true; }
			$this->template->assign_block_vars('dl_jump',array('TITLE'=>access::plain($cats[$id]['title']),'DEPTH'=>$depth,'CURRENT'=>$id===$category,
				'URL'=>$this->helper->route('phpbb2plus_downloads_category',array('category'=>$id))));
		}
		if ($active !== 'submit' && ($can_upload_here || $choose_upload))
		{
			$this->template->assign_var('U_DL_UPLOAD',$can_upload_here ? $this->helper->route('phpbb2plus_downloads_submit',array('category'=>$category)) :
				$this->helper->route('phpbb2plus_downloads_destinations'));
		}
		foreach (array('viewall'=>'VIEWALL','search'=>'ADVANCED','stats'=>'STATS','toplist'=>'TOPLIST') as $route=>$link)
		{
			if ($route === $active || ($route === 'viewall' && !(int) $this->access->setting('settings_viewall',1)) ||
				!$this->access->allows(0,$route)) { continue; }
			$params = $route === 'search' && $category ? array('cat_id'=>$category) : array();
			$this->template->assign_var('U_DL_' . $link,$this->helper->route('phpbb2plus_downloads_' . $route,$params));
		}
		if (!$category) { return; }
		$path = array();
		for ($id=$category; $id; $id=(int) $cats[$id]['parent_id']) { $path[]=$id; }
		foreach (array_reverse($path) as $id)
		{
			$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>utf8_htmlspecialchars(access::plain($cats[$id]['title'])),
				'U_VIEW_FORUM'=>$this->helper->route('phpbb2plus_downloads_category',array('category'=>$id))));
		}
	}

	public function sort($method,$direction)
	{
		$default = $this->access->setting('sort_method','file_time');
		if ($default === 'rating') { $default='file_rating'; }
		if ($method === 'rating') { $method='file_rating'; }
		if (!isset(settings::SORT[$method])) { $method=isset(settings::SORT[$default]) ? $default : 'file_time'; }
		if (!in_array($direction,array('ASC','DESC'),true)) { $direction=$this->access->setting('sort_order','DESC'); }
		return array('sort_method'=>$method,'sort_order'=>$direction === 'ASC' ? 'ASC' : 'DESC');
	}

	public function sort_controls($sort)
	{
		foreach (array('file_name'=>'PLUS_DL_TITLE','file_time'=>'PLUS_DL_DATE','file_rating'=>'PLUS_DL_RATING',
			'file_dls'=>'PLUS_DL_COUNT','file_update_time'=>'PLUS_DL_UPDATED') as $key=>$label)
		{
			$this->template->assign_block_vars('sorts',array('VALUE'=>$key,'LABEL'=>$this->language->lang($label),'SELECTED'=>$sort['sort_method']===$key));
		}
		$this->template->assign_var('DL_ASC',$sort['sort_order']==='ASC');
	}

	public function assign($rows)
	{
		$cats = $this->access->categories();
		$days = max(0,min(1000000,(int) $this->access->setting('settings_newdays',0)));
		$recent = time()-$days*86400;
		foreach ($rows as $row)
		{
			$cat = (int) $row['cat_id'];
			$preview = '';
			if (isset($row['preview_name']))
			{
				$preview = $row['preview_name'] !== '' ? $this->helper->route('phpbb2plus_downloads_preview',array('file'=>(int) $row['file_id'])) :
					utf8_htmlspecialchars(access::external((json_decode($row['source_json'],true) ?? array())['file_ssurl'] ?? ''));
			}
			$this->template->assign_block_vars('ranked',array('TITLE'=>access::plain($row['title']),'SUMMARY'=>access::plain($row['summary']),
				'PUBLISHER'=>!empty($row['user_id']) ? get_username_string('full',(int) $row['user_id'],$row['username'],$row['user_colour']) : $this->language->lang('GUEST'),
				'DATE'=>$this->user->format_date((int) $row['added_at']),'SCORE'=>$this->score($row['rating']),'COUNT'=>(int) $row['downloads'],
				'RECENT'=>$days && (int) $row['added_at']>$recent,'PINNED'=>(bool) $row['pinned'],'ICON'=>file_display::icon_url($row['icon_name'],$this->paths->get_web_root_path()),
				'PREVIEW'=>$preview,'CATEGORY'=>access::plain($cats[$cat]['title']),'CATEGORY_URL'=>$this->helper->route('phpbb2plus_downloads_category',array('category'=>$cat)),
				'URL'=>$this->helper->route('phpbb2plus_downloads_file',array('file'=>(int) $row['file_id']))));
		}
	}
}
