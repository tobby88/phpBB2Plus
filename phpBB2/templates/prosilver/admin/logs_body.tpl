<h1>{L_LOG_TITLE}</h1>
<p>{L_LOG_EXPLAIN}</p>

<form action="{U_ACTION}" method="get">
<p>{L_SORT_BY}: <select name="sort">{S_SORT_OPTIONS}</select> {L_ORDER}: <select name="order"><option value="ASC"{ASC_SELECTED}>{L_ASC}</option><option value="DESC"{DESC_SELECTED}>{L_DESC}</option></select> <input type="submit" value="{L_SORT}" class="liteoption" /></p>
</form>

<form action="{U_ACTION}" method="post">
<input type="hidden" name="sid" value="{S_SID}" />
<table width="100%" cellpadding="5" cellspacing="1" border="0" class="forumline">
<tr><th>&nbsp;</th><th>{L_ACTION}</th><th>{L_TOPIC}</th><th>{L_USER}</th><th>{L_IP}</th><th>{L_DATE}</th></tr>
<!-- BEGIN logrow -->
<tr>
  <td class="{logrow.ROW_CLASS}" align="center"><input type="checkbox" name="log_id[]" value="{logrow.ID}" /></td>
  <td class="{logrow.ROW_CLASS}">{logrow.ACTION}</td>
  <td class="{logrow.ROW_CLASS}" align="center"><a href="{logrow.U_TOPIC}" target="_blank">{logrow.TOPIC}</a></td>
  <td class="{logrow.ROW_CLASS}"><a href="{logrow.U_USER}">{logrow.USERNAME}</a></td>
  <td class="{logrow.ROW_CLASS}">{logrow.IP}</td><td class="{logrow.ROW_CLASS}">{logrow.DATE}</td>
</tr>
<!-- END logrow -->
<tr><td colspan="6" class="cat" align="center"><input type="submit" name="delete_selected" value="{L_DELETE_SELECTED}" class="liteoption" /></td></tr>
</table>
<p>{L_PRUNE}: <input type="number" min="1" name="prune_days" value="90" size="5" /> {L_DAYS} <input type="submit" name="prune" value="{L_PRUNE}" class="liteoption" /></p>
</form>
<table width="100%"><tr><td>{PAGE_NUMBER}</td><td align="right">{PAGINATION}</td></tr></table>
<br />
