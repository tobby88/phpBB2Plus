<?xml version="1.0" encoding="{S_CONTENT_ENCODING}"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" dir="{S_CONTENT_DIRECTION}" lang="{S_CONTENT_LANGUAGE}" xml:lang="{S_CONTENT_LANGUAGE}">
<head>
<meta http-equiv="Content-Type" content="text/html; charset={S_CONTENT_ENCODING}" />
<meta http-equiv="Content-Style-Type" content="text/css" />
{META}
{NAV_LINKS}
<title>{SITENAME} :: {PAGE_TITLE}</title>
<link rel="stylesheet" href="templates/fisubsilversh/{T_HEAD_STYLESHEET}" type="text/css" />
<link rel="shortcut icon" href="./favicon.ico" />
<script language="JavaScript" type="text/javascript" src="includes/toggle_display.js"></script>
<!-- BEGIN switch_enable_pm_popup -->
<script type="text/javascript">
<!--
	if ( {PRIVATE_MESSAGE_NEW_FLAG} )
	{
		window.open('{U_PRIVATEMSGS_POPUP}', '_phpbbprivmsg', 'HEIGHT=225,resizable=yes,WIDTH=400');;
	}
//-->
</script>
<!-- END switch_enable_pm_popup -->
<script type="text/javascript">
<!--
var phpEx = '{PHPEX}';
var POST_FORUM_URL = '{POST_FORUM_URL}';
var POST_TOPIC_URL = '{POST_TOPIC_URL}';
var POST_POST_URL = '{POST_POST_URL}';
var ajax_page_charset = '{S_CONTENT_ENCODING}';
var S_SID = '{S_SID}';
var ajax_core_defined = 0;
var phpbb_root_path = '{PHPBB_ROOT_PATH}';
//-->
</script>

<script type="text/javascript" src="includes/javascript/ajax_core.js?v=20260831-2"></script>

<script language="Javascript" type="text/javascript"> 
<!-- 
function setCheckboxes(theForm, elementName, isChecked)
{
    var chkboxes = document.forms[theForm].elements[elementName];
    var count = chkboxes.length;

    if (count) 
	{
        for (var i = 0; i < count; i++) 
		{
            chkboxes[i].checked = isChecked;
    	}
    } 
	else 
	{
    	chkboxes.checked = isChecked;
    } 

    return true;
} 
//--> 
</script>
<!-- Start add - Birthday MOD -->
{GREETING_POPUP}
<!-- End add - Birthday MOD -->
<script type="text/javascript">
<!--
window.status = "{PRIVATE_MESSAGE_INFO}";
// -->
</script>
<!-- Start add - Protect user account MOD -->
{PASSWD_POPUP}
<!-- End add - Protect user account MOD -->
<!-- BEGIN switch_absence -->
<script language="Javascript" type="text/javascript">
<!--
	window.open('{U_ABSENCE_POPUP}', '_phpbbprivmsg', 'HEIGHT=225,resizable=yes,WIDTH=400');;
