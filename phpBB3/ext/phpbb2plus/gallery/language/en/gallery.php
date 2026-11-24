<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_ALBUM' => 'Photo album', 'PLUS_ALBUM_CATEGORIES' => 'Albums', 'PLUS_ALBUM_EMPTY' => 'There are no visible pictures in this album.',
	'PLUS_ALBUM_ORIGINAL' => 'Open full-size picture', 'PLUS_ALBUM_COMMENTS' => 'Picture comments',
	'PLUS_ALBUM_NO_COMMENTS' => 'There are no comments on this picture yet.', 'PLUS_ALBUM_FORMER' => 'Former member',
	'PLUS_ALBUM_RATING' => 'Rating %1$s out of %2$d (%3$d votes)', 'PLUS_ALBUM_DECIMAL' => '.',
	'PLUS_ALBUM_UPLOAD' => 'Upload pictures', 'PLUS_ALBUM_FILES' => 'Image files', 'PLUS_ALBUM_COUNT' => 'Up to %d pictures per upload.',
	'PLUS_ALBUM_FILENAME_TITLE' => 'Leave the title blank to use each file name as its picture title.',
	'PLUS_ALBUM_BACK' => 'Return to the album', 'PLUS_ALBUM_PENDING' => 'Your pictures have been uploaded and are awaiting approval.',
	'PLUS_ALBUM_SAVED' => 'Your pictures are now visible in the album.', 'PLUS_ALBUM_SENDING' => 'Uploading pictures …',
	'PLUS_ALBUM_PROCESSING' => 'Transfer complete. Processing your pictures …',
	'PLUS_ALBUM_RETRY' => 'The upload could not be confirmed. You can try again.',
	'PLUS_ALBUM_BAD_FILE' => 'Choose readable images in a format allowed by this album.',
	'PLUS_ALBUM_BAD_SIZE' => 'At least one picture exceeds the file size or resolution limit.',
	'PLUS_ALBUM_BAD_COUNT' => 'This number of pictures cannot be uploaded together.',
	'PLUS_ALBUM_BAD_NAME' => 'Choose an available guest name.', 'PLUS_ALBUM_BAD_TEXT' => 'The title or description is too long.',
	'PLUS_ALBUM_QUOTA' => 'These pictures would exceed the remaining album quota.',
));
