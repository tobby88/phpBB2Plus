<script language="JavaScript" type="text/javascript">
<!--
var inpIndex = 0;

function addInput()
{
	if (inpIndex >= ({MAX_UPLOADS} - 1))
	{
		return;
	}

	inpIndex++;
	var imageContainer = document.getElementById('parah');
	var imageParagraph = document.createElement('p');
	var imageInput = document.createElement('input');
	imageParagraph.id = 'parah-' + inpIndex;
	imageInput.type = 'file';
	imageInput.id = 'pic_file-' + inpIndex;
	imageInput.name = 'pic_file-' + inpIndex;
	imageInput.className = 'post';
	imageInput.size = '49';
	imageParagraph.appendChild(imageInput);
	imageContainer.appendChild(imageParagraph);

	var thumbnailContainer = document.getElementById('parat');
	if (thumbnailContainer)
	{
		var thumbnailParagraph = document.createElement('p');
		var thumbnailInput = document.createElement('input');
		thumbnailParagraph.id = 'parat-' + inpIndex;
		thumbnailInput.type = 'file';
		thumbnailInput.id = 'pic_thumbnail-' + inpIndex;
		thumbnailInput.name = 'pic_thumbnail-' + inpIndex;
		thumbnailInput.className = 'post';
		thumbnailInput.size = '49';
		thumbnailParagraph.appendChild(thumbnailInput);
		thumbnailContainer.appendChild(thumbnailParagraph);
	}
}

function deleteInput()
{
	if (inpIndex <= 0)
	{
		return;
	}

	var imageParagraph = document.getElementById('parah-' + inpIndex);
	if (imageParagraph)
	{
		imageParagraph.parentNode.removeChild(imageParagraph);
	}

	var thumbnailParagraph = document.getElementById('parat-' + inpIndex);
	if (thumbnailParagraph)
	{
		thumbnailParagraph.parentNode.removeChild(thumbnailParagraph);
	}

	inpIndex--;
}

function openUploadProgress()
{
	var width = 460;
	var height = 150;
	var left = (screen.width - width) / 2;
	var top = (screen.height - height) / 2;
	var properties = 'height=' + height + ',width=' + width + ',top=' + top + ',left=' + left + ',scrollbars=no,resizable=no,menubar=no,status=no,toolbar=no';
	var progressWindow = window.open('album_nuffload_pbar.php?sessionid={PSID}', 'Uploader', properties);
	if (progressWindow)
	{
		progressWindow.focus();
	}
}

function postIt()
{
	if (!checkAlbumForm())
	{
		return false;
	}

<!-- BEGIN switch_show_progress_bar -->
	openUploadProgress();
<!-- END switch_show_progress_bar -->
	return true;
}

function checkAlbumForm() {
	formErrors = false;

	if (document.upload.pic_title.value.length < 2)
	{
		formErrors = "{L_UPLOAD_NO_TITLE}";
	}
	else if (document.upload.pic_file.value.length < 2)
	{
		formErrors = "{L_UPLOAD_NO_FILE}";
	}
	else if (document.upload.pic_desc.value.length > {S_PIC_DESC_MAX_LENGTH})
	{
		formErrors = "{L_DESC_TOO_LONG}";
	}
	//--- Album Category Hierarchy : begin
   	//--- Version : 1.3.0
	else
	{
		switch (document.upload.cat_id.value)
		{
			case '{S_ALBUM_ROOT_CATEGORY}':
			case '{S_ALBUM_JUMPBOX_SEPERATOR}':
			case '{S_ALBUM_JUMPBOX_USERS_GALLERY}':
			case '{S_ALBUM_JUMPBOX_PUBLIC_GALLERY}':
				formErrors = "{L_NO_VALID_CAT_SELECTED}";
			default:
				// do nothing
		}
	}
    //--- Album Category Hierarchy : end
	if (formErrors) {
		alert(formErrors);
		return false;
	} else {
		return true;
	}
}
// -->
</script>

<form name="upload" action="{S_ALBUM_ACTION}" method="post" enctype="multipart/form-data" onsubmit="return postIt();">
<table width="100%" cellspacing="2" cellpadding="2" border="0">
  <tr>
	<td class="nav"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a> -> <a class="nav" href="{U_ALBUM}">{L_ALBUM}</a> -> <a class="nav" href="{U_VIEW_CAT}">{CAT_TITLE}</a></span></td>
  </tr>
</table>

<table width="100%" cellpadding="3" cellspacing="1" border="0" class="forumline">
  <tr>
	<th class="thTop" height="25" colspan="2">{L_UPLOAD_PIC}</th>
  </tr>
