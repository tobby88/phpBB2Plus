<h1>{L_CAT_TITLE}</h1>

<p>{L_CAT_EXPLAIN}</p>
<table width="100%" cellpadding="2" cellspacing="1" border="0" class="forumline">
  <tr>
	<th class="thHead" colspan="5">{L_CAT_TITLE}</th>
  </tr>
<!-- BEGIN cat_row -->
	<!-- IF cat_row.IS_HIGHER_CAT -->
<tr>
	<td class="cat" valign="middle" nowrap="nowrap">{cat_row.PRE}&nbsp;&raquo;&nbsp;<a href="{cat_row.U_CAT}" class="cattitle">{cat_row.CAT_NAME}</a></td>
	<td class="cat" align="center" valign="middle"><span class="gen"><a href="{cat_row.U_CAT_EDIT}">{L_EDIT}</a></span></td>
	<td class="cat" align="center" valign="middle"><span class="gen"><a href="{cat_row.U_CAT_DELETE}">{L_DELETE}</a></span></td>
	<td class="cat" align="center" valign="middle" nowrap="nowrap"><span class="gen">{cat_row.S_CAT_MOVE_UP_FORM} {cat_row.S_CAT_MOVE_DOWN_FORM}</span></td>
	<td class="cat" align="center" valign="middle"><span class="gen">{cat_row.S_CAT_RESYNC_FORM}</span></td>
</tr>
	<!-- ELSE -->
<tr>
	<td class="row1" valign="middle" nowrap="nowrap">{cat_row.PRE}&nbsp;&raquo;&nbsp;<a href="{cat_row.U_CAT}" class="cattitle">{cat_row.CAT_NAME}</a></td>
	<td class="row1" align="center" valign="middle"><span class="gen"><a href="{cat_row.U_CAT_EDIT}">{L_EDIT}</a></span></td>
	<td class="row1" align="center" valign="middle"><span class="gen"><a href="{cat_row.U_CAT_DELETE}">{L_DELETE}</a></span></td>
	<td class="row1" align="center" valign="middle" nowrap="nowrap"><span class="gen">{cat_row.S_CAT_MOVE_UP_FORM} {cat_row.S_CAT_MOVE_DOWN_FORM}</span></td>
	<td class="row1" align="center" valign="middle"><span class="gen">{cat_row.S_CAT_RESYNC_FORM}</span></td>
</tr>
	<!-- ENDIF -->
<!-- END cat_row -->

  <tr> 
	<td class="cat" align="center" colspan="5"><form method="post" action="{S_CAT_ACTION}" style="display: inline;">{S_HIDDEN_FIELDS}<input type="submit" class="liteoption" name="addcategory" value="{L_CREATE_CATEGORY}" /></form> {S_SYNC_ALL_FORM}</td>
  </tr>
</table>
