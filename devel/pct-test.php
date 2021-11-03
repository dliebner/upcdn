<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

$tJob = TranscodingJob::getById(48);

$tJob->finishTranscode();
