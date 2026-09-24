{ERROR_BOX}
<form action="{S_CONFIRM_ACTION}" method="post">
<table class="forumline" width="100%" cellspacing="1" cellpadding="4" border="0">
  <tr><th class="thHead">{MESSAGE_TITLE}</th></tr>
  <tr><td class="row1" align="center">
    <p>{MESSAGE_TEXT}</p>
    {S_HIDDEN_FIELDS}
    <input type="submit" name="confirm" value="{L_YES}" class="mainoption" />
    <input type="submit" name="cancel" value="{L_NO}" class="liteoption" />
  </td></tr>
</table>
</form>
