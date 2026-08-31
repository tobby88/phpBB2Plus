<?php
/***************************************************************************
                                emailer.php
                             -------------------
    begin                : Sunday Aug. 12, 2001
    copyright            : (C) 2001 The phpBB Group
    email                : support@phpbb.com

    $Id: emailer.php,v 1.15.2.34 2003/07/26 11:41:35 acydburn Exp $

***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

//
// The emailer class has support for attaching files, that isn't implemented
// in the 2.0 release but we can probable find some way of using it in a future
// release
//
class emailer
{
	var $msg, $subject, $extra_headers;
	var $addresses, $reply_to, $from;
	var $use_smtp;
	var $vars, $encoding;

	var $tpl_msg = array();

	function __construct($use_smtp)
	{
		$this->emailer($use_smtp);
	}

	function emailer($use_smtp)
	{
		$this->reset();
		$this->use_smtp = $use_smtp;
		$this->reply_to = $this->from = '';
	}

	// Resets all the data (address, template file, etc etc to default
	function reset()
	{
		$this->addresses = array('to' => '', 'cc' => array(), 'bcc' => array());
		$this->vars = $this->msg = $this->subject = $this->extra_headers = '';
	}

	function normalize_address($address)
	{
		if (!is_scalar($address))
		{
			return '';
		}

		$address = trim(preg_replace('#[\x00-\x20\x7f]+#', '', (string) $address));
		return (strlen($address) <= 254 && filter_var($address, FILTER_VALIDATE_EMAIL)) ? $address : '';
	}

	// Sets an email address to send to
	function email_address($address)
	{
		$this->addresses['to'] = $this->normalize_address($address);
	}

	function cc($address)
	{
		$address = $this->normalize_address($address);
		if ($address !== '')
		{
			$this->addresses['cc'][] = $address;
		}
	}

	function bcc($address)
	{
		$address = $this->normalize_address($address);
		if ($address !== '')
		{
			$this->addresses['bcc'][] = $address;
		}
	}

	function replyto($address)
	{
		$this->reply_to = $this->normalize_address($address);
	}

	function from($address)
	{
		$this->from = $this->normalize_address($address);
	}

	// set up subject for mail
	function set_subject($subject = '')
	{
		$this->subject = trim(preg_replace('#[\x00\n\r]+#s', '', (string) $subject));
	}

	// set up extra mail headers
	function extra_headers($headers)
	{
		$lines = preg_split('/[\r\n]+/', (string) $headers);
		foreach ($lines as $line)
		{
			$line = trim($line);
			if (preg_match('/^X-AntiAbuse:\s*([^\r\n]*)$/iD', $line, $match))
			{
				$this->extra_headers .= 'X-AntiAbuse: ' . trim($match[1]) . "\n";
			}
		}
	}

	function use_template($template_file, $template_lang = '')
	{
		global $board_config, $phpbb_root_path;

		$template_file = trim((string) $template_file);
		if (!preg_match('/^[a-z0-9_-]{1,80}$/iD', $template_file))
		{
			message_die(GENERAL_ERROR, 'No template file set', '', __LINE__, __FILE__);
		}

		$template_lang = strtolower(trim((string) $template_lang));
		if (!preg_match('/^[a-z_]{1,30}$/D', $template_lang))
		{
			$template_lang = strtolower(trim((string) $board_config['default_lang']));
		}
		if (!preg_match('/^[a-z_]{1,30}$/D', $template_lang))
		{
			$template_lang = 'english';
		}

		if (empty($this->tpl_msg[$template_lang . $template_file]))
		{
			$tpl_file = $phpbb_root_path . 'language/lang_' . $template_lang . '/email/' . $template_file . '.tpl';

			if (!@is_file($tpl_file) || @is_link($tpl_file))
			{
				$fallback_lang = strtolower(trim((string) $board_config['default_lang']));
				if (!preg_match('/^[a-z_]{1,30}$/D', $fallback_lang))
				{
					$fallback_lang = 'english';
				}
				$tpl_file = $phpbb_root_path . 'language/lang_' . $fallback_lang . '/email/' . $template_file . '.tpl';

				if (!@is_file($tpl_file) || @is_link($tpl_file))
				{
					message_die(GENERAL_ERROR, 'Could not find email template file :: ' . $template_file, '', __LINE__, __FILE__);
				}
				$template_lang = $fallback_lang;
			}

			if (!($fd = @fopen($tpl_file, 'r')))
			{
				message_die(GENERAL_ERROR, 'Failed opening template file :: ' . $tpl_file, '', __LINE__, __FILE__);
			}

			$this->tpl_msg[$template_lang . $template_file] = fread($fd, filesize($tpl_file));
			fclose($fd);
		}

		$this->msg = $this->tpl_msg[$template_lang . $template_file];

		return true;
	}

	// assign variables
	function assign_vars($vars)
	{
		$this->vars = (empty($this->vars)) ? $vars : array_merge($this->vars, $vars);
	}

	// Send the mail out to the recipients set previously in var $this->address
	function send()
	{
		global $board_config, $lang, $phpEx, $phpbb_root_path, $db;

		$vars = $this->vars;
		$board_email = $this->normalize_address(isset($board_config['board_email']) ? $board_config['board_email'] : '');
		$this->from = $this->normalize_address($this->from);
		$this->reply_to = $this->normalize_address($this->reply_to);
		$this->addresses['to'] = $this->normalize_address($this->addresses['to']);
		$this->addresses['cc'] = array_values(array_unique(array_filter(array_map(array($this, 'normalize_address'), $this->addresses['cc']))));
		$this->addresses['bcc'] = array_values(array_unique(array_filter(array_map(array($this, 'normalize_address'), $this->addresses['bcc']))));
		if ($board_email === '')
		{
			$board_email = $this->from;
		}
		if ($board_email === '' || ($this->addresses['to'] === '' && !$this->addresses['cc'] && !$this->addresses['bcc']))
		{
			return false;
		}
		$server_name = trim(preg_replace('#[^a-z0-9.-]+#i', '', (string) $board_config['server_name']));
		if ($server_name === '')
		{
			$server_name = 'localhost';
		}
		$this->msg = preg_replace_callback(
			'#\{([a-z0-9\-_]*?)\}#is',
			function ($match) use ($vars)
			{
				return isset($vars[$match[1]]) && is_scalar($vars[$match[1]]) ? (string) $vars[$match[1]] : '';
			},
			$this->msg
		);

		// We now try and pull a subject from the email body ... if it exists,
		// do this here because the subject may contain a variable
		$drop_header = '';
		$match = array();
		if (preg_match('#^(Subject:(.*?))$#m', $this->msg, $match))
		{
			$this->subject = (trim($match[2]) != '') ? trim($match[2]) : (($this->subject != '') ? $this->subject : 'No Subject');
			$drop_header .= '[\r\n]*?' . preg_quote($match[1], '#');
		}
		else
		{
			$this->subject = (($this->subject != '') ? $this->subject : 'No Subject');
		}

		// Template variables may be user-controlled (for example topic titles).
		// Never allow them to turn the subject into additional mail headers.
		$this->subject = trim(preg_replace('#[\x00\r\n]+#', '', (string) $this->subject));
		if ($this->subject == '')
		{
			$this->subject = 'No Subject';
		}

		if (preg_match('#^(Charset:(.*?))$#m', $this->msg, $match))
		{
			$this->encoding = (trim($match[2]) != '') ? trim($match[2]) : trim($lang['ENCODING']);
			$drop_header .= '[\r\n]*?' . preg_quote($match[1], '#');
		}
		else
		{
			$this->encoding = trim($lang['ENCODING']);
		}

		// Charset is used verbatim in a MIME header, so keep it to a valid token.
		$this->encoding = preg_replace('#[^a-z0-9._-]+#i', '', (string) $this->encoding);
		if ($this->encoding == '')
		{
			$this->encoding = 'UTF-8';
		}

		if ($drop_header != '')
		{
			$this->msg = trim(preg_replace('#' . $drop_header . '#s', '', $this->msg));
		}

		$to = $this->addresses['to'];

		$cc = (count($this->addresses['cc'])) ? implode(', ', $this->addresses['cc']) : '';
		$bcc = (count($this->addresses['bcc'])) ? implode(', ', $this->addresses['bcc']) : '';

		// Build header
		$this->extra_headers = (($this->reply_to != '') ? "Reply-to: $this->reply_to\n" : '') . (($this->from != '') ? "From: $this->from\n" : "From: " . $board_email . "\n") . "Return-Path: " . $board_email . "\nMessage-ID: <" . bin2hex(phpbb_random_bytes(16)) . "@" . $server_name . ">\nMIME-Version: 1.0\nContent-type: text/plain; charset=" . $this->encoding . "\nContent-transfer-encoding: 8bit\nDate: " . date('r', time()) . "\nX-Priority: 3\nX-MSMail-Priority: Normal\nX-Mailer: PHP\nX-MimeOLE: Produced By phpBB2\n" . $this->extra_headers . (($cc != '') ? "Cc: $cc\n" : '')  . (($bcc != '') ? "Bcc: $bcc\n" : '');

		// Send message ... removed $this->encode() from subject for time being
		if ( $this->use_smtp )
		{
			if ( !defined('SMTP_INCLUDED') ) 
			{
				include($phpbb_root_path . 'includes/smtp.' . $phpEx);
			}

			$result = smtpmail($to, $this->subject, $this->msg, $this->extra_headers);
		}
		else
		{
			$empty_to_header = ($to == '') ? TRUE : FALSE;
			$to = ($to == '') ? (($board_config['sendmail_fix']) ? ' ' : 'Undisclosed-recipients:;') : $to;
	
			$result = @mail($to, $this->subject, preg_replace("#(?<!\r)\n#s", "\n", $this->msg), $this->extra_headers);
			
			if (!$result && !$board_config['sendmail_fix'] && $empty_to_header)
			{
				$to = ' ';

				$sql = "UPDATE " . CONFIG_TABLE . " 
					SET config_value = '1'
					WHERE config_name = 'sendmail_fix'";
				if (!$db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, 'Unable to update config table', '', __LINE__, __FILE__, $sql);
				}
				@unlink($phpbb_root_path . 'cache/config_data.cache');
				$board_config['sendmail_fix'] = 1;
				$result = @mail($to, $this->subject, preg_replace("#(?<!\r)\n#s", "\n", $this->msg), $this->extra_headers);
			}
		}

		// Did it work?
		if (!$result)
		{
			message_die(GENERAL_ERROR, 'Failed sending email :: ' . (($this->use_smtp) ? 'SMTP' : 'PHP') . ' :: ' . $result, '', __LINE__, __FILE__);
		}

		return true;
	}

	// Encodes the given string for proper display for this encoding ... nabbed 
	// from php.net and modified. There is an alternative encoding method which 
	// may produce lesd output but it's questionable as to its worth in this 
	// scenario IMO
	function encode($str)
	{
		if ($this->encoding == '')
		{
			return $str;
		}

		// define start delimimter, end delimiter and spacer
		$end = "?=";
		$start = "=?$this->encoding?B?";
		$spacer = "$end\r\n $start";

		// determine length of encoded text within chunks and ensure length is even
		$length = 75 - strlen($start) - strlen($end);
		$length = floor($length / 2) * 2;

		// encode the string and split it into chunks with spacers after each chunk
		$str = chunk_split(base64_encode($str), $length, $spacer);

		// remove trailing spacer and add start and end delimiters
		$str = preg_replace('#' . preg_quote($spacer, '#') . '$#', '', $str);

		return $start . $str . $end;
	}

} // class emailer

?>
