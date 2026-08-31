<?php
/***************************************************************************
 *                              smtp.php
 *                       -------------------
 *   begin                : Wed May 09 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: smtp.php,v 1.16.2.9 2003/07/18 16:34:01 acydburn Exp $
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

// Tell the Security Scanner that this constant is allowed
define('SMTP_INCLUDED', 1);

function smtp_envelope_address($address)
{
	$address = trim(preg_replace('/[\r\n]+/', '', (string) $address));
	if (preg_match('/<([^<>]+)>/', $address, $match))
	{
		$address = trim($match[1]);
	}

	return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : '';
}

function smtp_dot_stuff($data)
{
	$data = str_replace(array("\r\n", "\r"), "\n", (string) $data);
	$data = preg_replace('/^\./m', '..', $data);
	return str_replace("\n", "\r\n", $data);
}

//
// This function has been modified as provided
// by SirSir to allow multiline responses when 
// using SMTP Extensions
//
function server_parse($socket, $response, $line = __LINE__) 
{
	$server_response = '';
	$response_complete = false;
	$deadline = microtime(true) + 20;
	for ($response_line = 0; $response_line < 100; $response_line++)
	{
		$remaining = (int) ceil($deadline - microtime(true));
		if ($remaining <= 0)
		{
			message_die(GENERAL_ERROR, 'Mail server response timed out', '', $line, __FILE__);
		}
		stream_set_timeout($socket, $remaining);
		if (!($server_response = fgets($socket, 256))) 
		{
			$metadata = stream_get_meta_data($socket);
			$error = !empty($metadata['timed_out']) ? 'Mail server response timed out' : "Couldn't get mail server response codes";
			message_die(GENERAL_ERROR, $error, '', $line, __FILE__);
		}
		if (substr($server_response, 3, 1) === ' ')
		{
			$response_complete = true;
			break;
		}
	}
	if (!$response_complete)
	{
		message_die(GENERAL_ERROR, 'Mail server sent an excessive multiline response', '', $line, __FILE__);
	}

	if (!(substr($server_response, 0, 3) == $response)) 
	{ 
		$safe_response = htmlspecialchars(substr(trim($server_response), 0, 500), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		message_die(GENERAL_ERROR, "Ran into problems sending Mail. Response: $safe_response", '', $line, __FILE__);
	} 
}

// Replacement or substitute for PHP's mail command
function smtpmail($mail_to, $subject, $message, $headers = '')
{
	global $board_config;
	$cc = array();
	$bcc = array();

	// Fix any bare linefeeds in the message to make it RFC821 Compliant.
	$message = preg_replace("#(?<!\r)\n#si", "\r\n", $message);

	if ($headers != '')
	{
		if (is_array($headers))
		{
			if (sizeof($headers) > 1)
			{
				$headers = join("\n", $headers);
			}
			else
			{
				$headers = $headers[0];
			}
		}
		$headers = chop($headers);

		// Make sure there are no bare linefeeds in the headers
		$headers = preg_replace('#(?<!\r)\n#si', "\r\n", $headers);

		// Ok this is rather confusing all things considered,
		// but we have to grab bcc and cc headers and treat them differently
		// Something we really didn't take into consideration originally
		$header_array = explode("\r\n", $headers);
		@reset($header_array);

		$headers = '';
		foreach($header_array as $key => $header)
		{
			if (preg_match('#^cc:#si', $header))
			{
				$cc = preg_replace('#^cc:(.*)#si', '\1', $header);
			}
			else if (preg_match('#^bcc:#si', $header))
			{
				$bcc = preg_replace('#^bcc:(.*)#si', '\1', $header);
				$header = '';
			}
			$headers .= ($header != '') ? $header . "\r\n" : '';
		}

		$headers = chop($headers);
		$cc = explode(', ', (( !empty($cc) ) ? $cc : ''));
		$bcc = explode(', ', (( !empty($bcc) ) ? $bcc : ''));
	}

	if (trim($subject) == '')
	{
		message_die(GENERAL_ERROR, "No email Subject specified", "", __LINE__, __FILE__);
	}

	if (trim($message) == '')
	{
		message_die(GENERAL_ERROR, "Email message was blank", "", __LINE__, __FILE__);
	}

	// Ok we have error checked as much as we can to this point let's get on
	// it already.
	$smtp_host = trim((string) $board_config['smtp_host']);
	if ($smtp_host === '' || preg_match('/[\x00-\x20\x7f]/', $smtp_host))
	{
		message_die(GENERAL_ERROR, 'No SMTP host configured', '', __LINE__, __FILE__);
	}
	if( !$socket = @fsockopen($smtp_host, 25, $errno, $errstr, 20) )
	{
		message_die(GENERAL_ERROR, 'Could not connect to SMTP host (' . intval($errno) . ')', '', __LINE__, __FILE__);
	}
	stream_set_timeout($socket, 20);

	// Wait for reply
	server_parse($socket, "220", __LINE__);

	// Do we want to use AUTH?, send RFC2554 EHLO, else send RFC821 HELO
	// This improved as provided by SirSir to accomodate
	if( !empty($board_config['smtp_username']) && !empty($board_config['smtp_password']) )
	{ 
		$smtp_identity = preg_replace('/[^a-z0-9.-]/i', '', (string) $board_config['server_name']);
		$smtp_identity = ($smtp_identity !== '') ? $smtp_identity : 'localhost';
		fputs($socket, "EHLO " . $smtp_identity . "\r\n");
		server_parse($socket, "250", __LINE__);

		fputs($socket, "AUTH LOGIN\r\n");
		server_parse($socket, "334", __LINE__);

		fputs($socket, base64_encode($board_config['smtp_username']) . "\r\n");
		server_parse($socket, "334", __LINE__);

		fputs($socket, base64_encode($board_config['smtp_password']) . "\r\n");
		server_parse($socket, "235", __LINE__);
	}
	else
	{
		$smtp_identity = preg_replace('/[^a-z0-9.-]/i', '', (string) $board_config['server_name']);
		$smtp_identity = ($smtp_identity !== '') ? $smtp_identity : 'localhost';
		fputs($socket, "HELO " . $smtp_identity . "\r\n");
		server_parse($socket, "250", __LINE__);
	}

	// From this point onward most server response codes should be 250
	// Specify who the mail is from....
	$from_address = smtp_envelope_address($board_config['board_email']);
	if ($from_address === '')
	{
		fclose($socket);
		message_die(GENERAL_ERROR, 'Invalid board email address', '', __LINE__, __FILE__);
	}
	fputs($socket, "MAIL FROM: <" . $from_address . ">\r\n");
	server_parse($socket, "250", __LINE__);

	// Specify each user to send to and build to header.
	$to_header = '';

	// Add an additional bit of error checking to the To field.
	$mail_to = trim(preg_replace('/[\r\n]+/', '', (string) $mail_to));
	$to_address = smtp_envelope_address($mail_to);
	$to_header = ($to_address !== '') ? $to_address : 'Undisclosed-recipients:;';
	$recipient_count = 0;
	if ($to_address !== '')
	{
		fputs($socket, "RCPT TO: <$to_address>\r\n");
		server_parse($socket, "250", __LINE__);
		$recipient_count++;
	}

	// Ok now do the CC and BCC fields...
	@reset($bcc);
	foreach($bcc as $key => $bcc_address)
	{
		// Add an additional bit of error checking to bcc header...
		$bcc_address = trim($bcc_address);
		$bcc_address = smtp_envelope_address($bcc_address);
		if ($bcc_address !== '')
		{
			fputs($socket, "RCPT TO: <$bcc_address>\r\n");
			server_parse($socket, "250", __LINE__);
			$recipient_count++;
		}
	}

	@reset($cc);
	foreach(array_values($cc) as $cc_address)
	{
		// Add an additional bit of error checking to cc header
		$cc_address = trim($cc_address);
		$cc_address = smtp_envelope_address($cc_address);
		if ($cc_address !== '')
		{
			fputs($socket, "RCPT TO: <$cc_address>\r\n");
			server_parse($socket, "250", __LINE__);
			$recipient_count++;
		}
	}
	if ($recipient_count === 0)
	{
		fclose($socket);
		message_die(GENERAL_ERROR, 'No valid email recipient specified', '', __LINE__, __FILE__);
	}

	// Ok now we tell the server we are ready to start sending data
	fputs($socket, "DATA\r\n");

	// This is the last response code we look for until the end of the message.
	server_parse($socket, "354", __LINE__);

	$subject = trim(preg_replace('/[\x00\r\n]+/', '', (string) $subject));
	$data = "Subject: $subject\r\n";
	if (stripos($headers, 'To:') === false)
	{
		$data .= "To: $to_header\r\n";
	}
	$data .= $headers . "\r\n\r\n" . $message;
	fputs($socket, smtp_dot_stuff($data) . "\r\n.\r\n");
	server_parse($socket, "250", __LINE__);

	// Now tell the server we are done and close the socket...
	fputs($socket, "QUIT\r\n");
	fclose($socket);

	return TRUE;
}
