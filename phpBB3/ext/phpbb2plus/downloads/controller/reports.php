<?php
namespace phpbb2plus\downloads\controller;

use phpbb2plus\downloads\service\access;
use phpbb2plus\downloads\service\client_family;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class reports
{
	private $data;
	private $access;
	private $user;
	private $language;
	private $template;
	private $helper;
	private $request;
	private $pagination;
	private $listing;

	public function __construct($data,$access,$user,$language,$template,$helper,$request,$pagination,$listing)
	{
		$this->data = $data;
		$this->access = $access;
		$this->user = $user;
		$this->language = $language;
		$this->template = $template;
		$this->helper = $helper;
		$this->request = $request;
		$this->pagination = $pagination;
		$this->listing = $listing;
	}

	private function header($view,$params = array())
	{
		$this->access->open();
		$this->language->add_lang('downloads','phpbb2plus/downloads');
		$route = 'phpbb2plus_downloads_' . $view;
		if (!$this->access->allows(0,$view))
		{
			if (empty($this->user->data['is_registered']))
			{
				$start = $this->request->variable('start',0);
				if ($view === 'toplist' && $start>0) { $params['start'] = $start; }
				login_box($this->helper->route($route,$params,false));
			}
			throw new AccessDeniedHttpException();
		}
		$this->listing->navigation($view);
		$this->template->assign_block_vars('navlinks',array('FORUM_NAME'=>$this->language->lang('PLUS_DL_' . strtoupper($view)),
			'U_VIEW_FORUM'=>$this->helper->route($route,$params)));
		return $this->data->scope();
	}

	private function render($view)
	{
		$response = $this->helper->render('@phpbb2plus_downloads/' . $view . '.html',$this->language->lang('PLUS_DL_' . strtoupper($view)));
		$response->headers->set('Cache-Control','private, no-store');
		return $response;
	}

	public function stats()
	{
		$scope = $this->header('stats');
		list($summary,$records,$clients) = $this->data->statistics($scope);
		$summary['categories'] = $scope['categories'];
		foreach (array('files','categories','downloads','mean_downloads','mean_rating') as $name)
		{
			$value = (int) round((float) $summary[$name]);
			$this->template->assign_block_vars('figures',array('NAME'=>$name,'LABEL'=>$this->language->lang('PLUS_DL_TOTAL_' . strtoupper($name)),
				'VALUE'=>$name === 'mean_rating' && $summary[$name] !== null ? $value . ' / 10' : $value));
		}
		foreach ($records as $key=>$row)
		{
			$detail = '';
			if ($row)
			{
				if (isset($row['rating'])) { $detail = $this->listing->score($row['rating']); }
				elseif (in_array($key,array('newest','oldest'),true)) { $detail = $this->user->format_date((int) $row['added_at']); }
				else { $detail = (string) $row['downloads']; }
			}
			$this->template->assign_block_vars('records',array('NAME'=>$key,'LABEL'=>$this->language->lang('PLUS_DL_RECORD_' . strtoupper($key)),
				'TITLE'=>$row ? access::plain($row['title']) : '','DETAIL'=>$detail,'URL'=>$row ? $this->helper->route('phpbb2plus_downloads_file',array('file'=>(int) $row['file_id'])) : ''));
		}
		foreach (array('os'=>client_family::OS,'browser'=>client_family::BROWSERS) as $type=>$labels)
		{
			$this->template->assign_block_vars('clients',array('LABEL'=>$this->language->lang('PLUS_DL_CLIENT_' . strtoupper($type))));
			foreach ($labels as $family=>$label)
			{
				$this->template->assign_block_vars('clients.rows',array('NAME'=>$family,'LABEL'=>$label ?: $this->language->lang('PLUS_DL_OTHER'),
					'VISITORS'=>$clients['visitors'][$type][$family],'VOTES'=>$clients['votes'][$type][$family]));
			}
		}
		return $this->render('stats');
	}

	private function top_url($params) { return $this->helper->route('phpbb2plus_downloads_toplist',$params); }

	public function toplist()
	{
		$mode = $this->request->variable('mode','newest');
		if (!in_array($mode,array('newest','downloads','rating'),true)) { $mode = 'newest'; }
		$params = array('mode'=>$mode);
		if ($mode === 'newest')
		{
			$params['days'] = max(1,min(365,$this->request->variable('days',7)));
			$params['selected_date'] = max(0,min(4294967295,$this->request->variable('selected_date',0)));
		}
		else
		{
			$default = (int) $this->access->setting('settings_topnumber',10);
			$params['most_num'] = max(1,min(1000,$this->request->variable('most_num',$default > 0 ? $default : 10)));
			$params['most_type'] = $this->request->variable('most_type','num') === 'per' ? 'per' : 'num';
		}
		$scope = $this->header('toplist',$params);
		$where = $scope['where'];
		foreach (array('newest','downloads','rating') as $tab)
		{
			$this->template->assign_block_vars('modes',array('LABEL'=>$this->language->lang('PLUS_DL_MODE_' . strtoupper($tab)),
				'CURRENT'=>$tab === $mode,'URL'=>$this->top_url(array('mode'=>$tab))));
		}
		if ($mode === 'newest')
		{
			$zone = $this->user->create_datetime()->getTimezone();
			$today = new \DateTimeImmutable('today',$zone);
			$totals = $this->data->recent_totals($where,time());
			$this->template->assign_vars(array('DL_RECENT'=>true,'DL_WEEK'=>(int) $totals['week'],'DL_MONTH'=>(int) $totals['month']));
			foreach (array(7,14,30) as $days)
			{
				$this->template->assign_block_vars('periods',array('LABEL'=>$this->language->lang('PLUS_DL_DAYS',$days),'URL'=>$this->top_url(array('mode'=>$mode,'days'=>$days))));
			}
			if (!$params['selected_date'])
			{
				$counts = $this->data->day_counts($where,$today,$params['days']);
				for ($i=0,$day=$today; $i<$params['days']; ++$i,$day=$day->modify('-1 day'))
				{
					$stamp = $day->getTimestamp();
					$this->template->assign_block_vars('days',array('DATE'=>$this->user->format_date($stamp,'d M Y',true),
						'COUNT'=>$counts[$day->format('Y-m-d')] ?? 0,'URL'=>$this->top_url(array('selected_date'=>$stamp)+$params)));
				}
				return $this->render('toplist');
			}
			$day = (new \DateTimeImmutable('@' . $params['selected_date']))->setTimezone($zone)->setTime(0,0);
			$where .= ' AND f.added_at>=' . $day->getTimestamp() . ' AND f.added_at<' . $day->modify('+1 day')->getTimestamp();
			$total = $this->data->count($where);
			$this->template->assign_var('DL_CAPTION',$this->user->format_date($day->getTimestamp(),'d M Y',true));
		}
		else
		{
			if ($mode === 'rating') { $where .= $this->data->rated_filter(); }
			$available = $this->data->count($where);
			$number = $params['most_num'];
			$percent = $params['most_type'] === 'per';
			$total = min($available,$percent ? max(1,(int) round($available*min(100,$number)/100)) : $number);
			$this->template->assign_var('DL_CAPTION',$this->language->lang($percent ? 'PLUS_DL_TOP_PERCENT' : 'PLUS_DL_TOP_NUMBER',$number,$available));
			foreach (array('num'=>array(10,25,50),'per'=>array(1,5,10)) as $type=>$choices)
			{
				foreach ($choices as $choice)
				{
					$this->template->assign_block_vars('limits',array('LABEL'=>$type === 'per' ? $choice . '%' : $choice,
						'URL'=>$this->top_url(array('mode'=>$mode,'most_type'=>$type,'most_num'=>$choice))));
				}
			}
		}
		$this->template->assign_var('DL_FILE_LIST',true);
		$limit = max(1,min(100,(int) $this->access->setting('settings_file_page',20)));
		$start = $this->pagination->validate_start(max(0,$this->request->variable('start',0)),$limit,$total);
		$this->pagination->generate_template_pagination($this->top_url($params),'pagination','start',$total,$limit,$start);
		if ($total) { $this->listing->assign($this->data->files($where,$mode,min($total-$start,$limit),$start)); }
		return $this->render('toplist');
	}
}
