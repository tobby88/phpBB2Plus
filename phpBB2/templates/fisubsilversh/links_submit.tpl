<form name="linkdata" method="post" action="{U_LINK_REG}" onsubmit="return phpbbLinkForm.validate(this);" data-link-error="{LINK_FORM_ERROR_ATTR}" data-preview-error="{LINK_PREVIEW_ERROR_ATTR}">
{S_LINK_TOKEN}
<table border="0" cellpadding="0" cellspacing="1" width="100%" class="forumline">
  <tr><th class="thHead" align="left">&nbsp;{L_LINK_REGISTER}</th></tr>
  <tr><td class="row1"><table border="0" cellpadding="2" cellspacing="0" width="100%">
    <tr><td class="row1"><span class="gensmall">{L_LINK_REGISTER_RULE}</span></td></tr>
    <tr><td class="row1"><label class="gensmall" for="link-title">{L_LINK_TITLE}</label><br /><input id="link-title" type="text" name="link_title" value="" size="15" maxlength="200" data-link-maxchars="100" oninput="phpbbLinkForm.limit(this);" required="required" class="post" style="width:160px" /></td></tr>
    <tr><td class="row1"><label class="gensmall" for="link-url">{L_LINK_URL}</label><br /><input id="link-url" type="url" name="link_url" value="" placeholder="https://" size="15" maxlength="100" required="required" class="post" style="width:160px" /></td></tr>
    <tr><td class="row1"><label class="gensmall" for="link-logo">{L_LINK_LOGO_SRC}</label><br /><input id="link-logo" type="url" name="link_logo_src" value="" placeholder="https://" size="15" maxlength="120" {LINK_LOGO_REQUIRED} class="post" style="width:160px" />
      <br /><button type="button" class="liteoption" onclick="return phpbbLinkForm.preview(this.form);">{L_PREVIEW}</button>
      <span data-link-preview="true" style="display:none"><br /><img alt="{LINK_PREVIEW_ALT_ATTR}" referrerpolicy="no-referrer" style="max-width:200px;max-height:100px" /></span>
    </td></tr>
    <tr><td class="row1"><label class="gensmall" for="link-category">{L_LINK_CATEGORY}</label><br /><select id="link-category" name="link_category" required="required" style="width:160px"><option value="">----------------</option>{LINK_CAT_OPTION}</select></td></tr>
    <tr><td class="row1"><label class="gensmall" for="link-description">{L_LINK_DESC}</label><br /><textarea id="link-description" name="link_desc" cols="15" rows="4" maxlength="510" data-link-maxchars="255" oninput="phpbbLinkForm.limit(this);" required="required" class="post" style="width:160px"></textarea></td></tr>
  </table></td></tr>
  <tr><td class="cat" align="center"><input type="submit" name="addlink" value="{L_SUBMIT}" class="liteoption" /></td></tr>
</table>
</form>
