<?php 

if( !defined('IN_SCRIPT') ) die( "Hacking attempt" );

// Error Reporting
ini_set('display_errors', 1);
error_reporting( E_ERROR | E_WARNING | E_PARSE | E_RECOVERABLE_ERROR );

// autoload.php
//require_once( $root_path. 'vendor/autoload.php' );

// Includes
require_once( $root_path. 'includes/functions.php' );
require_once( $root_path. 'includes/db.php' );