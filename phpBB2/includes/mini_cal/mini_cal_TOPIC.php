<?php
/***************************************************************************
 *                            mini_cal_TOPIC.php
 *                            -------------------
 *   Author  		: 	netclectic - Adrian Cockburn - phpbb@netclectic.com
 *   Created 		: 	Monday, Nov 24, 2003
 *	 Last Updated	:	Thursday, March 25, 2004
 *
 *	 Version		: 	MINI_CAL - 2.0.4
 *
 ***************************************************************************/
    if ( !defined('IN_MINI_CAL') )
    {
    	die("Hacking attempt");
    }

    
    /***************************************************************************
        getMiniCalForumsAuth
        
        version:        1.0.0
        parameters:     $userdata - an initialised $userdata array.
        returns:        a two part array
                            $mini_cal_auth['view'] - a comma seperated list of forums which the user has VIEW permissions for
                            $mini_cal_auth['post'] - a comma seperated list of forums which the user has POST permissions for
     ***************************************************************************/
	function getMiniCalForumsAuth($userdata)
	{
		$permissions = auth(AUTH_ALL, AUTH_LIST_ALL, $userdata);
		$view_forums = array();
		$post_forums = array();
		foreach ($permissions as $forum_id => $permission)
		{
			if ((int) $forum_id < 1 || empty($permission['auth_view']) || empty($permission['auth_read'])) { continue; }
			if (!empty($permission[MINI_CAL_EVENT_AUTH_LEVEL])) { $view_forums[] = (int) $forum_id; }
			if (!empty($permission['auth_post']) && !empty($permission['auth_cal'])) { $post_forums[] = (int) $forum_id; }
		}
		return array('view' => implode(',', $view_forums), 'post' => implode(',', $post_forums));
	}

    /***************************************************************************
        getMiniCalEventDays
        
        version:        1.0.0
        parameters:     $auth_view_forums - a comma seperated list of forums which the user has VIEW permissions for
        returns:        an array containing a list of day containing event the user has permission to view
     ***************************************************************************/
	function getMiniCalEventDays($auth_view_forums)
	{
		global $db, $mini_cal_this_year, $mini_cal_this_month;

		$ids = miniCalForumIds($auth_view_forums);
		$start = mktime(0, 0, 0, $mini_cal_this_month, 1, $mini_cal_this_year);
		$end = mktime(0, 0, 0, $mini_cal_this_month + 1, 1, $mini_cal_this_year);
		if ($ids === '' || $start === false || $end === false) { return array(); }
		$sql = 'SELECT DISTINCT topic_calendar_time, topic_calendar_duration FROM ' . TOPICS_TABLE
			. " WHERE forum_id IN ($ids) AND topic_calendar_time > 0 AND topic_calendar_time < $end"
			. " AND topic_calendar_time + COALESCE(topic_calendar_duration,0) >= $start AND topic_status <> " . TOPIC_MOVED;
		if (!($result = $db->sql_query($sql))) { return array(); }
		$days = array();
		try
		{
			while ($row = $db->sql_fetchrow($result))
			{
				$time = (int) $row['topic_calendar_time'];
				$first = (int) date('j', max($time, $start));
				$last = (int) date('j', (int) min($end - 1, $time + (int) $row['topic_calendar_duration']));
				for ($day = $first; $day <= $last; $day++) { $days[$day] = true; }
			}
		}
		finally { $db->sql_freeresult($result); }
		ksort($days, SORT_NUMERIC);
		return array_keys($days);
	}

    /***************************************************************************
        getMiniCalEvents
        
        version:        1.0.0
        parameters:     $mini_cal_auth - a two part array 
                            $mini_cal_auth['view'] - a comma seperated list of forums which the user has VIEW permissions for
                            $mini_cal_auth['post'] - a comma seperated list of forums which the user has POST permissions for
        
        returns:        nothing - it assigns variable to the template
     ***************************************************************************/
	function miniCalTopicDate($clock, $format)
	{
		$weekday = 1 + ((int) $clock->format('w') - MINI_CAL_FDOW + 7) % 7;
		return getFormattedDate($weekday, $clock->format('n'), $clock->format('j'), $clock->format('Y'),
			$clock->format('G'), $clock->format('i'), $clock->format('s'), $format);
	}

	function getMiniCalEvents($mini_cal_auth)
    {
		global $template, $db, $phpEx, $phpbb_root_path, $lang, $mini_cal_today;
		$auth_view_forums = miniCalForumIds(isset($mini_cal_auth['view']) ? $mini_cal_auth['view'] : '');
		if ($auth_view_forums === '')
		{
			$template->assign_block_vars('switch_mini_cal_events', array());
			$template->assign_block_vars('mini_cal_no_events', array());
			return;
		}

		// Upcoming events are relative to today, independently of the month shown in the grid.
		$year = (int) substr($mini_cal_today, 0, 4);
		$month = (int) substr($mini_cal_today, 4, 2);
		$day = (int) substr($mini_cal_today, 6, 2);
		$start_date = mktime(0, 0, 0, $month, $day, $year);
		$days_ahead_sql = '';
		if (MINI_CAL_DAYS_AHEAD > 0)
		{
			$end_date = mktime(0, 0, 0, $month, $day + MINI_CAL_DAYS_AHEAD, $year);
			$days_ahead_sql = " AND t.topic_calendar_time < $end_date";
		}
		$mini_cal_auth_sql = ' AND t.forum_id IN (' . $auth_view_forums . ') ';
        
        // get events
		$sql = 'SELECT t.topic_id, t.topic_calendar_time, t.topic_title, t.topic_calendar_duration'
			. ' FROM ' . TOPICS_TABLE . ' t WHERE t.topic_calendar_time > 0'
			. " AND t.topic_calendar_time + COALESCE(t.topic_calendar_duration,0) >= $start_date"
			. ' AND t.topic_status <> ' . TOPIC_MOVED . $days_ahead_sql . $mini_cal_auth_sql
			. ' ORDER BY t.topic_calendar_time, t.topic_id LIMIT ' . (int) MINI_CAL_LIMIT;
        // did we get a result? 
    	if( $result = $db->sql_query($sql) )
    	{
           $template->assign_block_vars('switch_mini_cal_events', array());
           if ( $db->sql_numrows($result) > 0 )
           {
               // we've got some events
    
               // now let's output our events in the given date format for the current language
       	       while ($row = $db->sql_fetchrow($result))
               {
					$begin = phpbb_calendar_clock($row['topic_calendar_time']);
					$end = phpbb_calendar_clock($row['topic_calendar_time'], $row['topic_calendar_duration']);
					$day_span = $begin->format('Ymd') !== $end->format('Ymd');
					$include_time = $begin->format('His') !== '000000';
					$format = $lang['Mini_Cal_date_format'] . ((!$day_span && $include_time) ? ' ' . $lang['Mini_Cal_date_format_Time'] : '');
					$cal_date = miniCalTopicDate($begin, $format);
					if ($day_span || $row['topic_calendar_duration'] > 0)
					{
						$format = $day_span ? $lang['Mini_Cal_date_format'] : $lang['Mini_Cal_date_format_Time'];
						$cal_date .= ' - ' . miniCalTopicDate($end, $format);
					}
        			$template->assign_block_vars('mini_cal_events', array(
        					'MINI_CAL_EVENT_DATE' => $cal_date,
							'S_MINI_CAL_EVENT' => phpbb_stored_text($row['topic_title']),
        					'U_MINI_CAL_EVENT' => append_sid( $phpbb_root_path . "viewtopic.$phpEx?" . POST_TOPIC_URL . '=' . $row['topic_id'] )
                            )
        			); 
               }
           }
           else
           {
                // no events :(
                $template->assign_block_vars('mini_cal_no_events', array());
           }
           $db->sql_freeresult($result);
    
    	}
    }    
    
	
    /***************************************************************************
        getMiniCalSearchSql
        
        version:        1.0.0
        parameters:     $search_id   - the type of search we're looking for
						$search_date - the date passed to the search
        
        returns:        an sql string
     ***************************************************************************/
	function getMiniCalSearchSql($search_date)
	{
		$s_yy = intval(substr($search_date, 0, 4));
		$s_mm = intval(substr($search_date, 4, 2));
		$s_dd = intval(substr($search_date, 6, 2));
		$search_date = mktime(0,0,0, $s_mm, $s_dd, $s_yy);
		$nix_tomorrow  = mktime (0,0,0, $s_mm, $s_dd + 1, $s_yy);

		$sql = "SELECT topic_first_post_id as post_id
			FROM " . TOPICS_TABLE . " 
			WHERE (topic_calendar_time + topic_calendar_duration) >= $search_date AND topic_calendar_time < $nix_tomorrow";
	
		return $sql;
    }
	
	
    /***************************************************************************
        getMiniCalSearchURL
        
        version:        1.0.0
        parameters:     $search_date - the date passed to the search
        
        returns:        an url string
     ***************************************************************************/
	function getMiniCalSearchURL($search_date)
	{
		global $phpEx, $phpbb_root_path;

		$year = (int) substr($search_date, 0, 4);
		$month = (int) substr($search_date, 4, 2);
		$day = (int) substr($search_date, 6, 2);
		$timestamp = mktime(0, 0, 0, $month, $day, $year);
		return append_sid($phpbb_root_path . "calendar_scheduler.$phpEx?d=" . $timestamp);
	}
    /***************************************************************************
        getMiniCalPostForumsList
        
        version:        1.0.0
        parameters:     $mini_cal_post_auth  - a comma seperated list of forms with post rights
                        
        returns:        adds a forums select list to the template output
    ***************************************************************************/
	function getMiniCalPostForumsList($mini_cal_post_auth)
	{
		getPostForumsList($mini_cal_post_auth);
	}
	
    
	$template->assign_vars(array(
		'U_MINI_CAL_CALENDAR' => append_sid($phpbb_root_path . 'calendar.' . $phpEx),
		'U_MINI_CAL_ADD_EVENT' => append_sid($phpbb_root_path . 'posting.' . $phpEx . '?mode=newtopic' . (defined('MINI_CAL_EVENTS_FORUM') ? '&f=' . MINI_CAL_EVENTS_FORUM : ''))
        )
    );    
    
    
?>
