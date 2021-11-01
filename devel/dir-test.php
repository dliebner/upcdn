<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

/** @var SplFileInfo[] $files */
$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(realpath($root_path . 'www/v/')),
	RecursiveIteratorIterator::LEAVES_ONLY
);

foreach( $files as $filename => $file ) {

	if( $file->isDir() ) {

		echo "dir ";

	}

	echo "$filename vs " . $file->getPath() . " vs " . $file->getBasename() . " vs " . $file->getFilename() . "\n";

}
