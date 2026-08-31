
		
<!-- BEGIN attach -->
	<br /><br />
          
	<!-- BEGIN denyrow -->
	<table width="95%" border="0" cellpadding="3" cellspacing="1" class="forumline" align="center">
	<tr>
		<th width="100%" align="center"><b><span class="gen">{postrow.attach.denyrow.L_DENIED}</span></b></th>
	</tr>
	</table>
	<!-- END denyrow -->
	<!-- BEGIN cat_stream -->
	<table width="95%" border="0" cellpadding="3" cellspacing="1" class="forumline" align="center">
	<tr>
		<th width="100%" colspan="3" align="center"><b><span class="gen">{postrow.attach.cat_stream.DOWNLOAD_NAME}</span></b></th>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_DESCRIPTION}:</span></td>
		<td width="75%" class="row3">
			<table width="100%" border="0" cellpadding="0" cellspacing="4" align="center">
			<tr>
				<td class="row3"><span class="genmed">{postrow.attach.cat_stream.COMMENT}</span></td>
			</tr>
			</table>
		</td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_FILESIZE}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.cat_stream.FILESIZE} {postrow.attach.cat_stream.SIZE_VAR}</td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{postrow.attach.cat_stream.L_DOWNLOADED_VIEWED}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.cat_stream.L_DOWNLOAD_COUNT}</span></td>
	</tr>
	<tr>
		<td colspan="2" align="center"><br />
		<video controls="controls" preload="metadata" src="{postrow.attach.cat_stream.U_DOWNLOAD_LINK}" style="width:480px;max-width:100%"><a href="{postrow.attach.cat_stream.U_DOWNLOAD_LINK}">{postrow.attach.cat_stream.DOWNLOAD_NAME}</a></video><br /><br />
		</td>
	</tr>
	</table>
	<!-- END cat_stream -->
	<!-- BEGIN cat_swf -->
	<table width="95%" border="0" cellpadding="3" cellspacing="1" class="forumline" align="center">
	<tr>
		<th width="100%" colspan="3" align="center"><b><span class="gen">{postrow.attach.cat_swf.DOWNLOAD_NAME}</span></b></th>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_DESCRIPTION}:</span></td>
		<td width="75%" class="row3">
			<table width="100%" border="0" cellpadding="0" cellspacing="4" align="center">
			<tr>
				<td class="row3"><span class="genmed">{postrow.attach.cat_swf.COMMENT}</span></td>
			</tr>
			</table>
		</td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_FILESIZE}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.cat_swf.FILESIZE} {postrow.attach.cat_swf.SIZE_VAR}</td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{postrow.attach.cat_swf.L_DOWNLOADED_VIEWED}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.cat_swf.L_DOWNLOAD_COUNT}</span></td>
	</tr>
	<tr>
		<td colspan="2" align="center"><br />
		<object type="application/x-shockwave-flash" data="{postrow.attach.cat_swf.U_DOWNLOAD_LINK}" width="{postrow.attach.cat_swf.WIDTH}" height="{postrow.attach.cat_swf.HEIGHT}">
			<param name="movie" value="{postrow.attach.cat_swf.U_DOWNLOAD_LINK}" />
			<param name="allowScriptAccess" value="never" />
			<a href="{postrow.attach.cat_swf.U_DOWNLOAD_LINK}">{postrow.attach.cat_swf.DOWNLOAD_NAME}</a>
		</object><br /><br />
		</td>
	</tr>
	</table>
	<!-- END cat_swf -->
	<!-- BEGIN cat_images -->
	<table width="95%" border="0" cellpadding="3" cellspacing="1" class="forumline" align="center">
	<tr>
		<th width="100%" colspan="3" align="center"><b><span class="gen">{postrow.attach.cat_images.DOWNLOAD_NAME}</span></b></th>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_DESCRIPTION}:</span></td>
		<td width="75%" class="row3">
			<table width="100%" border="0" cellpadding="0" cellspacing="4" align="center">
			<tr>
				<td class="row3"><span class="genmed">{postrow.attach.cat_images.COMMENT}</span></td>
			</tr>
			</table>
		</td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_FILESIZE}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.cat_images.FILESIZE} {postrow.attach.cat_images.SIZE_VAR}</td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{postrow.attach.cat_images.L_DOWNLOADED_VIEWED}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.cat_images.L_DOWNLOAD_COUNT}</span></td>
	</tr>
	<tr>
		<td colspan="2" align="center"><br /><img src="{postrow.attach.cat_images.IMG_SRC}" alt="{postrow.attach.cat_images.DOWNLOAD_NAME}" border="0" /><br /><br /></td>
	</tr>
	</table>
	<!-- END cat_images -->
	<!-- BEGIN cat_thumb_images -->
	<table width="95%" border="0" cellpadding="3" cellspacing="1" class="forumline" align="center">
	<tr>
		<th width="100%" colspan="3" align="center"><b><span class="gen">{postrow.attach.cat_thumb_images.DOWNLOAD_NAME}</span></b></th>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_DESCRIPTION}:</span></td>
		<td width="75%" class="row3">
			<table width="100%" border="0" cellpadding="0" cellspacing="4" align="center">
			<tr>
				<td class="row3"><span class="genmed">{postrow.attach.cat_thumb_images.COMMENT}</span></td>
			</tr>
			</table>
		</td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_FILESIZE}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.cat_thumb_images.FILESIZE} {postrow.attach.cat_thumb_images.SIZE_VAR}</td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{postrow.attach.cat_thumb_images.L_DOWNLOADED_VIEWED}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.cat_thumb_images.L_DOWNLOAD_COUNT}</span></td>
	</tr>
	<tr>
		<td colspan="2" align="center"><br /><a href="{postrow.attach.cat_thumb_images.IMG_SRC}" target="_blank"><img src="{postrow.attach.cat_thumb_images.IMG_THUMB_SRC}" alt="{postrow.attach.cat_thumb_images.DOWNLOAD_NAME}" border="0" /></a><br /><br /></td>
	</tr>
	</table>
	<!-- END cat_thumb_images -->
	<!-- BEGIN attachrow -->
	<table width="95%" border="0" cellpadding="3" cellspacing="1" class="forumline" align="center">
	<tr>
		<th width="100%" colspan="3" align="center"><b><span class="gen">{postrow.attach.attachrow.DOWNLOAD_NAME}</span></b></th>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_DESCRIPTION}:</span></td>
		<td width="75%" class="row3">
			<table width="100%" border="0" cellpadding="0" cellspacing="4" align="center">
			<tr>
				<td class="row3"><span class="genmed">{postrow.attach.attachrow.COMMENT}</span></td>
			</tr>
			</table>
		</td>
		<td rowspan="4" align="center" width="10%" class="row2">{postrow.attach.attachrow.S_UPLOAD_IMAGE}<br /><a href="{postrow.attach.attachrow.U_DOWNLOAD_LINK}" {postrow.attach.attachrow.TARGET_BLANK} class="genmed"><b>{L_DOWNLOAD}</b></a></td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_FILENAME}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.attachrow.DOWNLOAD_NAME}</span></td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{L_FILESIZE}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.attachrow.FILESIZE} {postrow.attach.attachrow.SIZE_VAR}</td>
	</tr>
	<tr>
		<td width="15%" class="row2"><span class="genmed">&nbsp;{postrow.attach.attachrow.L_DOWNLOADED_VIEWED}:</span></td>
		<td width="75%" class="row3"><span class="genmed">&nbsp;{postrow.attach.attachrow.L_DOWNLOAD_COUNT}</span></td>
	</tr>
	</table>
	
	<!-- END attachrow -->
	
<!-- END attach -->
