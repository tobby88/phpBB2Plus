<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset={S_CONTENT_ENCODING}" />
	<link rel="stylesheet" href="{T_URL}/{T_HEAD_STYLESHEET}" type="text/css" />
	<style type="text/css">
		html, body { background: transparent; margin: 0; padding: 0; }
		.shoutCompact { box-sizing: border-box; padding: 4px; width: 100%; }
		.shoutToolbar { line-height: 24px; text-align: center; white-space: nowrap; }
		.shoutToolbar .button { margin: 0 1px; min-width: 22px; padding: 1px 3px; }
		.shoutSmilies { line-height: 18px; text-align: center; }
		.shoutLabel { display: block; margin: 2px 0 1px; }
		.shoutInput { box-sizing: border-box; width: 100%; }
		.shoutActions { margin-top: 4px; text-align: center; white-space: nowrap; }
		.shoutActions input { margin: 0 1px; }
		.shoutLogin { min-height: 42px; padding: 6px 2px; text-align: center; }
		.shoutMessages { background: transparent; border: 0; display: block; height: 158px; width: 100%; }
	</style>
</head>
<body>
	<script type="text/javascript" src="{T_URL}/bbcode.js"></script>
	<form method="post" name="post" action="{U_SHOUTBOX}" onsubmit="return checkForm(this)">
		{ERROR_BOX}
		<div class="row1 shoutCompact">
			<!-- BEGIN switch_auth_post -->
			<!-- BEGIN switch_bbcode -->
			<div class="shoutToolbar">
				<input type="button" class="button" accesskey="b" name="addbbcode0" value="{L_B}" style="font-weight:bold" onclick="bbstyle(0)" />
				<input type="button" class="button" accesskey="i" name="addbbcode2" value="{L_I}" style="font-style:italic" onclick="bbstyle(2)" />
				<input type="button" class="button" accesskey="u" name="addbbcode4" value="{L_U}" style="text-decoration:underline" onclick="bbstyle(4)" />
				<input type="button" class="button" accesskey="q" name="addbbcode6" value="{L_QUOTE}" onclick="bbstyle(6)" />
			</div>
			<div class="shoutSmilies"><a href="{U_MORE_SMILIES}" onclick="window.open('{U_MORE_SMILIES}', '_phpbbsmilies', 'height=300,resizable=yes,scrollbars=yes,width=250'); return false;" target="_phpbbsmilies" class="nav">{L_SMILIES}</a></div>
			<!-- END switch_bbcode -->
			<label class="gensmall shoutLabel" for="shoutMessage">{L_TEXT}:</label>
			<input id="shoutMessage" type="text" class="post shoutInput" name="message" value="{MESSAGE}" onselect="storeCaret(this)" onclick="storeCaret(this)" onkeyup="storeCaret(this)" />
			<div class="shoutActions">
				<input type="submit" class="mainoption" value="{L_SHOUT_SUBMIT}" name="shout" />
				<input type="submit" class="liteoption" value="{L_SHOUT_REFRESH}" name="refresh" />
			</div>
			<!-- END switch_auth_post -->
			<!-- BEGIN switch_auth_no_post -->
			<div class="gensmall shoutLogin">{L_SHOUTBOX_LOGIN}</div>
			<div class="shoutActions"><input type="submit" class="liteoption" value="{L_SHOUT_REFRESH}" name="refresh" /></div>
			<!-- END switch_auth_no_post -->
		</div>
		<iframe class="shoutMessages" src="{U_SHOUTBOX_VIEW}" title="{L_SHOUTBOX}"></iframe>
		{S_HIDDEN_FIELDS}
	</form>
</body>
</html>
