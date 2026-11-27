<?php
namespace phpbb2plus\calendar\event;

use phpbb2plus\calendar\service\period;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class portal implements EventSubscriberInterface
{
	private $schedule;
	private $request;
	private $user;
	private $auth;
	private $config;
	private $language;
	private $helper;
	private $template;
	private $extensions;
	private $root;
	private $ext;

	public function __construct($schedule, $request, $user, $auth, $config, $language, $helper, $template, $extensions, $root, $ext)
	{
		$this->schedule=$schedule; $this->request=$request; $this->user=$user; $this->auth=$auth; $this->config=$config;
		$this->language=$language; $this->helper=$helper; $this->template=$template; $this->extensions=$extensions; $this->root=$root; $this->ext=$ext;
	}

	public static function getSubscribedEvents()
	{
		return array('phpbb2plus.community.portal_content' => 'calendar', 'core.index_modify_birthdays_sql' => 'birthday_query',
			'core.index_modify_page_title' => array('forum_birthdays', -10), 'core.viewforum_modify_page_title' => array('forum_birthdays', -10),
			'phpbb2plus.community.announcement_columns' => 'announcement_columns', 'phpbb2plus.community.announcement_topic_row' => 'announcement_date');
	}

	public function announcement_columns($event)
	{
		// A CLI transition can leave the previous web container loaded after the calendar was disabled or purged.
		if (!$this->extensions->is_enabled('phpbb2plus/calendar') || !isset($this->config['plus_cal_nb_row'])) { return; }
		$event['columns'] = array_merge($event['columns'], array('t.plus_cal_start','t.plus_cal_end'));
	}

	public function announcement_date($event)
	{
		$row = $event['row'];
		if (empty($row['plus_cal_start'])) { return; }
		$start = (int) $row['plus_cal_start'];
		$end = max($start, (int) $row['plus_cal_end']);
		$values = period::values($start, $end, $this->user->create_datetime()->getTimezone());
		$format = $values['start_time'] === '' ? 'j M Y' : false;
		$when = $this->user->format_date($start, $format, true);
		if ($end > $start && ($values['start_time'] !== '' || $values['start_date'] !== $values['end_date']))
		{ $when .= ' – ' . $this->user->format_date($end, $format, true); }
		$this->language->add_lang('calendar', 'phpbb2plus/calendar');
		$display = $event['topic_row'];
		$display['CALENDAR_WHEN'] = $when;
		$date = $values['start_date'];
		if ($date >= '1971-01-01' && $date <= '2069-12-31')
		{
			$display['U_CALENDAR_DAY'] = $this->helper->route('phpbb2plus_calendar_home',
				array('view'=>'day','date'=>$date,'forum'=>(int) $row['forum_id']));
		}
		$event['topic_row'] = $display;
	}

	public function birthday_query($event)
	{
		// The Plus index reads today and the configured upcoming days together, including leap-day birthdays.
		if (!$this->template->retrieve_var('S_PLUS_BOARD_ACTIVITY') || !isset($this->config['plus_cal_birthday_days'])) { return; }
		$sql = $event['sql_ary']; $sql['WHERE'] = '1=0'; $event['sql_ary'] = $sql;
	}

	public function forum_birthdays()
	{
		if (!$this->template->retrieve_var('S_PLUS_BOARD_ACTIVITY') || !isset($this->config['plus_cal_birthday_days'])) { return; }
		$days = max(0, min(366, (int) $this->config['plus_cal_birthday_days']));
		$show = $days && $this->config['load_birthdays'] && $this->config['allow_birthdays'] && !empty($this->user->data['is_registered']) &&
			$this->auth->acl_gets('u_viewprofile', 'a_user', 'a_useradd', 'a_userdel');
		$this->template->assign_var('S_DISPLAY_BIRTHDAY_LIST', $show);
		if (!$show) { return; }
		$this->language->add_lang('calendar', 'phpbb2plus/calendar');
		$this->template->assign_var('PLUS_BOARD_BIRTHDAY_DAYS', $days);
		$today = new \DateTimeImmutable('today', $this->user->create_datetime()->getTimezone());
		$until = $today->modify('+' . ($days === 1 ? 1 : $days + 1) . ' days');
		$seen = array();
		foreach ($this->schedule->birthdays($today, $until, true) as $birthday)
		{
			$id = $birthday['USER_ID'];
			if (isset($seen[$id])) { continue; }
			$seen[$id] = true;
			$this->template->assign_block_vars($birthday['FROM'] === $today->getTimestamp() ? 'birthdays' : 'plus_upcoming_birthdays', array(
				'USERNAME' => get_username_string('full', $id, $birthday['TITLE'], $birthday['COLOUR']), 'AGE' => $birthday['AGE'] ?? '',
				'DATE' => $this->user->format_date($birthday['FROM'], 'j M', true)));
		}
	}

	private function portal_month($month)
	{
		return $this->helper->route('phpbb2plus_community_portal', array('cal_month' => $month->format('Y-m'))) . '#plus-mini-calendar';
	}

	public function calendar($event)
	{
		$this->language->add_lang('calendar', 'phpbb2plus/calendar');
		$zone = $this->user->create_datetime()->getTimezone();
		$today = new \DateTimeImmutable('today', $zone);
		$first = $today->modify('first day of this month');
		$month = $this->request->variable('cal_month', '');
		if ($month !== '' && preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $month) && substr($month,0,4) >= 1971 && substr($month,0,4) <= 2069)
		{ $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $zone); }
		elseif ($month === '' && ($offset = $this->request->variable('month', 0)))
		{
			$legacy = $first->modify(max(-1200, min(1200, $offset)) . ' months');
			if ((int) $legacy->format('Y') >= 1971 && (int) $legacy->format('Y') <= 2069) { $first = $legacy; }
		}
		$next = $first->modify('first day of next month');
		$previous = $first->modify('first day of previous month');
		$can_search = $this->config['load_search'] && $this->auth->acl_get('u_search') &&
			(bool) array_intersect($event['forums'], array_keys($this->auth->acl_getf('f_search', true)));
		$i = 0;
		foreach (period::month_cells($first,1) as $key=>$date)
		{
			if ($i < 7)
			{
				$this->template->assign_block_vars('mini_head', array('SHORT'=>$this->user->format_date((int) $date->format('U'),'D',true),
					'FULL'=>$this->user->format_date((int) $date->format('U'),'l',true)));
			}
			if (($i++ % 7) === 0) { $this->template->assign_block_vars('mini_week',array()); }
			$inside = $date >= $first && $date < $next;
			$this->template->assign_block_vars('mini_week.day', array('DATE'=>$key,'NUMBER'=>$date->format('j'),'INSIDE'=>$inside,
				'TODAY'=>$key === $today->format('Y-m-d'), 'URL'=>$inside && $date <= $today && $can_search ?
					append_sid($this->root . 'search.' . $this->ext,'search_id=mini_cal&amp;date=' . $key) : ''));
		}
		foreach ($this->schedule->topics((int) $today->format('U'), null, $event['forums'], 5) as $row)
		{
			$from = $today->setTimestamp($row['FROM']);
			$until = $today->setTimestamp($row['UNTIL']);
			$different_day = $from->format('Y-m-d') !== $until->format('Y-m-d');
			$format = ($different_day || $from->format('His') === '000000') ? 'j M Y' : 'j M Y H:i';
			$when = $this->user->format_date($row['FROM'],$format,true);
			if ($row['UNTIL'] > $row['FROM'] && ($different_day || $until->format('His') !== '235959'))
			{ $when .= ' – ' . $this->user->format_date($row['UNTIL'],$different_day ? 'j M Y' : 'H:i',true); }
			$this->template->assign_block_vars('mini_event', $row + array('WHEN'=>$when));
		}
		foreach ($this->schedule->posting_forums($event['forums']) as $forum)
		{
			$this->template->assign_block_vars('mini_forum',array('ID'=>(int) $forum['forum_id'],'NAME'=>$forum['forum_name']));
		}
		$days = min(366,max(0,(int) $this->config['plus_cal_birthday_days']));
		if ($days && !empty($this->user->data['is_registered']))
		{
			$seen = array();
			$until = $today->modify('+' . ($days === 1 ? 1 : $days + 1) . ' days');
			foreach ($this->schedule->birthdays($today,$until) as $birthday)
			{
				if (isset($seen[$birthday['URL']])) { continue; }
				$seen[$birthday['URL']] = true;
				$this->template->assign_block_vars('mini_birthday',$birthday + array('WHEN'=>$this->user->format_date($birthday['FROM'],'j M',true)));
			}
		}
		$this->template->assign_vars(array('MINI_MONTH'=>$this->user->format_date((int) $first->format('U'),'F Y',true),
			'U_MINI_FULL'=>$this->helper->route('phpbb2plus_calendar_home',array('date'=>$first->format('Y-m-d'))),
			'U_MINI_PREV'=>(int) $previous->format('Y') >= 1971 ? $this->portal_month($previous) : '',
			'U_MINI_NEXT'=>(int) $next->format('Y') <= 2069 ? $this->portal_month($next) : '',
			'U_MINI_POST'=>append_sid($this->root . 'posting.' . $this->ext, false, true, false),
			'MINI_SID'=>!empty($GLOBALS['_SID']) ? $this->user->session_id : ''));
	}
}
