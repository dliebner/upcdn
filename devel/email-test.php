<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );
require_once( $root_path. 'includes/Email.php' );

$email = new Email("dliebner@gmail.com", "Email test", "Hey bud it's me. This is a test email.");
$res = $email->send();

echo "mail was " . ($res ? "" : "not ") . "sent\n";

if( !$res ) {

	print_r([error_get_last()]);
	echo "\n";

}