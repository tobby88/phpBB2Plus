<?php
namespace phpbb2plus\downloads\controller;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\access;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class catalogue
{
	private $access;
	private $data;
	private $listing;
	private $db;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $pagination;

	public function __construct($access,$data,$listing,$db,$user,$language,$template,$helper,$request,$pagination)
	{
		$this->access=$access;
		$this->data=$data;
		$this->listing=$listing;
		$this->db=$db;
		$this->user=$user;
		$this->language=$language;
		$this->template=$template;
		$this->helper=$helper;
		$this->request=$request;
		$this->pagination=$pagination;
	}

	private function input($key,$default)
	{
		$query = $this->request->variable($key,$default,false,request_interface::GET);
		return $this->request->variable($key,$query,false,request_interface::POST);
	}

	private function directory($children,$branches,$parent)
	{
		if (empty($children[$parent])) { return; }
		$totals = $latest = array();
		foreach ($this->data->directory_totals(array_keys($branches)) as $row)
		{
			$folder = $branches[(int) $row['cat_id']];
			$totals[$folder] = ($totals[$folder] ?? 0) + (int) $row['total'];
			if (!isset($latest[$folder]) || array((int) $row['added_at'],(int) $row['file_id']) >
				array((int) $latest[$folder]['added_at'],(int) $latest[$folder]['file_id'])) { $latest[$folder]=$row; }
		}
		$cats = $this->access->categories();
		$days = max(0,min(1000000,(int) $this->access->setting('settings_newdays',0)));
		$cutoff = time()-$days*86400;
		foreach ($children[$parent] as $folder)
		{
			$file = $latest[$folder] ?? null;
			$this->template->assign_block_vars('dl_cats',array('TITLE'=>access::plain($cats[$folder]['title']),'SUMMARY'=>access::plain($cats[$folder]['summary']),
				'URL'=>$this->helper->route('phpbb2plus_downloads_category',array('category'=>$folder)),'COUNT'=>$totals[$folder] ?? 0,
				'LAST'=>$file ? access::plain($file['title']) : '','RECENT'=>$file && $days && (int) $file['added_at']>$cutoff,
				'DATE'=>$file ? $this->user->format_date((int) $file['added_at']) : '',
				'LAST_URL'=>$file ? $this->helper->route('phpbb2plus_downloads_file',array('file'=>(int) $file['file_id'])) : ''));
			foreach ($children[$folder] ?? array() as $child)
			{
				$this->template->assign_block_vars('dl_cats.children',array('TITLE'=>access::plain($cats[$child]['title']),
					'URL'=>$this->helper->route('phpbb2plus_downloads_category',array('category'=>$child))));
			}
		}
	}

	private function files($ids,$query,$sort,$route,$params,$offset)
	{
		if (!$ids) { return; }
		$where = 'f.approved=1 AND ' . $this->db->sql_in_set('f.cat_id',$ids);
		if ($query !== '')
		{
			$pattern = $this->db->sql_like_expression($this->db->get_any_char() . utf8_encode_ucr($query) . $this->db->get_any_char());
			$where .= " AND (f.title $pattern OR f.summary $pattern)";
		}
		// Folder and all-files pages repeat pins. Quick search counts each match once.
		$normal = $query === '' ? $where . ' AND f.pinned=0' : $where;
		$total = $this->data->count($normal);
		$limit = max(1,min(100,(int) $this->access->setting('settings_file_page',20)));
		$start = min(intdiv(max(0,$total-1),$limit),intdiv($offset,$limit))*$limit;
		$this->template->assign_var('DL_PAGE',$this->pagination->on_page($total,$limit,$start));
		$this->pagination->generate_template_pagination($this->helper->route($route,$params+$sort),'pagination','start',$total,$limit,$start);
		if ($query === '') { $this->listing->assign($this->data->files($where . ' AND f.pinned<>0',$sort['sort_method'],0,0,$sort['sort_order'],'catalogue')); }
		if ($total)
		{
			$this->listing->assign($this->data->files($normal,$sort['sort_method'],$limit,$start,$sort['sort_order'],$query === '' ? 'catalogue' : 'quick_search'));
		}
		$this->listing->sort_controls($sort);
	}