//-->
</script>
<!-- END switch_absence -->
<script type="text/javascript">
<!--
function Gk_PopTart(mypage, myname, w, h, scroll)
{
	var leftPosition = (screen.width) ? (screen.width - w) / 2 : 0;
	var topPosition = (screen.height) ? (screen.height - h) / 2 : 0;
	var settings = 'height=' + h + ',width=' + w + ',top=' + topPosition + ',left=' + leftPosition + ',scrollbars=' + scroll + ',resizable=yes';
	window.open(mypage, myname, settings);
}
//-->
</script>
<script type="text/javascript" src="{PHPBB_ROOT_PATH}assets/ruffle/phpbb-config.js"></script>
<script type="text/javascript" src="{PHPBB_ROOT_PATH}assets/ruffle/ruffle.js"></script>
</head>
<body>
<!-- Start add - Complete banner MOD -->
<!-- BEGIN switch_Banners -->
<table width="100%" border="0" cellspacing="0" cellpadding="0">
<tr>
<td width="20%">
<table width="100%" border="0" cellspacing="0" cellpadding="0">
<tr><td><div align="center">{BANNER_1_IMG}</div></td></tr>
<tr><td><div align="center">{BANNER_2_IMG}</div></td></tr>
</table>
</td>
<td width="60%">
<table width="100%" border="0" cellspacing="0" cellpadding="0">
<tr><td><div align="center">{BANNER_3_IMG}</div></td></tr>
<tr><td><div align="center">{BANNER_4_IMG}</div></td></tr>
</table>
</td>
<td width="20%">
<table width="100%" border="0" cellspacing="0" cellpadding="0">
<tr><td><div align="center">{BANNER_5_IMG}</div></td></tr>
<tr><td><div align="center">{BANNER_6_IMG}</div></td></tr>
</table>
</td>
</tr>
</table>
<!-- END switch_Banners -->
<!-- End add - Complete banner MOD -->
<a name="top" id="top"></a>
<table class="bodyline" width="100%" cellspacing="0" cellpadding="0" border="0">
<tr>
<td>
<table class="topbkg" width="100%" cellspacing="0" cellpadding="0" border="0">
<tr> 
<td><a href="{U_INDEX}"><img src="templates/fisubsilversh/images/phpbb2_logo.jpg" border="0" alt="{L_INDEX}" title="{L_INDEX}" width="240" height="110" /></a></td>
<td align="center" width="100%">{BANNER_0_IMG}</td><td><a href="{U_PORTAL}"><img src="templates/fisubsilversh/images/phpbb2_logor.jpg" border="0" alt="{L_HOME}" title="{L_HOME}" width="140" height="110" /></a></td>
</tr>
</table>
<table width="100%" border="0" cellspacing="0" cellpadding="2">
<tr> 
<td align="center" class="topnav">&nbsp;<a href="{U_FAQ}">{L_FAQ}</a>
&nbsp;&#8226;&nbsp;
<a href="{U_SEARCH}">{L_SEARCH}</a>
&nbsp;&#8226;&nbsp;
<a href="{U_PREFERENCES}">{L_PREFERENCES}</a>
<!-- BEGIN switch_user_logged_in -->
&nbsp;&#8226;&nbsp;
<a href="{U_BOOKMARKS}">{L_BOOKMARKS}</a>
&nbsp; &#8226;&nbsp;
<a href="{U_SEARCH_NEW}">{L_SEARCH_NEW2}</a>
<!-- END switch_user_logged_in -->
&nbsp;&#8226;&nbsp;
<a href="{U_GROUP_CP}">{L_USERGROUPS}</a>
<!-- BEGIN switch_user_logged_out -->
&nbsp;&#8226;&nbsp;
<a href="{U_REGISTER}">{L_REGISTER}</a>
<!-- END switch_user_logged_out -->
<!-- BEGIN login_sec_link -->
&nbsp;&#8226;&nbsp;
<a href="{U_LOGIN_SEC}">{L_LOGIN_SEC}</a>
<!-- END login_sec_link -->
&nbsp;&#8226;&nbsp;
<a href="{U_PROFILE}">{L_PROFILE}</a>
&nbsp;&#8226;&nbsp;
<a href="{U_PRIVATEMSGS}">{PRIVATE_MESSAGE_INFO}</a>
&nbsp;&#8226;&nbsp;
<a href="{U_ACTIVITY}">{L_ACTIVITY}</a>
&nbsp;&#8226;&nbsp;
<a href="{U_LOGIN_LOGOUT}">{L_LOGIN_LOGOUT}</a></td>
</tr>
</table>
<table border="0" cellpadding="0" cellspacing="0" class="tbl"><tr><td class="tbll"><img src="images/spacer.gif" alt="" width="8" height="4" /></td><td class="tblbot"><img src="images/spacer.gif" alt="" width="8" height="4" /></td><td class="tblr"><img src="images/spacer.gif" alt="" width="8" height="4" /></td></tr></table>
<!-- BEGIN switch_cookie_consent -->
<div id="cookie_notice" class="forumline" style="margin-top: 8px; padding: 8px; text-align: center; background-color: #fdf4e3;">
<span class="gensmall">{cookie_consent_msg} [ <a href="#" onclick="document.getElementById('privacy_dropdown').style.display = (document.getElementById('privacy_dropdown').style.display == 'none') ? 'block' : 'none'; return false;">{L_PRIVACY}</a> ]</span>
<div id="privacy_dropdown" class="gensmall" style="display: none; margin: 8px auto; width: 80%; text-align: left;">{L_PRIVACY_POLICY}</div>
<input type="button" class="mainoption" value="{L_COOKIE_ACCEPT}" onclick="document.cookie='cookie_consent=1; path=/; max-age=31536000; SameSite=Lax'; document.getElementById('cookie_notice').style.display='none'; return false;" />
</div>
<!-- END switch_cookie_consent -->
<!-- BEGIN ctracker_message -->
<br />
<div align="center"><table width="80%" cellspacing="1" cellpadding="3" border="0" class="forumline">
<tr><td align="center" style="background-color:#{ctracker_message.ROW_COLOR};"><img src="{ctracker_message.ICON_GLOB}" alt="" title="" border="0" /></td><td align="center" style="background-color:#{ctracker_message.ROW_COLOR};"><span class="gensmall">{ctracker_message.L_MESSAGE_TEXT}</span></td></tr>
<!-- BEGIN switch_mark_action -->
<tr><td align="center" class="row2" colspan="2"><form method="post" action="{ctracker_message.switch_mark_action.S_MARK_ACTION}" style="display:inline"><input type="hidden" name="marknow" value="{ctracker_message.switch_mark_action.S_MARK_VALUE}" /><input type="hidden" name="ct_token" value="{ctracker_message.switch_mark_action.S_MARK_TOKEN}" /><input class="liteoption" type="submit" value="{ctracker_message.switch_mark_action.L_MARK_MESSAGE}" /></form></td></tr>
<!-- END switch_mark_action -->
</table></div><br />
<!-- END ctracker_message -->
{CALENDAR_BOX}
<table width="100%" border="0" cellspacing="0" cellpadding="10">
<tr>
<td>
