<script type="text/javascript" src="includes/javascript/links_form.js"></script>

<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
	<tr> 
	  <!-- td align="left" class="nav"><a href="{U_INDEX}">{L_INDEX}</a> &raquo; <a href="{U_SITE_LINKS}">{L_SITE_LINKS}</a></td -->
	  <td align="right" style="font-weight: bold;font-size: 11px; color: orange">&#149;&nbsp;<a href="{U_SITE_SEARCH}">{L_SEARCH_SITE}</a>&nbsp;&nbsp;&#149;&nbsp;<a href="{U_SITE_TOP}">{L_DESCEND_BY_HITS}</a>&nbsp;&nbsp;&#149;&nbsp;<a href="{U_SITE_NEW}">{L_DESCEND_BY_JOINDATE}</a>
	  </td>
	</tr>
</table>
<table border="0" cellpadding="0" cellspacing="0" width="100%">
	<tr>
	  <td width="250" nowrap="nowrap" valign="top">
		<table border="0" cellpadding="3" cellspacing="1" width="100%" class="forumline">
			<tr>
			  <th class="thHead" align="left" nowrap="nowrap">{L_LINK_US}</th>
			</tr>
			<tr> 
			  <td class="row1">
			  	<br /><span class="gensmall"><center><img src="{U_SITE_LOGO}" border="0" alt="{SITENAME}" /></center><br />
			  	{L_LINK_US_EXPLAIN}</span><br />
			  	<table width="100%" cellspacing="0" cellpadding="0" border="0" align="center"> 

              <tr> 

               <td width="100%" align="center"><textarea name="link_us_syntax" rows="5" cols="20" wrap="virtual">{LINK_US_SYNTAX}</textarea></td>
				  </tr>
				</table>
			  </td>
			</tr>
		</table><br />
		<!-- BEGIN lock -->
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
		<!-- END lock -->
	  </td>
	  <td width="10" nowrap="nowrap">&nbsp;</td>
	  <td width="100%" nowrap="nowrap" valign="top">
		<table border="0" cellpadding="2" cellspacing="1" width="100%" class="forumline">
		<tr>
			  <th class="thHead" align="left" colspan="2">&nbsp;{L_LINK_CATEGORY}</th>
		</tr>
		<tr><td class="row1">
		<table border="0" cellpadding="4" cellspacing="3" width="100%">
			<!-- BEGIN catcol -->
			<tr>
				<!-- BEGIN linkrow -->
			        <td width="{LINK_WIDTH}%" nowrap="nowrap" class="postbody"><a href="{catcol.linkrow.LINK_URL}"><img src="{FOLDER_IMG}" alt="{catcol.linkrow.LINK_TITLE}" align="absmiddle" border="0" /></a>&nbsp;<a href="{catcol.linkrow.LINK_URL}">{catcol.linkrow.LINK_TITLE}</a>&nbsp;({catcol.linkrow.LINK_NUMBER})</td>
				<!-- END linkrow -->
      			</tr>
			<!-- END catcol -->
		</table>
		</td></tr>
		</table><br />
		<div align="center" style="font-family: Verdana; font-size: 10px; letter-spacing: -1px">Links MOD v1.2.2b by <a href="http://www.phpbb2.de" target="_blank" rel="noopener noreferrer">phpBB2.de</a> and OOHOO and <a href="http://mail.dhjh.tcc.edu.tw/~gzqbyr/" target="_gzqbyr">CRLin</a>.</div>
	  </td>
	</tr> 
</table>
