<!-- phpBB Arcade Log Template v2.1.8 -->

<table width="100%" cellpadding="4" cellspacing="0" border="0" align="center" class="bodyline">
  <tr>
    <th class="thHead" colspan="3">{LOG_TXT}</th>
  </tr>
  <tr>
    <td class="catTop" colspan="3"><span class="gensmall">{LOG_TXT_INFO}</span></td>
  </tr>
  <tr>
    <td class="catBottom" colspan="3">
      <form action="{S_ACTION}" method="post" name="sort">
        <select name="sort" onchange="if(this.options[this.selectedIndex].value >= 0){ forms['sort'].submit() }">
          <option value="-1">----------</option>
          <option value="0">{RECORD_TXT}</option>
          <option value="1">BAD_GAME</option>
          <option value="2">ERROR</option>
          <option value="3">GAME</option>
          <option value="4">GAME_ERROR</option>
          <option value="5">POST</option>
        </select>
      </form>
    </td>
  </tr>
  <form action="{S_ACTION}" method="post">
<!-- BEGIN log_record_none -->
    <td colspan="3"><center>{NONE}</center></td>
<!-- END log_record_none -->
<!-- BEGIN log_record -->
  <tr>
    <td width="5%"><input type="checkbox" name="record_no[]" value="{log_record.RECORD_NO}"></td>
    <td>&raquo; <i>{log_record.RECORD_DATE}</i> &raquo; {log_record.RECORD_NAME} &raquo; <b><i>{log_record.RECORD_USERNAME}</i></b> &raquo; {log_record.RECORD_DATA}</td>
  </tr>
<!-- END log_record -->
  <tr>
    <input type="hidden" name="sort" value="{SORT}">
    <td width="5%" class="catLeft"><input type="image" value="delete" src="{DELETE_IMG}" border="0" /></td>
  </form>  
    <td width="90%" class="cat">
      <form action="{S_ACTION}" method="post"><center>
        <input type="submit" name="purge" value="{PURGE}">
        <input type="hidden" name="sort" value="{SORT}">
      </center></form>
    </td>
    <td width="5%" class="catRight"> </td>
  </tr>
</table>

<table width="100%" cellspacing="2" cellpadding="2" border="0">
	<tr> 
		<td width="15%"><span class="nav">&nbsp;{PAGE_NO}&nbsp;</span></td>
		<td align="right"><span class="nav">&nbsp;{PAGINATION}&nbsp;</span></td>
	</tr>
</table>

<br />

<center><span class="gensmall">{RECORDS}<br><br />{VERSION}</center></span>  
