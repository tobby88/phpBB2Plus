<!-- BEGIN xs_file_version -->
/***************************************************************************
 *                                cache.tpl
 *                                ---------
 *   copyright            : (C) 2003 - 2005 CyberAlien
 *   support              : http://www.phpbbstyles.com
 *
 *   version              : 2.3.1
 *
 *   file revision        : 55
 *   project revision     : 78
 *   last modified        : 05 Dec 2005  13:54:55
 *
 ***************************************************************************/
<!-- END xs_file_version -->

<h1>{L_XS_MANAGE_CACHE}</h1>

<p>
{L_XS_MANAGE_CACHE_EXPLAIN2}
{RESULT}
</p>

<table cellpadding="4" cellspacing="1" border="0" class="forumline" align="center">
<tr>
	<th class="thHead" colspan="4">{L_XS_MANAGE_CACHE}</th>
</tr>
<tr>
	<td class="catLeft" align="center"><span class="gen">{L_XS_TEMPLATE}</span></td>
	<td class="cat" align="center"><span class="gen">{L_XS_STYLES}</span></td>
	<td class="cat" align="center"><form method="post" action="{S_CACHE_ACTION}">{S_FORM_TOKEN}<button type="submit" name="clear_cache" value="1" class="liteoption">{L_XS_CLEAR_ALL_LC}</button></form></td>
	<td class="catRight" align="center"><form method="post" action="{S_CACHE_ACTION}" onsubmit="return confirm('{L_XS_CACHE_CONFIRM}');">{S_FORM_TOKEN}<button type="submit" name="compile_cache" value="1" class="liteoption">{L_XS_COMPILE_ALL_LC}</button></form></td>
</tr>
<!-- BEGIN styles -->
<tr> 
	<td class="{styles.ROW_CLASS}" align="left" valign="middle"><span class="gen">{styles.TPL}</span></td>
	<td class="{styles.ROW_CLASS}" align="left" valign="middle"><span class="gen">{styles.STYLES}</span></td>
	<td class="{styles.ROW_CLASS}" align="center" valign="middle" nowrap="nowrap"><form method="post" action="{S_CACHE_ACTION}">{S_FORM_TOKEN}<input type="hidden" name="template" value="{styles.TPL_VALUE}" /><button type="submit" name="clear_cache" value="1" class="liteoption">{L_XS_CLEAR_CACHE_LC}</button></form></td>
	<td class="{styles.ROW_CLASS}" align="center" valign="middle" nowrap="nowrap"><form method="post" action="{S_CACHE_ACTION}">{S_FORM_TOKEN}<input type="hidden" name="template" value="{styles.TPL_VALUE}" /><button type="submit" name="compile_cache" value="1" class="liteoption">{L_XS_COMPILE_CACHE_LC}</button></form></td>
</tr>
<!-- END styles -->
</table>
<br />
