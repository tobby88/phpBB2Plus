<h1>{L_ALBUM_NUFFLOAD_CONFIG}</h1>

<p>{L_ALBUM_NUFFLOAD_CONFIG_EXPLAIN}</p>

<form action="{S_ALBUM_NUFFLOAD_CONFIG_ACTION}" method="post">
<table width="100%" cellpadding="4" cellspacing="1" border="0" class="forumline">
	<tr>
	  <th class="thHead" colspan="2">{L_PROGRESS_BAR_CONFIG}</th>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_PERL_UPLOADER}</span></td>
	  <td class="row2"><span class="genmed"><input type="radio" {PERL_UPLOADER_ENABLED} name="perl_uploader" value="1" />{L_YES}&nbsp;&nbsp;<input type="radio" {PERL_UPLOADER_DISABLED} name="perl_uploader" value="0" />{L_NO}</span></td>
	</tr>
	<tr>
	  <td class="row1" width="45%"><span class="genmed">{L_PATH_TO_BIN}</span></td>
	  <td class="row2"><input class="post" type="text" size="15" name="path_to_bin" value="{PATH_TO_BIN}" /></td>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_SHOW_PROGRESS_BAR}</span></td>
	  <td class="row2"><span class="genmed"><input type="radio" {SHOW_PROGRESS_BAR_ENABLED} name="show_progress_bar" value="1" />{L_YES}&nbsp;&nbsp;<input type="radio" {SHOW_PROGRESS_BAR_DISABLED} name="show_progress_bar" value="0" />{L_NO}</span></td>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_CLOSE_ON_FINISH}</span></td>
	  <td class="row2"><span class="genmed"><input type="radio" {CLOSE_ON_FINISH_ENABLED} name="close_on_finish" value="1" />{L_YES}&nbsp;&nbsp;<input type="radio" {CLOSE_ON_FINISH_DISABLED} name="close_on_finish" value="0" />{L_NO}</span></td>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_SIMPLE_FORMAT}</span></td>
	  <td class="row2"><span class="genmed"><input type="radio" {SIMPLE_FORMAT_ENABLED} name="simple_format" value="1" />{L_YES}&nbsp;&nbsp;<input type="radio" {SIMPLE_FORMAT_DISABLED} name="simple_format" value="0" />{L_NO}</span></td>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_MAX_PAUSE}</span></td>
	  <td class="row2"><input class="post" type="text" maxlength="2" size="2" name="max_pause" value="{MAX_PAUSE}" /></td>
	</tr>
	<tr>
	  <th class="thHead" colspan="2">{L_MULTIPLE_UPLOADS_CONFIG}</th>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_MULTIPLE_UPLOADS}</span></td>
	  <td class="row2"><span class="genmed"><input type="radio" {MULTIPLE_UPLOADS_ENABLED} name="multiple_uploads" value="1" />{L_YES}&nbsp;&nbsp;<input type="radio" {MULTIPLE_UPLOADS_DISABLED} name="multiple_uploads" value="0" />{L_NO}</span></td>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_MAX_UPLOADS}</span></td>
	  <td class="row2"><input class="post" type="text" maxlength="4" size="4" name="max_uploads" value="{MAX_UPLOADS}" /></td>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_ZIP_UPLOADS}</span></td>
	  <td class="row2"><span class="genmed"><input type="radio" {ZIP_UPLOADS_ENABLED} name="zip_uploads" value="1" />{L_YES}&nbsp;&nbsp;<input type="radio" {ZIP_UPLOADS_DISABLED} name="zip_uploads" value="0" />{L_NO}</span></td>
	</tr>
	<tr>
	  <th class="thHead" colspan="2">{L_RESIZE_PICS_CONFIG}</th>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_RESIZE_PIC}</span></td>
	  <td class="row2"><span class="genmed"><input type="radio" {RESIZE_PIC_ENABLED} name="resize_pic" value="1" />{L_YES}&nbsp;&nbsp;<input type="radio" {RESIZE_PIC_DISABLED} name="resize_pic" value="0" />{L_NO}</span></td>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_RESIZE_WIDTH}</span></td>
	  <td class="row2"><input class="post" type="text" maxlength="4" size="4" name="resize_width" value="{RESIZE_WIDTH}" /></td>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_RESIZE_HEIGHT}</span></td>
	  <td class="row2"><input class="post" type="text" maxlength="4" size="4" name="resize_height" value="{RESIZE_HEIGHT}" /></td>
	</tr>
	<tr>
	  <td class="row1"><span class="genmed">{L_RESIZE_QUALITY}</span></td>
	  <td class="row2"><input class="post" type="text" maxlength="3" size="3" name="resize_quality" value="{RESIZE_QUALITY}" /></td>
	</tr>
	<tr>
	  <td class="catBottom" colspan="2" align="center">{S_HIDDEN_FIELDS}<input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" />&nbsp;&nbsp;<input type="reset" value="{L_RESET}" class="liteoption" /></td>
	</tr>
</table></form>
<br clear="all" />
