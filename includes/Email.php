<?php

/*

	classEmail.php
	
	Handles all E-Mail sendage
	
								*/

// This is to make sure we are being called by a valid page
if( !defined('IN_SCRIPT') ) die("Hacking attempt");

class Email {

	public $targetAddr;
	public $title;
	public $contents;
	public $from;
	public $extraHeaders = array();

	public function __construct($target, $title, $contents, $from = '', $footer = true) {
		
		$this->targetAddr = $target;
		$this->title = $title;
		$this->contents = $contents;

		if( empty($from) ) {

			$this->from = 'UPCDN <upcdn-noreply@' . Config::get('hostname') . '>';

		} else {

			$this->from = $from;

		}

		$this->footer = $footer;

	}

	public function addRecip($target) {

		$this->targetAddr = $target;

	}

	public function setTitle($title) {

		$this->title = $title;

	}

	public function setContents($contents) {

		$this->contents .= $contents;

	}

	public function addHeader($header) {

		$this->extraHeaders[] = $header;

	}

	function send() {

		return;

		// Check for Errors
		if( empty($this->targetAddr) ) throw new Exception('Missing target E-Mail Address.');
		if( empty($this->title) ) throw new Exception('Missing E-Mail Title.');
		if( empty($this->contents) ) throw new Exception('Missing E-Mail Contents.');

		$message = $this->contents;

		if( $this->footer ) {

			$message .= '

-------------------------------
Thanks,
UPCDN';

		}

		$headers = array(
			"From: " . $this->from
		);

		$headers = array_merge($this->extraHeaders, $headers);

		return mail($this->targetAddr, $this->title, $message, implode("\r\n", $headers));

	}
		
}
