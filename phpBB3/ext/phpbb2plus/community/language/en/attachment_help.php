<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_ATTACH_RULES_LINK' => 'Allowed file types and sizes',
	'PLUS_ATTACH_UPLOAD_POLICY' => 'File extensions for new attachments',
	'PLUS_ATTACH_UPLOAD_POLICY_EXPLAIN' => 'New post and private message attachments use the native extension groups and all imported or manually added blocks. Additional Plus protection allows extensions of up to 16 letters or digits and blocks predefined script types. Existing attachments use the board’s download permissions.',
	'PLUS_ATTACH_ON_IMPORT' => 'Activate additional Plus protection when importing a Plus board',
	'PLUS_ATTACH_GROUPS_ONLY' => 'Use native groups and recorded extension blocks',
	'PLUS_ATTACH_INHERIT_TYPES' => 'Apply the Plus restriction to native extension groups',
	'PLUS_ATTACH_POST_RULES' => 'Attachment rules for this forum',
	'PLUS_ATTACH_PM_RULES' => 'Attachment rules for private messages',
	'PLUS_ATTACH_GROUP' => 'File group', 'PLUS_ATTACH_TYPES' => 'File extensions', 'PLUS_ATTACH_FILE_LIMIT' => 'Maximum size per file',
	'PLUS_ATTACH_NO_LIMIT' => 'No board size limit', 'PLUS_ATTACH_NO_TYPES' => 'No file types are currently enabled for attachments here.',
	'PLUS_HELP_FILE_EDIT'=>'How do I add a file to a post or change an attachment?',
	'PLUS_HELP_FILE_EDIT_ANSWER'=>'Open the full post editor and select the file on the Attachments tab. You can add a file comment there. While you can edit the post, you can use the same tab to add more files, change comments or delete individual attachments. Submit the post to save your changes.',
	'PLUS_HELP_FILE_INLINE'=>'How do I place an attachment at a particular point in a post?',
	'PLUS_HELP_FILE_INLINE_ANSWER'=>'Upload the file, put the cursor where you want it in the text and select Place inline for the attachment. Keep the inserted marker in the text. Other attachments appear below the post. Use Preview to check the placement.',
	'PLUS_HELP_FILE_ACCESS'=>'Who can download a file attached to a post?',
	'PLUS_HELP_FILE_ACCESS_ANSWER'=>'Readers need access to the post and permission to download attachments in its forum. If you can read the post and the download is still unavailable, send the post link to a moderator.',
	'PLUS_HELP_FILE_PM'=>'How do I send a file in a private message?',
	'PLUS_HELP_FILE_PM_ANSWER'=>'While composing the message, open the Attachments tab, upload the file and send the message. Recipients can open the file in the message if they have permission to download private-message attachments.',
	'PLUS_HELP_FILE_QUOTA'=>'Why can a small file exceed my storage allowance?',
	'PLUS_HELP_FILE_QUOTA_ANSWER'=>'In addition to the size limit for an individual file, the board can limit storage for posts and private messages separately. An administrator can assign an allowance to your account or groups. The available storage of a private-message recipient can also affect delivery.',
));
