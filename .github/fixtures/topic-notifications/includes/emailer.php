<?php
// Only the delivery transport is substituted. Never sends email or uses SMTP.
class emailer
{
	var $msg = ''; var $vars = array(); var $address = '';
	function __construct($smtp) {}
	function from($value) {}
	function replyto($value) {}
	function email_address($value) { $this->address = $value; }
	function use_template($name, $language) {}
	function set_subject($value) {}
	function assign_vars($value) { $this->vars = $value; }
	function send()
	{
		mutation_check($GLOBALS['mutation_server']->owner === null, 'Mail I/O never holds the shared writer lock');
		$GLOBALS['notification_deliveries'][] = array('address'=>$this->address, 'vars'=>$this->vars);
		if (is_callable($GLOBALS['notification_delivery_hook'])) { call_user_func($GLOBALS['notification_delivery_hook']); }
		return $GLOBALS['notification_delivery_result'];
	}
}
