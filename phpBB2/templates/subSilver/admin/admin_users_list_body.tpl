<h1>{L_ADMIN_USERS_LIST} 2.1</h1>
<p>{L_ADMIN_USERS_LIST_EXPLAIN}</p>
<p>{L_THERE_ARE} {TOTAL_USERS} {L_MEMBERS}.</p>

<form action="{U_LIST_ACTION}" method="get">
<table width="100%" cellpadding="3" cellspacing="1" border="0">
<tr>
  <td>{L_SORT_BY}: <select name="sort">{S_SORT_OPTIONS}</select></td>
  <td>{L_ORDER}: <select name="order"><option value="ASC"{ASC_SELECTED}>{L_SORT_ASCENDING}</option><option value="DESC"{DESC_SELECTED}>{L_SORT_DESCENDING}</option></select></td>
  <td>{L_SHOW}: <input type="text" name="show" size="4" value="{S_SHOW}" /></td>
  <td><input type="submit" value="{L_SORT}" class="liteoption" /></td>
</tr>
</table>
</form>

<p class="genmed" style="text-align:center">
<!-- BEGIN alpha --><a href="{alpha.U_LETTER}">{alpha.LETTER}</a>&nbsp;<!-- END alpha -->
</p>

<form action="{U_LIST_ACTION}" method="post" name="userlistform">
<table width="100%" cellpadding="5" cellspacing="1" border="0" class="forumline">
<tr>
  <th><input type="checkbox" onclick="for(var i=0;i<this.form.elements.length;i++){if(this.form.elements[i].name=='u[]')this.form.elements[i].checked=this.checked;}" /></th>
  <th>{L_ID}</th><th>{L_ACTION}</th><th>{L_USERNAME}</th><th>{L_EMAIL}</th><th>{L_POSTS}</th><th>{L_JOINED}</th><th>{L_LAST_VISIT}</th><th>{L_ACTIVE}</th>
</tr>
<!-- BEGIN userrow -->
<tr>
  <td class="{userrow.COLOR}" align="center"><input type="checkbox" name="u[]" value="{userrow.NUMBER}" /></td>
  <td class="{userrow.COLOR}" align="center">{userrow.NUMBER}</td>
  <td class="{userrow.COLOR}" align="center"><span class="gensmall"><a href="{userrow.U_ADMIN_USER}">{L_EDIT}</a><br /><a href="{userrow.U_ADMIN_USER_AUTH}">{L_PERMISSION}</a></span></td>
  <td class="{userrow.COLOR}"><span class="genmed">{userrow.USERNAME}</span></td>
  <td class="{userrow.COLOR}"><span class="genmed">{userrow.EMAIL}</span></td>
  <td class="{userrow.COLOR}" align="center">{userrow.POSTS}</td>
  <td class="{userrow.COLOR}" align="center">{userrow.JOINED}</td>
  <td class="{userrow.COLOR}" align="center">{userrow.LAST_VISIT}</td>
  <td class="{userrow.COLOR}" align="center">{userrow.ACTIVE}</td>
</tr>
<!-- END userrow -->
<tr>
  <td class="cat" colspan="9" align="center">
    {L_BULK_ACTION}: <select name="bulk_action"><option value="">--</option><option value="activate">{L_ACTIVATE}</option><option value="deactivate">{L_DEACTIVATE}</option><option value="ban">{L_BAN}</option><option value="unban">{L_UNBAN}</option><option value="group">{L_ADD_GROUP}</option></select>
    <select name="group_id">{S_GROUP_OPTIONS}</select>
    <input type="submit" value="{L_APPLY}" class="mainoption" />
  </td>
</tr>
</table>
</form>

<table width="100%" cellspacing="2" cellpadding="2" border="0"><tr><td><span class="nav">{PAGE_NUMBER}</span></td><td align="right"><span class="nav">{PAGINATION}</span></td></tr></table>
<br />
