<?php
/***************************************************************************
 * REPOSITORY NOTE
 *
 * Legacy reference only. This script performs destructive database changes
 * and uses the removed mysql_* API. Do not deploy or execute it unchanged.
 ***************************************************************************/
/***************************************************************************
 *                  uninstall_crackertracker_4x_legacy.php
 *                            -------------------
 *   begin                : Aug 31, 2006
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
 $title     = 'CBACK CT Uninstall';
 $version   = '';
 $rootpath  = './';
 $sprefix   = 'phpbb_'; // PREFIX IS CHANGED AUTOMATICALLY BY THE SETUP!!! YOU DON'T HAVE TO CHANGE ANYTHING IN THIS FILE!


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
 $sql[] = "DROP TABLE `phpbb_ctrack`;";
 $sql[] = "DROP TABLE `phpbb_ct_filter`;";
 $sql[] = "DROP TABLE `phpbb_ct_viskey`;";
 $sql[] = "ALTER TABLE `phpbb_users` DROP `ct_logintry`;";
 $sql[] = "ALTER TABLE `phpbb_users` DROP `ct_unsucclogin`;";
 $sql[] = "ALTER TABLE `phpbb_users` DROP `ct_pwreset`;";
 $sql[] = "ALTER TABLE `phpbb_users` DROP `ct_mailcount`;";
 $sql[] = "ALTER TABLE `phpbb_users` DROP `ct_postcount`;";
 $sql[] = "ALTER TABLE `phpbb_users` DROP `ct_posttime`;";
 $sql[] = "ALTER TABLE `phpbb_users` DROP `ct_searchcount`;";
 $sql[] = "ALTER TABLE `phpbb_users` DROP `ct_searchtime`;";
 
?>

<html>
  	    <head>
        <title>CBACK Database Uninstall</title>
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
                    This Setup Script will remove old CrackerTracker Version entrys from your Database while you're reading this text.
					</font><br><br><br><font face="Verdana" color="yellow" size="3"><b>Database Operations:</b><br><br></font><ul>
<?php

  // Lets do the Database Changes
  for( $i = 0; $i < count($sql); $i++ )
  {
    $sql[$i] = preg_replace('/' . $sprefix . '/', $table_prefix, $sql[$i]);
    if($result = mysql_query ($sql[$i]) )
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
