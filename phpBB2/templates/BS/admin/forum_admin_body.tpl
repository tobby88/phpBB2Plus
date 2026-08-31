				<h1>{L_FORUM_TITLE}</h1>
				<p>{L_FORUM_EXPLAIN}</p>
				<form method="post" action="{S_FORUM_ACTION}">{S_SESSION_FIELD}
				<table cellspacing="1">
				<col class="row1" /><col class="row1" /><col class="row1" /><col class="row2" />
				<tbody>
				<!-- BEGIN catrow -->
				<tr>
					<td colspan="3"><strong><a href="{catrow.U_CAT_EDIT}">{catrow.CAT_DESC}</a></strong></td>
					<td style="vertical-align:top;width:180px;text-align:right;white-space:nowrap">{catrow.S_CAT_MOVE_UP_BUTTON} {catrow.S_CAT_MOVE_DOWN_BUTTON} <a href="{catrow.U_CAT_EDIT}">{L_EDIT}</a> <a href="{catrow.U_CAT_DELETE}">{L_DELETE}</a></td>
				</tr>
				<!-- BEGIN forumrow -->
				<tr>
					<td><a href="{catrow.forumrow.U_FORUM_EDIT}">{catrow.forumrow.FORUM_NAME}</a><br />{catrow.forumrow.FORUM_DESC}</td>
					<td>{catrow.forumrow.NUM_TOPICS}</td>
					<td>{catrow.forumrow.NUM_POSTS}</td>
					<td style="vertical-align:top;width:180px;text-align:right;white-space:nowrap">{catrow.forumrow.S_FORUM_MOVE_UP_BUTTON} {catrow.forumrow.S_FORUM_MOVE_DOWN_BUTTON} <a href="{catrow.forumrow.U_FORUM_EDIT}">{L_EDIT}</a> {catrow.forumrow.S_FORUM_RESYNC_BUTTON} <a href="{catrow.forumrow.U_FORUM_DELETE}">{L_DELETE}</a></td>
				</tr>
				<!-- END forumrow -->
				<tr>
					<td colspan="3"><input type="text" name="{catrow.S_ADD_FORUM_NAME}" /> <input type="submit" class="button2" name="{catrow.S_ADD_FORUM_SUBMIT}" value="{L_CREATE_FORUM}" /></td>
					<td></td>
				</tr>
				<!-- END catrow -->
				<tr>
					<td colspan="3"><input type="text" name="categoryname" /> <input type="submit" class="button2" name="addcategory" value="{L_CREATE_CATEGORY}" /></td>
					<td></td>
				</tr>
				</tbody>
				</table>
				</form>
