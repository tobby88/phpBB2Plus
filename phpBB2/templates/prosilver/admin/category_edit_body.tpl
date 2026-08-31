				<h1>{L_EDIT_CATEGORY}</h1>
				<p>{L_EDIT_CATEGORY_EXPLAIN}</p>
				<form action="{S_FORUM_ACTION}" method="post">
				<fieldset>
					<legend>{L_EDIT_CATEGORY}</legend>
					<dl>
						<dt><label>{L_CATEGORY}:</label></dt>
						<dd><input type="text" size="25" name="cat_title" value="{CAT_TITLE}" /></dd>
					</dl>
					<dl>
						<dt><label>{L_CAT_DESCRIPTION}:</label></dt>
						<dd><textarea rows="5" cols="45" wrap="virtual" name="cat_desc">{CAT_DESCRIPTION}</textarea></dd>
					</dl>
					<dl>
						<dt><label>{L_ICON}:</label><br /><span>{L_ICON_EXPLAIN}</span></dt>
						<dd><input type="text" size="60" name="icon" value="{ICON}" />{ICON_IMG}</dd>
					</dl>
					<dl>
						<dt><label>{L_CATEGORY_ATTACHMENT}:</label></dt>
						<dd><select name="cat_main">{S_CAT_LIST}</select></dd>
					</dl>
				</fieldset>
				<fieldset class="submit-buttons">
					{S_HIDDEN_FIELDS}
					<input type="submit" name="submit" value="{S_SUBMIT_VALUE}" class="button1" />
				</fieldset>
				</form>