	public function view($category = 0,$all = false)
	{
		$this->access->open();
		$this->language->add_lang('downloads','phpbb2plus/downloads');
		$category = (int) $category;
		$route = $all ? 'phpbb2plus_downloads_viewall' : ($category ? 'phpbb2plus_downloads_category' : 'phpbb2plus_downloads_index');
		$params = $category ? array('category'=>$category) : array();
		$url = $this->helper->route($route,$params);
		$sort = $this->listing->sort($this->input('sort_method',''),$this->input('sort_order',''));
		$start = max(0,$this->input('start',0));
		if ($all)
		{
			if (!(int) $this->access->setting('settings_viewall',1)) { throw new \phpbb\exception\http_exception(404,'PLUS_DL_VIEWALL_DISABLED'); }
			if (!$this->access->allows(0,'viewall'))
			{
				if (empty($this->user->data['is_registered'])) { login_box($this->helper->route($route,$sort+array('start'=>$start),false)); }
				throw new AccessDeniedHttpException();
			}
		}
		$this->listing->navigation($all ? 'viewall' : ($category ? 'category' : ''),$category);
		$query = $all ? '' : trim(htmlspecialchars_decode($this->request->variable('q','',true),ENT_QUOTES));
		$search = $this->access->allows(0,'search');
		if ($query !== '' && !$search) { throw new NotFoundHttpException(); }
		if ($query !== '') { $params['q']=$query; }
		// Old sort forms POST to this view. Their result is a repeatable read-only bookmark.
		if ($this->request->server('REQUEST_METHOD')==='POST')
		{
			if ($start) { $params['start']=$start; }
			$response = new RedirectResponse($this->helper->route($route,$params+$sort,false),303);
			$response->headers->set('Cache-Control','private, no-store');
			return $response;
		}
		$cats = $this->access->categories();
		if ($all)
		{
			$title = $this->language->lang('PLUS_DL_VIEWALL');
			$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>$title,'U_VIEW_FORUM'=>$url));
		}
		else
		{
			$title = $category ? access::plain($cats[$category]['title']) : trim(access::plain($this->access->setting('settings_dbname','')));
			if ($title === '') { $title=$this->language->lang('PLUS_DL'); }
		}
		$this->template->assign_vars(array('DL_TITLE'=>$title,'S_DL_SEARCH'=>$search && !$all,'DL_QUERY'=>$query,'U_DL_SEARCH'=>$url,
			'U_DL_SORT'=>$url,'S_DL_CATALOGUE'=>true,'S_DL_SINGLE_CATEGORY'=>$category && $query===''));
		$children = $branches = $scope = array();
		foreach ($cats as $id=>$cat)
		{
			if (!$this->access->visible($id)) { continue; }
			$children[(int) $cat['parent_id']][] = $id;
			if (!$cat['allow_files'] || !$this->access->allows($id,'view_file')) { continue; }
			if ($all || $id===$category) { $scope[]=$id; continue; }
			// The first child below this directory owns the descendant's statistics.
			$branch = $id;
			while ((int) $cats[$branch]['parent_id'] !== $category)
			{
				$branch = (int) $cats[$branch]['parent_id'];
				if (!$branch) { break; }
			}
			if (!$branch) { continue; }
			$branches[$id]=$branch;
			if ($query!=='') { $scope[]=$id; }
		}
		if ($category && $this->access->can_review($category))
		{ $this->template->assign_var('U_DL_QUEUE',$this->helper->route('phpbb2plus_downloads_queue',array('category'=>$category))); }
		if (!$all && $query==='') { $this->directory($children,$branches,$category); }
		if ($all || $category || $query!=='') { $this->files($scope,$query,$sort,$route,$params,$start); }
		$response = $this->helper->render('@phpbb2plus_downloads/index.html',utf8_htmlspecialchars($title));
		$response->headers->set('Cache-Control','private, no-store');
		return $response;
	}
}
