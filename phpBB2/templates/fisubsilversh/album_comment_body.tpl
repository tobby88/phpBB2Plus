<table width="100%" cellspacing="2" cellpadding="2" border="0">
  <tr>
	<td class="nav"><span class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a> {ALBUM_NAVIGATION_ARROW} <a href="{U_ALBUM}" class="nav">{L_ALBUM}</a>{NAV_CAT_DESC}</span></td>
    <td align="right">
		<form action="{U_ALBUM_SEARCH}" method="get">
			<span class="gensmall">{L_SEARCH_FOR}:
			<select name="mode">
				<option value="user">{L_USERNAME}</option>
				<option value="name">{L_NAME}</option>
				<option value="desc">{L_DESCRIPTION}</option>
			</select>
			{L_THAT_CONTAINS}: <input type="text" name="search" maxlength="20" />
			<input type="submit" value="{L_GO}" /></span>
		</form>
  </td>
 </tr>
</table>


<table width="100%" cellpadding="3" cellspacing="1" border="0" class="forumline">
  <tr>
	<th class="thTop" height="25" colspan="2">{PIC_TITLE}</th>
  </tr>
  <tr>
	<td class="row1" align="center"><img src="{U_PIC}" border="0" vspace="10" alt="{PIC_TITLE}" title="{PIC_TITLE}" /></td>
  </tr>
  <tr>
	<td class="row2"><table width="90%" align="center" border="0" cellpadding="3" cellspacing="2">
	  <tr>
		<td width="25%" align="right"><span class="genmed">{L_POSTER}:</span></td>
		<td><span class="genmed"><b>{POSTER}</b></span></td>
	  </tr>
	  <tr>
		<td valign="top" align="right"><span class="genmed">{L_PIC_TITLE}:</span></td>
		<td valign="top"><b><span class="genmed">{PIC_TITLE}</span></b></td>
	  </tr>
	  <tr>
		<td align="right"><span class="genmed">{L_POSTED}:</span></td>
		<td><b><span class="genmed">{PIC_TIME}</span></b></td>
	  </tr>
	  <tr>
		<td align="right"><span class="genmed">{L_VIEW}:</span></td>
		<td><b><span class="genmed">{PIC_VIEW}</span></b></td>
	  </tr>
	  <!-- BEGIN rate_switch -->
	  <tr>
		<td valign="top" align="right"><span class="genmed">{L_RATING}:</span></td>
		<td><b><span class="genmed">{PIC_RATING}</span></b></td>
	  </tr>
	  <!-- END rate_switch -->
	  <tr>
		<td valign="top" align="right"><span class="genmed">{L_PIC_DESC}:</span></td>
		<td valign="top"><b><span class="genmed">{PIC_DESC}</span></b></td>
	  </tr>
	</table></td>
  </tr>
</table>
<script type="text/javascript" src="album_mod/album_comment_form.js"></script>

<!-- BEGIN switch_comment_post -->
<form name="commentform" action="{S_ALBUM_ACTION}" method="post" onsubmit="return checkAlbumComment(this);" data-limit="{S_MAX_LENGTH}" data-empty="{L_COMMENT_NO_TEXT}" data-long="{L_COMMENT_TOO_LONG}">
{S_FORM_TOKEN}
<table width="100%" cellpadding="3" cellspacing="1" border="0" class="forumline">
  <tr>
	<th class="thTop" height="25" colspan="3">{L_POST_YOUR_COMMENT}</th>
  </tr>
  <tr>
	<td class="row1" valign="top" width="20%"><span class="genmed">{L_MESSAGE}<br>
		{L_MAX_LENGTH}: <b>{S_MAX_LENGTH}</b></span></td>
	<td class="row2" valign="top">
			
	<textarea name="comment" class="post" cols="60" rows="9">{S_MESSAGE}</textarea></td>
	
	<td class="row2" valign="middle" width="40%">  
	   <table border="0" cellspacing="0" cellpadding="5">
	      <tr>
	     	<!-- BEGIN smilies -->
	            <td><img src="{switch_comment_post.smilies.URL}" border="0" style="cursor: pointer;" data-code=" {switch_comment_post.smilies.CODE} " onclick="emotions(this.getAttribute('data-code'));" alt="{switch_comment_post.smilies.DESC}" /></td>
	                        			
		        <!-- BEGIN new_col -->
		        </tr><tr>
		    	<!-- END new_col -->
	    	<!-- END smilies -->
	      </tr>
	   </table>
	   
	   <INPUT TYPE='button' CLASS=BUTTON NAME="SmilesButt" VALUE="{L_MORE_SMILIES}" data-url="{U_ALBUM_SMILIES}" onclick="openAllSmiles(this.getAttribute('data-url'));">
	       
	 </td>
  </tr>
  <tr>
	<td class="cat" align="center" colspan="3" height="28"><input type="submit" name="submit" value="{L_SUBMIT}" class="mainoption" /></td>
  </tr>
</table>
</form>
<!-- END switch_comment_post -->

<br />

<!--
You must keep my copyright notice visible with its original content
-->
<div align="center" style="font-family: Verdana; font-size: 10px; letter-spacing: -1px">Powered by Photo Album Addon {ALBUM_VERSION} &copy; 2002-2003 <a href="http://smartor.is-root.com" target="_blank" rel="noopener noreferrer">Smartor</a> with Volodymyr (CLowN) Skoryk's SP1 addon</div>
