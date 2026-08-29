<center><b><span class="genmed"><h3>{GAME_TITLE}</h3></span></b></center>

<form action="{S_GAME_ACTION}" method="post">
<table width="100%" cellspacing="2" cellpadding="2" border="0">
  <tr>
	<td class="nav"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a> &raquo; <a class="nav" href="{U_ARCADE_CAT}">{L_ARCADE_CAT}</a> &raquo; <a class="nav" href="{U_ARCADE}">{L_ARCADE}</a> &raquo; <a class="nav" href="{U_GAME_TITLE}">{L_GAME_TITLE}</a></span></td>
  </tr>
</table>

<table width="100%" cellpadding="3" cellspacing="1" border="0" class="forumline">
  <tr> 
    <th class="thTop" height="25" colspan="2">{L_COMMENTS}</th>
  </tr>
  <tr> 
    <td colspan="2" align="center" class="row1">
	  <table width="100%" align="left" cellspacing="2" cellpadding="2" border="0">
        <tr> 
          <td width="15%" align="right" valign="top" nowrap class="genmed"><b>{L_PLAYED}:</b></td>
          <td width="20%" valign="top" class="genmed">{PLAYED}</td>
          <td width="30%" align="right" valign="top" class="genmed">&nbsp;</td>
          <td width="15%" align="right" valign="top" class="genmed"><b>{L_POSTER}</b></td>
          <td width="20%" valign="top" class="genmed">{POSTER}</td>
        </tr>
        <tr> 
          <td height="20" align="right" valign="top" nowrap class="genmed"><b>{L_ADDED}:</b></td>
          <td valign="top" class="genmed">{DATE_ADDED}</td>
          <td valign="top" align="right" class="genmed">&nbsp;</td>
          <td valign="top" align="right" class="genmed"><b>{L_COMMENTS}:</b></td>
          <td valign="top" class="genmed">{GAME_COMMENTS}</td>
        </tr>
      </table></td>
  </tr>
  
<!-- BEGIN commentrow -->
  <tr id="comment-{commentrow.ID}">
	<td class="row3" width="85%" height="25"><span class="name"><b>{L_POSTER}: {commentrow.POSTER} @ {commentrow.TIME}</b></span></td>
	<td class="row3" width="15%"><span class="genmed">{commentrow.EDIT_IMG}&nbsp;{commentrow.DELETE_IMG}&nbsp;{commentrow.IP_IMG}</span></td>
  </tr>
  <tr>
	<td class="row1" width="85%"><span class="postbody">{commentrow.TEXT}</span><br />
              <span class="gensmall">{commentrow.EDIT_INFO}</span> </td>
	<td class="row1" width="15%" valign="top"><span class="gensmall"><u>{USER_HEADER}</u><br /><br />{commentrow.USER_STATS}</span></td>
      </tr>
<!-- END commentrow -->
<!-- BEGIN switch_comment -->
  <tr>
	<td class="cat" align="center" height="28" colspan="2"><span class="gensmall">{L_ORDER}:</span>
	<select name="sort_order"><option {SORT_ASC} value='ASC'>{L_ASC}</option><option {SORT_DESC} value='DESC'>{L_DESC}</option></select>&nbsp;<input type="submit" name="submit" value="{L_SORT}" class="liteoption" /></td>
  </tr>
<!-- END switch_comment -->
</table>
<!-- BEGIN switch_comment -->
<table width="100%" cellspacing="2" border="0" cellpadding="2">
  <tr>
	<td width="100%"><span class="nav">{PAGE_NUMBER}</span></td>
	<td align="right" nowrap><span class="gensmall">{S_TIMEZONE}</span><br /><span class="nav">{PAGINATION}</span></td>
  </tr>
</table>
<!-- END switch_comment -->
</form>

<!-- BEGIN switch_comment_post -->
<script type="text/javascript">
<!--
function checkForm() {
	formErrors = false;

	if (document.commentform.comment.value.length < 5)
	{
		formErrors = "{L_COMMENT_NO_TEXT}";
	}
	else if (document.commentform.comment.value.length > {S_MAX_LENGTH})
	{
		formErrors = "{L_COMMENT_TOO_LONG}";
	}

	if (formErrors) {
		alert(formErrors);
		return false;
	} else {
		return true;
	}
}
// -->
</script>

<form name="commentform" action="{S_ARCADE_ACTION}" method="post" onsubmit="return checkForm();">
<table width="100%" cellpadding="3" cellspacing="1" border="0" class="forumline">
  <tr>
	<th class="thTop" height="25" colspan="2">{L_POST_YOUR_COMMENT}</th>
  </tr>
  <tr>
	<td class="row2" valign="top" align="center"><textarea name="comment" class="post" cols="70" rows="7">{S_MESSAGE}</textarea></td>
  </tr>
  <tr>
	<td class="cat" align="center" colspan="2" height="28"><input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" /></td>
  </tr>
</table>
{S_HIDDEN_FIELDS}
</form>
<!-- END switch_comment_post -->

<div align="center"><span class="gensmall">{ARCADE_MOD}</span></div>
