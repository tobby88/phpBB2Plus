<?php
/***************************************************************************
 *                            mini_cal_common.php
 *                            -------------------
 *   Author  		: 	netclectic - Adrian Cockburn - phpbb@netclectic.com
 *   Created 		: 	Tuesday, Jan 02, 2004
 *	 Last Updated	:	Wednesday, Mar 17, 2004
 *
 *	 Version		: 	MINI_CAL - 2.0.4
 *
 ***************************************************************************/


    /***************************************************************************
        getFormattedDate
        
        version:        1.0.0
        parameters:     $cal_weekday    - 
                        $cal_month      - 
                        $cal_monthday   - 
                        $cal_year       - 
                        $cal_hour       - 
                        $cal_min        - 
                        $cal_sec        -
                        
        returns:        a date formatted according to the MINI_CAL_DATE_PATTERNS 
                        set in mini_cal_config.php and the Mini_Cal_date_format 
                        set in lang_main_min_cal.php
     ***************************************************************************/
    function getFormattedDate($cal_weekday, $cal_month, $cal_monthday, $cal_year, $cal_hour, $cal_min, $cal_sec, $format)
    {
		global $lang;
		
        // initialise out date formatting patterns
        $cal_date_pattern = phpbb_safe_unserialize_array(MINI_CAL_DATE_PATTERNS);

        $cal_date_replace = array( 
            $lang['mini_cal']['day'][$cal_weekday], 
            $lang['mini_cal']['month'][$cal_month], 
            $cal_month, 
            ( (strlen($cal_monthday) < 2 ) ?  '0' : '' ) . $cal_monthday, 
            $cal_monthday, 
            ( (strlen($cal_month) < 2 ) ?  '0' : '' ) . $cal_month, 
            substr($cal_year, -2),
            $cal_year,
            ( (strlen($cal_hour) < 2 ) ?  '0' : '' ) . $cal_hour,
            $cal_hour,
            ( (strlen($cal_hour) < 2 ) ?  '0' : '' ) . ( ( $cal_hour > 12 ) ? $cal_hour-12 : $cal_hour ),
            ( $cal_hour > 12 ) ? $cal_hour-12 : $cal_hour,
            $cal_min,
            $cal_sec,
            ( $cal_hour < 12 ) ? 'AM' : 'PM'
        );
        
        return preg_replace($cal_date_pattern, $cal_date_replace, $format); 
    }
    


    /***************************************************************************
        setQueryStringVal
        
        version:        1.0.0
        parameters:     $var    - the variable who's value is to be replaced
                        $value  - the new value for the variable
                        
        returns:        a modified querystring prefixed with ? 
     ***************************************************************************/
	function setQueryStringVal($var, $value)
	{
		if (!is_string($var) || !preg_match('/^[a-z0-9_]{1,32}$/iD', $var) || !is_scalar($value))
		{
			return '';
		}

		$params = array();
		$mode = isset($_POST['mode']) && is_scalar($_POST['mode']) ? (string) $_POST['mode'] :
			(isset($_GET['mode']) && is_scalar($_GET['mode']) ? (string) $_GET['mode'] : '');
		if ($mode === 'personal')
		{
			$params['mode'] = 'personal';
		}

		$user_key = defined('POST_USERS_URL') ? POST_USERS_URL : 'u';
		$user_value = isset($_POST[$user_key]) && is_scalar($_POST[$user_key]) ? $_POST[$user_key] :
			(isset($_GET[$user_key]) && is_scalar($_GET[$user_key]) ? $_GET[$user_key] : 0);
		$user_id = max(0, intval($user_value));
		if ($user_id > 0)
		{
			$params[$user_key] = $user_id;
		}

		$params[$var] = substr((string) $value, 0, 32);
		return '?' . str_replace('&', '&amp;', http_build_query($params, '', '&', PHP_QUERY_RFC3986));
	}

	function miniCalForumIds($forum_ids)
	{
		if (!is_scalar($forum_ids))
		{
			return '';
		}
		$forum_ids = trim((string) $forum_ids);
		if ($forum_ids === '' || !preg_match('/^[0-9]+(?:\s*,\s*[0-9]+)*$/D', $forum_ids))
		{
			return '';
		}

		$normalized = array();
		foreach (explode(',', $forum_ids) as $forum_id)
		{
			$forum_id = intval(trim($forum_id));
			if ($forum_id > 0)
			{
				$normalized[$forum_id] = $forum_id;
			}
		}
		return implode(',', $normalized);
	}
	
	
    /***************************************************************************
        getPostForumsList
        
        version:        1.0.0
        parameters:     $mini_cal_post_auth  - a comma seperated list of forms with post rights
                        
        returns:        adds a forums select list to the template output
    ***************************************************************************/
	function getPostForumsList($mini_cal_post_auth, $and_post_auth_sql = '')
	{
		$mini_cal_post_auth = miniCalForumIds($mini_cal_post_auth);
		if ($mini_cal_post_auth !== '')
	   	{
			global $db, $template, $lang;
			$selected_forum = isset($_POST[POST_FORUM_URL]) && is_scalar($_POST[POST_FORUM_URL]) ? intval($_POST[POST_FORUM_URL]) :
				(isset($_GET[POST_FORUM_URL]) && is_scalar($_GET[POST_FORUM_URL]) ? intval($_GET[POST_FORUM_URL]) : 0);

	       // get a list of events forums
	       $sql = 'SELECT c.cat_id, c.cat_title, f.forum_id, f.forum_name 
	            FROM '  . FORUMS_TABLE . ' f, ' . CATEGORIES_TABLE . ' c 
	            WHERE f.cat_id = c.cat_id 
	              AND f.forum_id IN (' . $mini_cal_post_auth . ')' . 
				  $and_post_auth_sql;
			
	       if( $result = $db->sql_query($sql) )
		   {
	           $num_rows = $db->sql_numrows($result);
	           if ( $num_rows > 0 )
	           {
	               $template->assign_block_vars('switch_mini_cal_add_events', array());
	    
	               $forums_list = '<select style="width: 100%" name="' . POST_FORUM_URL . '" onchange="if(this.options[this.selectedIndex].value > -1){ forms[\'mini_cal\'].submit() }">';
	               	                    
	               $cat_id = 0;
		       while ($row = $db->sql_fetchrow($result))
		       {
					$selected = ((int) $row['forum_id'] === $selected_forum) ? ' selected="selected"' : '';
					$forums_list .= '<option value="' . (int) $row['forum_id'] . '"' . $selected . '>  - ' . htmlspecialchars(substr((string) $row['forum_name'], 0, 20), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</option>';
		       }
	               $forums_list .= '</select>';
	               
	               $template->assign_vars( array(
	                    'S_MINI_CAL_EVENTS_FORUMS_LIST' => $forums_list 
	                    )
	               );
	           }    
	           $db->sql_freeresult($result);
	       }
		}
   }
	
?>
