<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

$basePath = realpath($root_path . 'transcoding/XmsABEKThX2GdMPJ_300x250/out/');

/** @var SplFileInfo[] $files */
$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($basePath),
	RecursiveIteratorIterator::LEAVES_ONLY
);

foreach( $files as $filename => $file ) {

	// Get real and relative path for current file
	$filePath = $file->getRealPath();
	$relativePath = substr($filePath, strlen($basePath) + 1);

	if( $file->isDir() ) {

		$dirName = $relativePath;

	} else {

		$dirName = dirname($relativePath);

	}

	echo "$dirName\n$relativePath\n\n";

}
