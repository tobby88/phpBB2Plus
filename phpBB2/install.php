<?php
/***************************************************************************
 *                                install.php
 *                            -------------------
 *   begin                : Saturday, Aug 19, 2006
 *   copyright            : (C) Christian Knerr (CBACK)
 *   homepage             : http://www.cback.de
 *
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This Script is NOT released under GPL License! Sorry, but this should
 *   stay an unique part of CBACK MODs and the Orion Update System.
 *
 ***************************************************************************/

 define("IN_PHPBB", true);


 // General Information
 $title     = 'CBACK CrackerTracker Professional';
 $version   = '5.0.4';
 $rootpath  = './';
 $sprefix   = 'phpbb_';


 // Load Configuration
 include($rootpath . "extension.inc");
 include($rootpath . "config." . $phpEx);
 include($rootpath . "includes/db." . $phpEx);


 // Database Connect
 @$sql = mysql_connect($dbhost, $dbuser, $dbpasswd)
   or die("<b>CBACK Setup System</b><br><br>Critical Error: Database connection failed.");

 @mysql_select_db($dbname)
   or die("<b>CBACK Setup System</b><br><br>Critical Error: Selected Database doesn't exists.");


 // SQL Scheme
 // For all who ask: The Prefix will be automatically replaced during Setup, you don't have to do
 // anything in this file you see ;)
 $sql = array();

 // Usertable alterations
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_search_time` INT( 11 ) NULL DEFAULT 1 AFTER `user_newpasswd`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_search_count` MEDIUMINT( 8 ) NULL DEFAULT 1 AFTER `ct_search_time`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_last_mail` INT( 11 ) NULL DEFAULT 1 AFTER `ct_search_count`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_last_post` INT( 11 ) NULL DEFAULT 1 AFTER `ct_last_mail`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_post_counter` MEDIUMINT( 8 ) NULL DEFAULT 1 AFTER `ct_last_post`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_last_pw_reset` INT( 11 ) NULL DEFAULT 1 AFTER `ct_post_counter`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_enable_ip_warn` TINYINT( 1 ) NULL DEFAULT 1 AFTER `ct_last_pw_reset`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_last_used_ip` VARCHAR( 16 ) NULL DEFAULT '0.0.0.0' AFTER `ct_enable_ip_warn`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_last_ip` VARCHAR( 16 ) NULL DEFAULT '0.0.0.0' AFTER `ct_last_used_ip`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_login_count` MEDIUMINT( 8 ) NULL DEFAULT 1 AFTER `ct_last_used_ip`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_login_vconfirm` TINYINT( 1 ) NULL DEFAULT 0 AFTER `ct_login_count`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_last_pw_change` INT( 11 ) NULL DEFAULT 1 AFTER `ct_login_vconfirm`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_global_msg_read` TINYINT( 1 ) NULL DEFAULT 0 AFTER `ct_last_pw_change`;";
 $sql[] = "ALTER TABLE `phpbb_users` ADD `ct_miserable_user` TINYINT( 1 ) NULL DEFAULT 0 AFTER `ct_global_msg_read`;";

 // Create Configuration Table with its entrys
 $sql[] = "CREATE TABLE `phpbb_ctracker_config` (
			`ct_config_name` varchar(255) NOT NULL,
			`ct_config_value` varchar(255) NOT NULL,
			PRIMARY KEY  (`ct_config_name`)
			)";

 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('ipblock_enabled', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('ipblock_logsize', '100');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('auto_recovery', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('vconfirm_guest', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('autoban_mails', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('detect_misconfiguration', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('search_time_guest', '30');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('search_time_user', '20');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('search_count_guest', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('search_count_user', '4');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('massmail_protection', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('reg_protection', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('reg_blocktime', '30');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('reg_lastip', '0.0.0.0');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('pwreset_time', '20');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('massmail_time', '20');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('spammer_time', '30');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('spammer_postcount', '4');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('spammer_blockmode', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('loginfeature', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('pw_reset_feature', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('reg_last_reg', '1155944976');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('login_history', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('login_history_count', '10');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('login_ip_check', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('pw_validity', '30');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('pw_complex_min', '4');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('pw_complex_mode', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('pw_control', '0');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('pw_complex', '0');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('last_file_scan', '1156000091');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('last_checksum_scan', '1156000082');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('logsize_logins', '100');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('logsize_spammer', '100');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('reg_ip_scan', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('global_message', 'Hello world!');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('global_message_type', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('logincount', '2');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('search_feature_enabled', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('spam_attack_boost', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('spam_keyword_det', '1');";
 $sql[] = "INSERT INTO `phpbb_ctracker_config` (`ct_config_name`, `ct_config_value`) VALUES ('footer_layout', '3');";

 // Create File Check Table
 $sql[] = "CREATE TABLE `phpbb_ctracker_filechk` (
			`filepath` text,
			`hash` varchar(32) default NULL
			)";

 // Create File Scanner Table
 $sql[] = "CREATE TABLE `phpbb_ctracker_filescanner` (
			`id` smallint(5) NOT NULL,
			`filepath` text,
			`safety` smallint(1) NOT NULL default '0',
			PRIMARY KEY  (`id`)
			)";

 // Create IP Blocker Table with its entrys
 $sql[] = "CREATE TABLE `phpbb_ctracker_ipblocker` (
			`id` mediumint(8) unsigned NOT NULL,
			`ct_blocker_value` varchar(250) default NULL,
			PRIMARY KEY  (`id`)
			) AUTO_INCREMENT=33 ;";

 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (1, '*WebStripper*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (2, '*NetMechanic*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (3, '*CherryPicker*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (4, '*EmailCollector*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (5, '*EmailSiphon*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (6, '*WebBandit*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (7, '*EmailWolf*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (8, '*ExtractorPro*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (9, '*SiteSnagger*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (10, '*CheeseBot*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (11, '*ia_archiver*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (12, '*Website Quester*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (13, '*WebZip*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (14, '*moget*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (15, '*WebSauger*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (16, '*WebCopier*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (17, '*WWW-Collector*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (18, '*InfoNaviRobot*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (19, '*Harvest*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (20, '*Bullseye*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (21, '*LinkWalker*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (22, '*LinkextractorPro*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (23, '*WebProxy*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (24, '*BlowFish*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (25, '*WebEnhancer*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (26, '*TightTwatBot*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (27, '*LinkScan*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (28, '*WebDownloader*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (29, 'lwp');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (30, '*BruteForce*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (31, 'lwp-*');";
 $sql[] = "INSERT INTO `phpbb_ctracker_ipblocker` (`id`, `ct_blocker_value`) VALUES (32, '*anonym*');";

 // Create Login History Table
 $sql[] = "CREATE TABLE `phpbb_ctracker_loginhistory` (
			`ct_user_id` int(10) default NULL,
			`ct_login_ip` varchar(16) default NULL,
			`ct_login_time` int(11) NOT NULL default '0'
			)";

?>

<html>
  	    <head>
        <title>CBACK Database Update System</title>
        </head>
        <body bgcolor="#2F2C2C">
        <div align="center">
          <table border="1" width="800px" cellspacing="0">
            <tr>
              <td width="100%" valign="top" bgcolor="#000000">

                <img src="http://www.community.cback.de/uplink/ctracker_g5.jpg" border="0"><br>
                <br>
                <center>
                  <table border="0" height="100%" width="94%" cellspacing="0">
                    <tr height="100%">
                    <td align="left">
                    <div align="right"><font face="Tahoma" color="orange" size="5"><b><?php echo $title; ?></b><br /><?php echo $version; ?></font></div><br><br>

                    <font face="Verdana" color="#FFFFFF" size="3">
                    Welcome to the automatic <a href="http://www.cback.de" target="_blank" style="color:yellow">CBACK</a> Database Update System for <?php echo $title; ?> v<?php echo $version; ?>.
                    This Setup Script is performing all needed Database changes to your Forum while you're reading this Text.
					</font><br><br><br><font face="Verdana" color="yellow" size="3"><b>Database Operations:</b><br><br></font><ul>
<?php

  // Lets do the Database Changes
  for( $i = 0; $i < count($sql); $i++ )
  {
    $sql[$i] = preg_replace('/' . $sprefix . '/', $table_prefix, $sql[$i]);
    if(!$result = mysql_query ($sql[$i]) )
	{
		 echo '<li><font face="Arial" color="#FF0000" size="2"><b>[ ERROR ]</b></font> <font face="Arial" color="#808080" size="2">' . $sql[$i] . '</font></li><br />';
	}
	else
	{
		echo '<li><font face="Arial" color="#00AA00" size="2"><b>[ OK ]</b></font> <font face="Arial" color="#808080" size="2">' . $sql[$i] . '</font></li><br />';
	}
  }
?>
                    </ul><font face="Verdana" color="#FFFFFF" size="3"><br><br>
                    <font face="Verdana" color="yellow" size="3">Everything is done now! Please <b>delete this file</b> from your Webspace now!<br><br></font>
                    <br><br><br>
                    </font>
                    </td>
                    </tr>
                  </table>
                </center>
                <br><img src="http://www.community.cback.de/uplink/cdus_foot.jpg" border="0">
              </td>
            </tr>
          </table>
        </div>
        </body>
        </html>


<?php

  // Datenbankverbindung trennen
  @mysql_close($sql);

?>