<!-- BEGIN switch_user_logged_out -->
  <tr>
	<td class="row1" width="30%" height="28"><span class="gen">{L_USERNAME}:</span></td>
	<td class="row2"><input class="post" type="text" name="pic_username" size="32" maxlength="32" /></td>
  </tr>
<!-- END switch_user_logged_out -->
  <tr>
	<td class="row1" height="28"><span class="gen">{L_PIC_TITLE}:</span></td>
	<td class="row2"><input class="post" type="text" name="pic_title" size="60" /></td>
  </tr>
  <tr>
	<td class="row1" valign="top" height="28"><span class="gen">{L_PIC_DESC}:<br />
	</span><span class="genmed">{L_PLAIN_TEXT_ONLY}<br />{L_MAX_LENGTH}: <b>{S_PIC_DESC_MAX_LENGTH}</b></span></td>
	<td class="row2"><textarea class="post" cols="60" rows="4" name="pic_desc" size="60"></textarea></td>
  </tr>
  <tr>
	<td class="row1"><span class="gen">{L_UPLOAD_PIC_FROM_MACHINE}:
	<!-- BEGIN switch_multiple_uploads -->
	<br /><a href="javascript:addInput();">{ADD_FIELD}</a><br /><a href="javascript:deleteInput();">{REMOVE_FIELD}</a>
	<!-- END switch_multiple_uploads -->
	</span></td>
	<td class="row2" id="parah"><input class="post" type="file" name="pic_file" size="49" /></td>
  </tr>
<!-- BEGIN switch_manual_thumbnail -->
  <tr>
	<td class="row1"><span class="gen">{L_UPLOAD_THUMBNAIL}:<br /></span><span class="gensmall">{L_UPLOAD_THUMBNAIL_EXPLAIN}</span></td>
	<td class="row2" id="parat"><input class="post" type="file" name="pic_thumbnail" size="49" /></td>
  </tr>
  <tr>
	<td class="row1" height="28"><span class="gen">{L_THUMBNAIL_SIZE}:</span></td>
	<td class="row2"><span class="gen"><b>{S_THUMBNAIL_SIZE}</b></span></td>
  </tr>
<!-- END switch_manual_thumbnail -->
  <tr>
	<td height="28" class="row1"><span class="gen">{L_UPLOAD_TO_CATEGORY}:</span></td>
	<td class="row2">{SELECT_CAT}</td>
  </tr>
  <tr>
	<td class="row1" height="28"><span class="gen">{L_MAX_FILESIZE}:</span></td>
	<td class="row2"><span class="gen"><b>{S_MAX_FILESIZE}</b></span></td>
  </tr>
  <tr>
	<td class="row1" height="28"><span class="gen">{L_MAX_WIDTH}:</span></td>
	<td class="row2"><span class="gen"><b>{S_MAX_WIDTH}</b></span></td>
  </tr>
  <tr>
	<td class="row1" height="28"><span class="gen">{L_MAX_HEIGHT}:</span></td>
	<td class="row2"><span class="gen"><b>{S_MAX_HEIGHT}</b></span></td>
  </tr>
  <tr>
	<td class="row1" height="28"><span class="gen">{L_ALLOWED_JPG}:</span></td>
	<td class="row2"><span class="gen"><b>{S_JPG}</b></span></td>
  </tr>
  <tr>
	<td class="row1" height="28"><span class="gen">{L_ALLOWED_PNG}:</span></td>
	<td class="row2"><span class="gen"><b>{S_PNG}</b></span></td>
  </tr>
  <tr>
	<td class="row1" height="28"><span class="gen">{L_ALLOWED_GIF}:</span></td>
	<td class="row2"><span class="gen"><b>{S_GIF}</b></span></td>
  </tr>
  <tr>
	<td class="row1" height="28"><span class="gen">{L_ALLOWED_ZIP}:</span></td>
	<td class="row2"><span class="gen"><b>{S_ZIP}</b></span></td>
  </tr>
  <tr>
	<td class="cat" align="center" height="28" colspan="2"><input type="reset" value="{L_RESET}" class="liteoption" />&nbsp;&nbsp;&nbsp;<input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" /></td>
  </tr>
</table>
<table border="0" cellpadding="0" cellspacing="0" class="tbl"><tr><td class="tbll"><img src="images/spacer.gif" alt="" width="8" height="4" /></td><td class="tblbot"><img src="images/spacer.gif" alt="" width="8" height="4" /></td><td class="tblr"><img src="images/spacer.gif" alt="" width="8" height="4" /></td></tr></table>
</form>

<br />

<!--
You must keep my copyright notice visible with its original content
-->
<div align="center" class="copyright">Powered by Photo Album {ALBUM_VERSION} &copy; 2002-2003 <a href="http://smartor.is-root.com" target="_blank">Smartor</a></div>