<?php
namespace phpbb2plus\weblinks\controller;

use phpbb\request\request_interface;
use phpbb2plus\weblinks\service\directory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class pages
{
	private $directory;
	private $notices;
	private $request;
	private $user;
	private $language;
	private $config;
	private $template;
	private $helper;
	private $pagination;
	private $root;

	public function __construct($directory, $notices, $request, $user, $language, $config, $template, $helper, $pagination, $root)
	{
		$this->directory = $directory; $this->notices = $notices; $this->request = $request; $this->user = $user;
		$this->language = $language; $this->config = $config; $this->template = $template; $this->helper = $helper;
		$this->pagination = $pagination; $this->root = $root;
	}

	private function page($name, $title)
	{
		$response = $this->helper->render('@phpbb2plus_weblinks/' . $name . '.html',$this->language->lang($title));
		$response->headers->set('Cache-Control','private, no-store');
		return $response;
	}
	private function home($params = array()) { return $this->helper->route('phpbb2plus_weblinks_home',$params); }
	private function image($row)
	{
		if ($row['local_logo'] === 'unavailable') { return ''; }
		if (!empty($row['local_logo'])) { return $this->helper->route('phpbb2plus_weblinks_logo',array('id'=>$row['link_id']),false); }
		return directory::external(directory::plain($row['logo']));
	}

	public function index()
	{
		$view = $this->request->variable('t','index');
		if (!in_array($view,array('index','new','pop','sub_pages','search'),true)) { $view = 'index'; }
		$cat = $this->request->variable('cat',1);
		$sort = $this->request->variable('mode','link_joined');
		if (!isset(directory::SORTS[$sort])) { $sort = 'link_joined'; }
		$order = $this->request->variable('order','DESC') === 'ASC' ? 'ASC' : 'DESC';
		$query = utf8_substr(htmlspecialchars_decode($this->request->variable('search_keywords','',true),ENT_QUOTES),0,100);
		$categories = array();
		foreach ($this->directory->categories() as $row)
		{
			$id = (int) $row['cat_id'];
			$categories[$id] = directory::plain($row['title']);
			$this->template->assign_block_vars('wl_categories',array('ID'=>$id,'TITLE'=>$categories[$id],'COUNT'=>(int) $row['amount'],
				'URL'=>$this->home(array('t'=>'sub_pages','cat'=>$id))));
		}
		if ($view === 'sub_pages' && !isset($categories[$cat])) { throw new NotFoundHttpException($this->language->lang('WL_BAD_CATEGORY')); }
		$heading = $view === 'sub_pages' ? $categories[$cat] : $this->language->lang('WL_' . strtoupper($view));
		if ($view !== 'index')
		{
			list($total,$start,$rows) = $this->directory->listing($view,$cat,$sort,$order,$query,$this->request->variable('start',0));
			$params = array('t'=>$view);
			if ($view === 'search') { $params['search_keywords'] = $query; }
			if ($view === 'sub_pages') { $params += array('cat'=>$cat,'mode'=>$sort,'order'=>$order); }
			$this->pagination->generate_template_pagination($this->home($params),'pagination','start',$total,
				max(1,min(100,(int) $this->config['plus_wl_linkspp'])),$start);
			foreach ($rows as $row)
			{
				$id = (int) $row['link_id'];
				$author = $this->language->lang((int) $row['source_user']>0 || (int) $row['user_id']>ANONYMOUS ? 'WL_FORMER' : 'GUEST');
				if ((int) $row['user_id']>ANONYMOUS && isset($row['username']))
				{ $author = get_username_string('full',(int) $row['user_id'],$row['username'],$row['user_colour']); }
				$this->template->assign_block_vars('wl_links',array('ID'=>$id,'TITLE'=>directory::plain($row['title']),
					'DESCRIPTION'=>directory::plain($row['description']),'CATEGORY'=>$categories[$row['cat_id']] ?? '',
					'LOGO'=>$this->config['plus_wl_display_links_logo'] ? $this->image($row) : '', 'AUTHOR'=>$author,
					'JOINED'=>$this->user->format_date((int) $row['joined']), 'HITS'=>$row['hits'],
					'URL'=>$this->helper->route('phpbb2plus_weblinks_visit',array('id'=>$id))));
			}
		}
		$site = directory::external(directory::plain($this->config['plus_wl_site_url']));
		$site_logo = $this->image(array('link_id'=>0,'local_logo'=>$this->config['plus_wl_local_logo'],'logo'=>$this->config['plus_wl_site_logo']));
		if ($site_logo && $this->config['plus_wl_local_logo'])
		{ $site_logo = $this->helper->route('phpbb2plus_weblinks_logo',array('id'=>0),false,'',\Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL); }
		$width = (int) $this->config['plus_wl_width']; $height = (int) $this->config['plus_wl_height'];
		$markup = '';
		if ($site && $site_logo)
		{
			$markup = '<a href="' . utf8_htmlspecialchars($site) . '"><img src="' . utf8_htmlspecialchars($site_logo) . '" width="' .
				$width . '" height="' . $height . '" alt="' . utf8_htmlspecialchars(htmlspecialchars_decode($this->config['sitename'],ENT_QUOTES)) . '"></a>';
		}
		$this->template->assign_vars(array('WL_VIEW'=>$view,'WL_HEADING'=>$heading,'WL_CAT'=>$cat,'WL_SORT'=>$sort,'WL_ORDER'=>$order,
			'WL_QUERY'=>$query,'WL_WIDTH'=>$width,'WL_HEIGHT'=>$height,'WL_MARKUP'=>$markup,'U_WL'=>$this->home(),
			'U_WL_NEW'=>$this->home(array('t'=>'new')),'U_WL_POP'=>$this->home(array('t'=>'pop')),'U_WL_SEARCH'=>$this->home(array('t'=>'search')),
			'U_WL_SUBMIT'=>($this->directory->may_submit() || (!$this->user->data['is_registered'] && !$this->config['plus_wl_lock_submit_site'])) ?
				$this->helper->route('phpbb2plus_weblinks_submit') : ''));
		return $this->page('directory','WL_TITLE');
	}

	public function submit()
	{
		$url = $this->helper->route('phpbb2plus_weblinks_submit',array(),false);
		if (!$this->user->data['is_registered']) { login_box($url); }
		if (!$this->directory->may_submit()) { throw new AccessDeniedHttpException($this->language->lang('WL_LOCKED')); }
		$data = array('title'=>'','description'=>'','destination'=>'','logo'=>'','cat_id'=>0);
		$error = '';
		$preview = '';
		if ($this->request->is_set_post('submit') || $this->request->is_set_post('preview'))
		{
			if (!check_form_key('plus_wl_submit')) { throw new AccessDeniedHttpException($this->language->lang('FORM_INVALID')); }
			foreach ($data as $key=>$default)
			{
				$value = $this->request->variable($key,$default,is_string($default),request_interface::POST);
				$data[$key] = is_string($value) ? htmlspecialchars_decode($value,ENT_QUOTES) : $value;
			}
			if ($this->request->is_set_post('preview'))
			{
				$preview = directory::external(trim($data['logo']));
				if ($preview === '' || strlen($preview)>120) { $preview = ''; $error = $this->language->lang('WL_INVALID'); }
			}
			else
			{
				try
				{
					list($id,$active) = $this->directory->submit($data);
					$notified = $active || $this->notices->pending($id);
					$this->template->assign_vars(array('WL_DONE'=>$this->language->lang($active ? 'WL_PUBLISHED' : 'WL_SUBMITTED'),
						'WL_NOTICE_ERROR'=>!$notified,'U_WL'=>$this->home()));
					return $this->page('submit','WL_SUBMIT');
				}
				catch (\DomainException $e) { $error = $this->language->lang($e->getMessage()); }
			}
		}
		foreach ($this->directory->categories() as $cat)
		{
			$this->template->assign_block_vars('wl_categories',array('ID'=>(int) $cat['cat_id'],'TITLE'=>directory::plain($cat['title']),
				'SELECTED'=>(int) $cat['cat_id'] === $data['cat_id']));
		}
		$this->template->assign_vars(array('WL_FORM'=>$data,'WL_ERROR'=>$error,'WL_PREVIEW'=>$preview,'U_WL'=>$this->home(),'U_WL_SUBMIT'=>$url,
			'WL_LOGO_REQUIRED'=>!$this->config['plus_wl_allow_no_logo']));
		add_form_key('plus_wl_submit');
		return $this->page('submit','WL_SUBMIT');
	}

	public function visit($id)
	{
		$url = $this->directory->visit($id,$this->request->server('REQUEST_METHOD','GET') === 'GET');
		if (!$url) { throw new NotFoundHttpException($this->language->lang('WL_UNAVAILABLE')); }
		return new RedirectResponse($url,302,array('Referrer-Policy'=>'no-referrer','Cache-Control'=>'private, no-store'));
	}

	public function logo($id)
	{
		$file = $this->directory->local_logo((int) $id);
		if (!preg_match('/^weblink_[a-f0-9]{64}\.(gif|png|jpg|webp)$/D',$file,$m)) { throw new NotFoundHttpException(); }
		$path = $this->root . trim($this->config['upload_path'],'/') . '/' . $file;
		if (!is_file($path) || is_link($path)) { throw new NotFoundHttpException(); }
		return new BinaryFileResponse($path,200,array('Content-Type'=>'image/' . ($m[1] === 'jpg' ? 'jpeg' : $m[1]),
			'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store'),false);
	}
}
