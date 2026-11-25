<?php
namespace phpbb2plus\downloads\controller;

use phpbb\request\request_interface;
use phpbb2plus\downloads\service\file_search;
use phpbb2plus\downloads\service\settings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class search
{
	private $search;
	private $saved;
	private $data;
	private $listing;
	private $access;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $pagination;

	public function __construct($search,$saved,$data,$listing,$access,$user,$language,$template,$helper,$request,$pagination)
	{
		$this->search=$search;
		$this->saved=$saved;
		$this->data=$data;
		$this->listing=$listing;
		$this->access=$access;
		$this->user=$user;
		$this->language=$language;
		$this->template=$template;
		$this->helper=$helper;
		$this->request=$request;
		$this->pagination=$pagination;
	}

	private function input($name,$fallback)
	{
		$get = $this->request->raw_variable($name,$fallback,request_interface::GET);
		$value = $this->request->raw_variable($name,$get,request_interface::POST);
		return is_scalar($value) ? (string) $value : (string) $fallback;
	}

	private function criteria()
	{
		$default = $this->access->setting('sort_method','file_time');
		if (!isset(settings::SORT[$default])) { $default='file_time'; }
		$sort = $this->input('sort_method',$default);
		if ($sort==='rating') { $sort='file_rating'; }
		$keywords = file_search::bounded($this->input('search_keywords',''),500);
		$author = file_search::bounded($this->input('search_author',''),100);
		return array('search_keywords'=>$keywords,'search_author'=>$author,'search_terms'=>$this->input('search_terms','any')==='all' ? 'all' : 'any',
			'cat_id'=>max(0,(int) $this->input('cat_id',0)),'comments_search'=>$this->input('comments_search',$keywords==='' && $author==='' ? 'YES' : 'NO')==='YES' ? 'YES' : 'NO',
			'sort_method'=>isset(settings::SORT[$sort]) ? $sort : $default,'sort_order'=>$this->input('sort_order',$this->access->setting('sort_order','DESC'))==='ASC' ? 'ASC' : 'DESC');
	}

	private function private_response($response)
	{
		$response->headers->set('Cache-Control','private, no-store');
		$response->headers->set('Referrer-Policy','no-referrer');
		return $response;
	}

	public function view()
	{
		$this->access->open();
		$this->language->add_lang('downloads','phpbb2plus/downloads');
		$route = 'phpbb2plus_downloads_search';
		$url = $this->helper->route($route);
		if (!$this->access->allows(0,'search'))
		{
			if (empty($this->user->data['is_registered'])) { login_box($this->helper->route($route,array(),false)); }
			throw new AccessDeniedHttpException();
		}
		$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>$this->language->lang('PLUS_DL'),'U_VIEW_FORUM'=>$this->helper->route('phpbb2plus_downloads_index')));
		$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>$this->language->lang('PLUS_DL_ADVANCED'),'U_VIEW_FORUM'=>$url));
		foreach (array('stats','toplist') as $name)
		{
			if ($this->access->allows(0,$name)) { $this->template->assign_var('U_DL_' . strtoupper($name),$this->helper->route('phpbb2plus_downloads_' . $name)); }
		}
		$criteria = $this->criteria();
		$id = max(0,(int) $this->input('search_id',0));
		$fresh = $criteria['search_keywords']!=='' || $criteria['search_author']!=='';
		$snapshot = !$fresh && $id ? $this->saved->recall($id) : null;
		if ($snapshot) { $criteria = $snapshot['criteria']; }
		list($menu,$files,$comments) = $this->search->categories($criteria['cat_id']);
		foreach ($menu as $key=>$name)
		{
			$this->template->assign_block_vars('categories',array('ID'=>$key,'TITLE'=>$name,'SELECTED'=>$criteria['cat_id']===$key));
		}
		foreach (array('file_name'=>'PLUS_DL_TITLE','file_time'=>'PLUS_DL_DATE','file_rating'=>'PLUS_DL_RATING','file_dls'=>'PLUS_DL_COUNT','file_update_time'=>'PLUS_DL_UPDATED') as $key=>$label)
		{
			$this->template->assign_block_vars('sorts',array('VALUE'=>$key,'LABEL'=>$this->language->lang($label),'SELECTED'=>$criteria['sort_method']===$key));
		}
		$this->template->assign_vars(array('U_DL_FORM'=>$url,'DL_KEYWORDS'=>$criteria['search_keywords'],'DL_AUTHOR'=>$criteria['search_author'],
			'DL_ALL'=>$criteria['search_terms']==='all','DL_COMMENTS'=>$criteria['comments_search']==='YES','DL_ASC'=>$criteria['sort_order']==='ASC'));
		if ($fresh || $snapshot)
		{
			$where = $this->search->filter($criteria,$files,$comments);
			if ($snapshot)
			{
				$where .= ' AND ' . $this->search->candidates($snapshot['ids']);
				$total = $this->data->count($where);
			}
			else
			{
				$ids = $this->search->ids($where);
				$total = count($ids);
				$where .= ' AND ' . $this->search->candidates($ids);
			}
			if ($fresh && $total && $this->request->server('REQUEST_METHOD')!=='HEAD')
			{
				$id = $this->saved->remember($criteria,$ids);
				if ($id && $this->request->server('REQUEST_METHOD')==='POST')
				{
					$params = array('search_id'=>$id);
					$start = (int) $this->input('start',0);
					if ($start>0) { $params['start']=$start; }
					return $this->private_response(new RedirectResponse($this->helper->route($route,$params,false),303));
				}
			}
			$this->template->assign_var('DL_MATCHES',$this->language->lang('PLUS_DL_MATCHES',$total));
			if ($total)
			{
				$limit = max(1,min(100,(int) $this->access->setting('settings_file_page',20)));
				$start = $this->pagination->validate_start(max(0,(int) $this->input('start',0)),$limit,$total);
				if ($id) { $this->pagination->generate_template_pagination($this->helper->route($route,array('search_id'=>$id)),'pagination','start',$total,$limit,$start); }
				// Recheck visibility and comment-only matches when loading the actual page.
				$this->listing->assign($this->data->files($where,$criteria['sort_method'],$limit,$start,$criteria['sort_order']));
			}
		}
		elseif ($id) { $this->template->assign_var('DL_EXPIRED',true); }
		return $this->private_response($this->helper->render('@phpbb2plus_downloads/search.html',$this->language->lang('PLUS_DL_ADVANCED')));
	}
}
