<?php
namespace phpbb2plus\calendar\controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class board
{
	private $schedule;
	private $settings;
	private $request;
	private $user;
	private $language;
	private $helper;
	private $template;
	private $pagination;

	public function __construct($schedule, $settings, $request, $user, $language, $helper, $template, $pagination)
	{
		$this->schedule=$schedule; $this->settings=$settings; $this->request=$request; $this->user=$user;
		$this->language=$language; $this->helper=$helper; $this->template=$template; $this->pagination=$pagination;
	}

	private function link($view, $date, $forum, $slot = null)
	{
		$args = array('view'=>$view,'date'=>$date->format('Y-m-d'));
		if ($forum) { $args['forum'] = (int) $forum; }
		if ($slot !== null) { $args['moment'] = (int) $slot; }
		return $this->helper->route('phpbb2plus_calendar_home',$args);
	}

	private function selected($zone)
	{
		$value = $this->request->variable('date','');
		$moment = $this->request->variable('moment','');
		$stamp = null;
		if ($moment !== '')
		{
			if (!preg_match('/^[0-9]{1,10}$/D',$moment)) { throw new NotFoundHttpException(); }
			$stamp = (new \DateTimeImmutable('@' . $moment))->setTimezone($zone);
		}
		if ($value !== '')
		{
			$date = \DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);
			if (!$date || $date->format('Y-m-d') !== $value || $stamp && $stamp->format('Y-m-d') !== $value) { throw new NotFoundHttpException(); }
		}
		else { $date = $stamp ?: new \DateTimeImmutable('now',$zone); }
		if ((int) $date->format('Y') < 1971 || (int) $date->format('Y') > 2069) { throw new NotFoundHttpException(); }
		return array($date->setTime(0,0),$stamp);
	}

	private function records($from, $until, $forums)
	{
		yield from $this->schedule->birthdays($from,$until);
		yield from $this->schedule->topics((int) $from->format('U'),(int) $until->format('U'),$forums);
	}

	private function description($record)
	{
		if ($record['BIRTHDAY']) { return $this->language->lang('PLUS_CAL_BIRTHDAY') . ($record['AGE'] ? ' (' . $record['AGE'] . ')' : ''); }
		$from = $this->user->format_date($record['FROM'],false,true);
		return $from . ($record['UNTIL'] > $record['FROM'] ? ' – ' . $this->user->format_date($record['UNTIL'],false,true) : '');
	}

	private function month($day, $filter, $forums)
	{
		$first = $day->modify('first day of this month');
		$next = $first->modify('first day of next month');
		$week = $this->settings->get('week_start') ? 1 : 0;
		$cells = array();
		foreach (\phpbb2plus\calendar\service\period::month_cells($day, $week) as $key => $date)
		{
			$cells[$key] = array('date'=>$date,'count'=>0,'items'=>array());
		}
		$limit = $this->settings->get('nb_row');
		$this->fill($cells,$first,$next,$forums,$limit);
		$today = $this->user->create_datetime()->format('Y-m-d');
		$i = 0;
		foreach ($cells as $key => $cell)
		{
			$d = $cell['date'];
			if ($i < 7) { $this->template->assign_block_vars('cal_head',array('LABEL'=>$this->user->format_date($d->getTimestamp(),'D',true))); }
			if (($i++ % 7) === 0) { $this->template->assign_block_vars('cal_week',array()); }
			$url = (int) $d->format('Y') >= 1971 && (int) $d->format('Y') <= 2069 ? $this->link('day',$d,$filter) : '';
			$this->template->assign_block_vars('cal_week.day',array('DATE'=>$key,'NUM'=>(int)$d->format('j'),'URL'=>$url,
				'CURRENT'=>$key === $today,'IN_MONTH'=>$d >= $first && $d < $next, 'MORE'=>max(0,$cell['count']-$limit)));
			foreach ($cell['items'] as $entry) { $this->template->assign_block_vars('cal_week.day.event',$entry); }
		}
	}

	private function fill(&$cells, $first, $next, $forums, $limit)
	{
		$topics = array();
		foreach ($this->records($first,$next,$forums) as $record)
		{
			$start = max($record['FROM'],$first->getTimestamp());
			$date = $first->setTimestamp($start)->setTime(0,0);
			for (; $date < $next && $date->getTimestamp() <= $record['UNTIL']; $date=$date->modify('+1 day'))
			{
				$key = $date->format('Y-m-d');
				if (++$cells[$key]['count'] <= $limit)
				{
					$cells[$key]['items'][] = $record;
					if (!$record['BIRTHDAY']) { $topics[$record['TOPIC_ID']] = true; }
				}
			}
		}
		$previews = $this->schedule->previews(array_keys($topics),$forums);
		foreach ($cells as &$cell)
		{
			foreach ($cell['items'] as &$entry)
			{
				$id = $entry['TOPIC_ID'] ?? 0;
				$entry['PREVIEW'] = $previews[$id] ?? '';
				$entry['SHORT_TITLE'] = $id ? $this->schedule->excerpt($entry['TITLE'],$this->settings->get('title_length')) : $entry['TITLE'];
			}
			unset($entry);
		}
		unset($cell);
	}

	public function strip()
	{
		$days = $this->settings->get('header_cells');
		if (!$days) { throw new NotFoundHttpException(); }
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		list($day) = $this->selected($this->user->create_datetime()->getTimezone());
		$first = $day->modify('-1 day');
		if ((int) $first->format('Y') < 1971) { $first = $day; }
		$next = min($first->modify('+' . $days . ' days'),$first->setDate(2070,1,1));
		list(,$forums) = $this->schedule->scope(0,0);
		$cells = array();
		for ($date=$first; $date < $next; $date=$date->modify('+1 day'))
		{
			$cells[$date->format('Y-m-d')] = array('date'=>$date,'count'=>0,'items'=>array());
		}
		$limit = $this->settings->get('nb_row');
		$this->fill($cells,$first,$next,$forums,$limit);
		$today = $this->user->create_datetime()->format('Y-m-d');
		foreach ($cells as $key=>$cell)
		{
			$date = $cell['date'];
			$this->template->assign_block_vars('cal_strip',array('URL'=>$this->link('day',$date,0),'DATE'=>$key,
				'CURRENT'=>$key === $today,'LABEL'=>$this->user->format_date($date->getTimestamp(),'D, j M',true),'MORE'=>max(0,$cell['count']-$limit)));
			foreach ($cell['items'] as $entry) { $this->template->assign_block_vars('cal_strip.event',$entry); }
		}
		$this->template->set_filenames(array('plus_cal_header'=>'@phpbb2plus_calendar/strip.html'));
		return new Response($this->template->assign_display('plus_cal_header'),200,array('Cache-Control'=>'private, no-store'));
	}

	private function agenda($day, $moment, $view, $filter, $forums)
	{
		$from = $day;
		$until = $day->modify('+1 day');
		$end_of_day = $until->getTimestamp();
		$selected_slot = null;
		if ($view === 'hour')
		{
			$stamp = $moment ? (int) $moment->format('U') : $day->getTimestamp();
			$selected_slot = $day->getTimestamp() + intdiv($stamp - $day->getTimestamp(),3600) * 3600;
			$from = $day->setTimestamp($selected_slot);
			$until = $day->setTimestamp(min($end_of_day,$selected_slot+3600));
		}
		for ($stamp=$day->getTimestamp(); $stamp<$end_of_day; $stamp+=3600)
		{
			$this->template->assign_block_vars('cal_hours',array('URL'=>$this->link('hour',$day,$filter,$stamp),
				'LABEL'=>$this->user->format_date($stamp,'H:i T',true), 'SELECTED'=>$stamp===$selected_slot));
		}
		$start = max(0,$this->request->variable('start',0));
		$limit = 20;
		$count = 0;
		foreach ($this->records($from,$until,$forums) as $r)
		{
			if ($count >= $start && $count-$start < $limit)
			{
				$r['WHEN'] = $this->description($r);
				$this->template->assign_block_vars('cal_events',$r);
			}
			++$count;
		}
		$url = $this->link($view,$day,$filter,$selected_slot);
		$this->pagination->generate_template_pagination($url,'pagination','start',$count,$limit,$start);
		$this->template->assign_vars(array('CAL_TOTAL'=>$count,'U_CAL_DAY'=>$this->link('day',$day,$filter)));
	}

	public function show()
	{
		$this->language->add_lang('calendar','phpbb2plus/calendar');
		$view = $this->request->variable('view','month');
		if (!in_array($view,array('month','day','hour'),true)) { throw new NotFoundHttpException(); }
		list($day,$moment) = $this->selected($this->user->create_datetime()->getTimezone());
		list($filter,$forums,$choices) = $this->schedule->scope($this->request->variable('forum',0),$this->request->variable('old_cat',0));
		foreach ($choices as $id=>$f)
		{
			$this->template->assign_block_vars('cal_forums',array('ID'=>$id,'NAME'=>$f['forum_name'],'SELECTED'=>$id===$filter));
		}
		if ($view === 'month') { $this->month($day,$filter,$forums); } else { $this->agenda($day,$moment,$view,$filter,$forums); }
		$previous = $view === 'month' ? $day->modify('first day of previous month') : $day->modify('-1 day');
		$next = $view === 'month' ? $day->modify('first day of next month') : $day->modify('+1 day');
		$heading = $this->user->format_date($day->getTimestamp(),$view==='month' ? 'F Y' : 'l, j F Y',true);
		$this->template->assign_vars(array('S_PLUS_CALENDAR_PAGE'=>true,'CAL_MONTH'=>$view==='month','CAL_DATE'=>$day->format('Y-m-d'),
			'CAL_TITLE'=>$heading,'CAL_VIEW'=>$view,'U_CAL_HOME'=>$this->helper->route('phpbb2plus_calendar_home'),
			'U_CAL_MONTH'=>$this->link('month',$day,$filter),
			'U_CAL_PREV'=>(int)$previous->format('Y')>=1971 ? $this->link($view==='month'?'month':'day',$previous,$filter) : '',
			'U_CAL_NEXT'=>(int)$next->format('Y')<=2069 ? $this->link($view==='month'?'month':'day',$next,$filter) : ''));
		return $this->helper->render('@phpbb2plus_calendar/calendar.html',$heading . ' – ' . $this->language->lang('PLUS_CALENDAR'));
	}
}
