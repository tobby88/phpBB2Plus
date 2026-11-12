<script type="text/javascript" src="includes/javascript/links_form.js"></script>

<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
	<tr> 
	  <td align="right" style="font-weight: bold;font-size: 11px; color: orange">&#149;&nbsp;<a href="{U_SITE_LINKS}">{LINKS_HOME}</a>&nbsp;&nbsp;&#149;&nbsp;<a href="{U_SITE_SEARCH}">{L_SEARCH_SITE}</a>&nbsp;&nbsp;&#149;&nbsp;<a href="{U_SITE_TOP}">{L_DESCEND_BY_HITS}</a>&nbsp;&nbsp;&#149;&nbsp;<a href="{U_SITE_NEW}">{L_DESCEND_BY_JOINDATE}</a>
	  </td>
	</tr>
</table>
<table border="0" cellpadding="0" cellspacing="0" width="100%">
	<tr> 
	  <!-- BEGIN lock -->
	  <td width="10%" nowrap="nowrap" valign="top">
		<!-- BEGIN logout -->
		<table border="0" cellpadding="0" cellspacing="1" width="100%" class="forumline">
		  <tr> 
			<th class="thHead" align="left">&nbsp;{L_LOGIN}</th>
		  </tr>
		<tr><td class="row1">
		<form method="post" action="{S_LOGIN_ACTION}">
		{S_LOGIN_FIELDS}
		<table border="0" cellpadding="2" cellspacing="0" width="100%">
		<tr>
			<td class="row1">
			  <span class="gensmall">{L_LINK_REGISTER_GUEST_RULE}</span>
			</td>
		  </tr>
		  <tr>
			<td class="row1">
			  <span class="gensmall">{L_USERNAME}:</span><br /><input type="text" name="username" size="25" maxlength="40" value="" class="post" />
			</td>
		  </tr>
		  <tr>
			<td class="row1">
			  <span class="gensmall">{L_PASSWORD}:<br /><input type="password" name="password" size="25" maxlength="32" class="post" />
			</td>
		  </tr>
		  <tr> 
			<td class="row1"><span class="gensmall">{L_AUTO_LOGIN}: <input type="checkbox" name="autologin" /></span></td>
		  </tr>
		  <tr>
			<td class="row1" align="center"><input type="hidden" name="redirect" value="{U_SITE_LINKS}" /><input type="submit" name="login" class="mainoption" value="{L_LOGIN}" /><br /><br /><span class="gensmall"><a href="{U_REGISTER}">{L_REGISTER}</a></span></td>
		  </tr>
		</table></form>
		</td></tr>
		</table>
		<!-- END logout -->
		
		<!-- BEGIN submit -->
        <!-- INCLUDE links_submit.tpl -->
        <!-- END submit -->
	  </td>
	  <td width="10" nowrap="nowrap">&nbsp;</td>
	  <!-- END lock -->
	  <td width="100%" nowrap="nowrap" valign="top">
		<table border="0" cellpadding="3" cellspacing="1" width="100%" class="forumline">
		<tr>
			  <th class="thHead" align="left" colspan="2">&nbsp;{L_LINK_TITLE1}</th>
		</tr>
		<!-- BEGIN linkrow -->
		<tr>
		<td rowspan="2" valign="top" align="right" class="{linkrow.ROW_CLASS}">&nbsp;{linkrow.LINK_LOGO}&nbsp;</td>
		<td width="100%" class="{linkrow.ROW_CLASS}">
		<a href="{linkrow.LINK_URL}" target="_blank" class="genmed" rel="noopener noreferrer">{linkrow.LINK_TITLE}</a><br /><span class="genmed">{linkrow.LINK_DESC}</span>
		</td>
		</tr>
		<tr>
		<td class="{linkrow.ROW_CLASS}" align="center"><span class="genmed">{L_LINK_CATEGORY}: {linkrow.LINK_CATEGORY}&nbsp;&nbsp;,&nbsp;{L_LINK_JOINED}: {linkrow.LINK_JOINED}&nbsp;&nbsp;,&nbsp;{L_LINK_HITS}: {linkrow.LINK_HITS}&nbsp;&nbsp;,&nbsp;{L_LINK_SUBMITER}&nbsp;{linkrow.U_LINK_USER}</span></td>
		</tr>
		<!-- END linkrow -->
		</table><br />
		<table width="100%" cellspacing="0" cellpadding="0" border="0">
		  <tr> 
			<td valign="top"><span class="nav">{PAGE_NUMBER}</span></td>
			<td align="right"><span class="gensmall">{S_TIMEZONE}</span><br /><span class="nav">{PAGINATION}</span></td>
		  </tr>
		</table>	  
	  </td>
	</tr> 
</table>
