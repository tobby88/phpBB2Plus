<?php
/**  
* <b>CrackerTracker File: ct_ipblocker.php</b><br><br>
* 
* This file is the engine for the CrackerTracker IP, UserAgent and
* Remote Host blocking System. You can enable or disable this feature
* in ACP and you can add or remove Blocked Hostnames, IP Adresses, etc.
* 
* <br><br>
* 
* This scanner also works well with the Joker-Sign <i>"*"</i>. So you have the
* possibility to block for example IPs like <i>"123.456.*.*"</i> or Hostnames
* like <i>"BadBrowser v*"</i>
* 
* 
* @author Christian Knerr (cback)
* @package ctracker
* @version 5.0.0
* @since 16.07.2006 - 02:07:51
* @copyright (c) 2006 www.cback.de
* 
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*/

if ( !defined('IN_PHPBB') )
{
	die("Hacking attempt!");
}

function ctracker_ip_matches_cidr($ip, $cidr)
{
	if (!is_string($ip) || !is_string($cidr) || !preg_match('~^(.+)/([0-9]{1,3})$~D', trim($cidr), $match))
	{
		return false;
	}

	$packed_ip = @inet_pton($ip);
	$packed_network = @inet_pton($match[1]);
	if ($packed_ip === false || $packed_network === false || strlen($packed_ip) !== strlen($packed_network))
	{
		return false;
	}

	$bits = intval($match[2]);
	$max_bits = strlen($packed_ip) * 8;
	if ($bits < 0 || $bits > $max_bits)
	{
		return false;
	}

	$whole_bytes = intval(floor($bits / 8));
	$remaining_bits = $bits % 8;
	if ($whole_bytes > 0 && substr($packed_ip, 0, $whole_bytes) !== substr($packed_network, 0, $whole_bytes))
	{
		return false;
	}
	if ($remaining_bits === 0)
	{
		return true;
	}

	$mask = (0xff << (8 - $remaining_bits)) & 0xff;
	return (ord($packed_ip[$whole_bytes]) & $mask) === (ord($packed_network[$whole_bytes]) & $mask);
}

function ctracker_blocklist_pattern_matches($pattern, $target)
{
	$pattern = is_scalar($pattern) ? trim((string) $pattern) : '';
	$target = is_scalar($target) ? (string) $target : '';
	if ($pattern === '' || $target === '' || strlen($pattern) > 200 || substr_count($pattern, '*') > 8 || preg_match('/[\x00-\x1f\x7f]/', $pattern))
	{
		return false;
	}

	$expression = str_replace('\\*', '.*', preg_quote($pattern, '~'));
	return preg_match('~\A' . $expression . '\z~is', $target) === 1;
}

function ctracker_blocklist_matches($pattern, $ip, $user_agent, $remote_host)
{
	if (ctracker_ip_matches_cidr((string) $ip, (string) $pattern))
	{
		return true;
	}

	return ctracker_blocklist_pattern_matches($pattern, $ip) ||
		ctracker_blocklist_pattern_matches($pattern, $user_agent) ||
		ctracker_blocklist_pattern_matches($pattern, $remote_host);
}


/*
 * We check if the user has activated the IP and Hostname Blocker.
 * If so we use our ct_database class to load the Blocklist from the
 * Database in an array and check if someone who was blocked is in the list.
 */
if (!defined('CTRACKER_IPBLOCKER_NO_AUTO_RUN') && !empty($ctracker_config->settings['ipblock_enabled']))
{
	// Fetch Blocklist from Database
	$ctracker_config->unset_blocklist_verbose();
	$ctracker_config->load_blocklist();
	
	// Fetch IP UserAgent and Remote Host
	$ct_client_ip = isset($ctracker_config->user_ip_value) ? (string) $ctracker_config->user_ip_value : '';
	$ct_user_agent = isset($HTTP_SERVER_VARS['HTTP_USER_AGENT']) && is_scalar($HTTP_SERVER_VARS['HTTP_USER_AGENT']) ? substr((string) $HTTP_SERVER_VARS['HTTP_USER_AGENT'], 0, 2048) : '';
	$ct_remote_host = isset($HTTP_SERVER_VARS['REMOTE_HOST']) && is_scalar($HTTP_SERVER_VARS['REMOTE_HOST']) ? substr((string) $HTTP_SERVER_VARS['REMOTE_HOST'], 0, 255) : '';
	
	/*
	 * Now we check if IP Adress, UserAgent or RemoteHost of the User
	 * is blocked by CrackerTracker. You can use the Joker "*" to match
	 * all expressions between 2 Words (adjustable in ACP)
	 */
	for ($i = 0; $i < $ctracker_config->blocklist_count; $i++)
	{
		if (ctracker_blocklist_matches($ctracker_config->blocklist[$i], $ct_client_ip, $ct_user_agent, $ct_remote_host))
		{
	 		// We have a match, so write the log
			include_once($phpbb_root_path . 'ctracker/classes/class_log_manager.' . $phpEx);
	
			// write data into logfile
			$logfile = new log_manager();
			$logfile->write_general_logfile($ctracker_config->settings['ipblock_logsize'], 3);
			unset($logfile);	 		
	 		
		// generate HTML output
		if (!headers_sent())
		{
			http_response_code(403);
			header('Content-Type: text/html; charset=UTF-8');
			header('Cache-Control: no-store');
		}
		$htmloutput = '<html>
				     		<head>
		    				   <title>CBACK CrackerTracker :: Security Alert</title>
  							 </head>
  							 <body>
		    				   <br>
    						   <div align="center">
      						   <table style="border:2px solid #000000" border="0" width="600" cellpadding="10" cellspacing="0">
         			    		 <tr>
		          				   <td align="left" bgcolor="#000000"><font face="Tahoma, Arial, Helvetica" size="4" color="#FFFFFF"><b>SECURITY ALERT &raquo; &raquo; &raquo; &raquo;</b></font></td>
        						 </tr>
        						 <tr>
          						   <td bgcolor="#FFF2CF" align="left">
          								<font face="Tahoma, Arial, Helvetica" size="2" color="#000000">
		          						  <b>CBACK CrackerTracker</b> blocked you because
										  the Admin blocked your IP range, useragent or hostname
										  from this board.
										  <br><br>
										  If you think you\'re banned without a reason please tell
										  the Admin from this error message and ask him what happened that
										  he has the possibility to unblock you.
		          						</font>
        		  				    </td>
        						   </tr>
      				    		 </table>
		    				   </div>
		  					 </body>
				   		</html>';	 		
	 		
	 		// stop the script
	 		die($htmloutput);
		}

	} // for
	 
	 /*
	  * Now we don't need the Array with the Blocklist Information anymore so
	  * we drop it
	  */
	 unset($ctracker_config->blocklist);
	 
} // if


// Tell the self test that this file was included correctly
define('protection_unit_three', true);

?>
