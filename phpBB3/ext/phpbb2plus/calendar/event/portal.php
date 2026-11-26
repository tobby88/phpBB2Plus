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
	private $root;
	private $ext;

	public function __construct($schedule, $request, $user, $auth, $config, $language, $helper, $template, $root, $ext)
	{
		$this->schedule=$schedule; $this->request=$request; $this->user=$user; $this->auth=$auth; $this->config=$config;
		$this->language=$language; $this->helper=$helper; $this->template=$template; $this->root=$root; $this->ext=$ext;
	}

	public static function getSubscribedEvents() { return array('phpbb2plus.community.portal_content' => 'calendar'); }

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
