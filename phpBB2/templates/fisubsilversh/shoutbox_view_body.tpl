<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="120;url={U_SHOUTBOX_VIEW}?auto_refresh=1" />
	<meta http-equiv="Content-Type" content="text/html; charset={S_CONTENT_ENCODING}" />
	<link rel="stylesheet" href="{T_URL}/{T_HEAD_STYLESHEET}" type="text/css" />
	<style type="text/css">
		html, body { background: transparent; margin: 0; padding: 0; }
		.shoutRows { border: 0; border-collapse: separate; border-spacing: 0 1px; width: 100%; }
		.shoutRows td { padding: 4px 5px; vertical-align: top; }
		.shoutTime { display: block; margin-bottom: 2px; opacity: .75; }
		.shoutText { display: block; margin-top: 2px; overflow-wrap: anywhere; }
	</style>
</head>
<body>
	<table class="forumline shoutRows" cellpadding="0" cellspacing="0">
		<!-- BEGIN shoutrow -->
		<tr>
			<td class="{shoutrow.ROW_CLASS}">
				<span class="gensmall shoutTime">{shoutrow.TIME}</span>
				<span class="gensmall"><b>{shoutrow.USERNAME}:</b></span>
				<span class="gensmall shoutText">{shoutrow.SHOUT}</span>
			</td>
		</tr>
		<!-- END shoutrow -->
	</table>
</body>
</html>
