<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
function ctracker_legacy_blocklist_values()
{
    return array('*WebStripper*','*NetMechanic*','*CherryPicker*','*EmailCollector*',
        '*EmailSiphon*','*WebBandit*','*EmailWolf*','*ExtractorPro*',
        '*SiteSnagger*','*CheeseBot*','*ia_archiver*','*Website Quester*',
        '*WebZip*','*moget*','*WebSauger*','*WebCopier*','*WWW-Collector*',
        '*InfoNaviRobot*','*Harvest*','*Bullseye*','*LinkWalker*',
        '*LinkextractorPro*','*WebProxy*','*BlowFish*','*WebEnhancer*',
        '*TightTwatBot*','*LinkScan*','*WebDownloader*','lwp',
        '*BruteForce*','lwp-*','*anonym*');
}
function ctracker_legacy_blocklist_where()
{
    $values=array();foreach(ctracker_legacy_blocklist_values()as$value){$values[]="'".strtoupper(bin2hex($value))."'";}
    // Text collations also equate custom accented/case variants. Match only
    // the exact shipped bytes, including trailing spaces, during cleanup.
    return 'HEX(`ct_blocker_value`) IN ('.implode(',',$values).')';
}
function ctracker_legacy_blocklist_cleanup_sql($table)
{
    if(!is_string($table)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)){throw new InvalidArgumentException('Invalid legacy rule table');}
    return 'DELETE FROM `'.$table.'` WHERE '.ctracker_legacy_blocklist_where();
}
