				<h1>{L_FORUM_TITLE}</h1>
				<p>{L_FORUM_EXPLAIN}</p>
				<form action="{S_FORUM_ACTION}" method="post">
				<fieldset>
					<legend>{L_FORUM_SETTINGS}</legend>
					<dl>
						<dt><label>{L_FORUM_NAME}:</label></dt>
						<dd><input type="text" size="25" name="forumname" value="{FORUM_NAME}" /></dd>
					</dl>
					<dl>
						<dt><label>{L_FORUM_DESCRIPTION}:</label></dt>
						<dd><textarea rows="5" cols="45" wrap="virtual" name="forumdesc">{DESCRIPTION}</textarea></dd>
					</dl>
					<dl>
						<dt><label>{L_ICON}:</label><br /><span>{L_ICON_EXPLAIN}</span></dt>
						<dd><input type="text" size="60" name="icon" value="{ICON}" />{ICON_IMG}</dd>
					</dl>
					<dl>
						<dt><label>{L_CATEGORY}:</label></dt>
						<dd><select name="c">{S_CAT_LIST}</select></dd>
					</dl>
					<dl>
						<dt><label>{L_FORUM_STATUS}:</label></dt>
						<dd><select name="forumstatus">{S_STATUS_LIST}</select></dd>
					</dl>
					<dl>
						<dt><label>{L_COUNT_POSTS}:</label></dt>
						<dd><label><input type="radio" name="count_posts" value="1" {COUNT_POSTS_YES} /> {L_YES}</label> <label><input type="radio" name="count_posts" value="0" {COUNT_POSTS_NO} /> {L_NO}</label></dd>
					</dl>
				</fieldset>
				<fieldset>
					<legend>{L_AUTO_PRUNE}</legend>
					<dl>
						<dt><label>{L_ENABLED}:</label></dt>
						<dd><input type="checkbox" name="prune_enable" value="1" {S_PRUNE_ENABLED} /></dd>
					</dl>
					<dl>
						<dt><label>{L_PRUNE_DAYS}:</label></dt>
						<dd><input type="text" name="prune_days" value="{PRUNE_DAYS}" size="5" /> {L_DAYS}</dd>
					</dl>
					<dl>
						<dt><label>{L_PRUNE_FREQ}:</label></dt>
						<dd><input type="text" name="prune_freq" value="{PRUNE_FREQ}" size="5" /> {L_DAYS}</dd>
					</dl>
				</fieldset>
				<fieldset>
					<legend>{L_LINK}</legend>
					<dl>
						<dt><label>{L_FORUM_LINK}:</label><br /><span>{L_FORUM_LINK_EXPLAIN}</span></dt>
						<dd><input type="text" size="60" name="forum_link" value="{FORUM_LINK}" /></dd>
					</dl>
					<dl>
						<dt><label>{L_FORUM_LINK_INTERNAL}:</label><br /><span>{L_FORUM_LINK_INTERNAL_EXPLAIN}</span></dt>
						<dd><label><input type="radio" name="forum_link_internal" value="1" {FORUM_LINK_INTERNAL_YES} /> {L_YES}</label> <label><input type="radio" name="forum_link_internal" value="0" {FORUM_LINK_INTERNAL_NO} /> {L_NO}</label></dd>
					</dl>
					<dl>
						<dt><label>{L_FORUM_LINK_HIT_COUNT}:</label><br /><span>{L_FORUM_LINK_HIT_COUNT_EXPLAIN}</span></dt>
						<dd><label><input type="radio" name="forum_link_hit_count" value="1" {FORUM_LINK_HIT_COUNT_YES} /> {L_YES}</label> <label><input type="radio" name="forum_link_hit_count" value="0" {FORUM_LINK_HIT_COUNT_NO} /> {L_NO}</label></dd>
					</dl>
				</fieldset>
				<fieldset class="submit-buttons">
					{S_HIDDEN_FIELDS}
					<input type="submit" name="submit" value="{S_SUBMIT_VALUE}" class="button1" />
				</fieldset>
				</form>
