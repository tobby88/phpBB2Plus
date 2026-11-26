<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'PLUS_PW_RULES' => 'Apply phpBB2 Plus password rules',
	'PLUS_PW_RULES_EXPLAIN' => 'Check new passwords against the Plus rules below. The native minimum character count and complexity rules also apply.',
	'PLUS_PW_MIN_BYTES' => 'Minimum password length in bytes',
	'PLUS_PW_MIN_BYTES_EXPLAIN' => 'Enter 0 to 72. A new password must contain at least one character. The Plus limit is 72 UTF-8 bytes, counting each quote and backslash twice.',
	'PLUS_PW_NO_NAME' => 'Prevent using the account name as a password',
	'PLUS_PW_NO_NAME_EXPLAIN' => 'Reject an exact, case-sensitive match with the account name.',
	'PLUS_PW_LETTERS_DIGITS' => 'Require a letter and a decimal digit',
	'PLUS_PW_LETTERS_DIGITS_EXPLAIN' => 'Letters and decimal digits from any writing system qualify.',
	'PLUS_PW_BYTES_HINT' => 'Use %d to 72 UTF-8 bytes. Each single quote, double quote and backslash counts twice.',
	'PLUS_PW_LENGTH_ERROR' => 'The password is outside the allowed length in UTF-8 bytes.',
	'PLUS_PW_NAME_ERROR' => 'The password must differ from the account name.',
	'PLUS_PW_LETTER_DIGIT_ERROR' => 'Include at least one letter and one decimal digit.',
	'PLUS_PW_INVALID' => 'The password contains an invalid character.',
));
