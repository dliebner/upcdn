<?php

define('IN_SCRIPT', 1);

$root_path = './../';

require_once( $root_path. 'common.php' );

$ffProbeResult = new FFProbeResult("{\"format\":{\"size\":\"7119382\",\"tags\":{\"major_brand\":\"mp42\",\"creation_time\":\"2021-12-19T03:34:50.000000Z\",\"minor_version\":\"0\",\"compatible_brands\":\"mp42mp41\"},\"bit_rate\":\"5307690\",\"duration\":\"10.730667\",\"filename\":\"phpEjcUIc\",\"nb_streams\":2,\"start_time\":\"0.000000\",\"format_name\":\"mov,mp4,m4a,3gp,3g2,mj2\",\"nb_programs\":0,\"probe_score\":100},\"streams\":[{\"refs\":1,\"tags\":{\"encoder\":\"AVC Coding\",\"language\":\"eng\",\"handler_name\":\"\\u001fMainconcept Video Media Handler\",\"creation_time\":\"2021-12-19T03:34:50.000000Z\"},\"index\":0,\"level\":31,\"width\":728,\"height\":90,\"is_avc\":\"true\",\"pix_fmt\":\"yuv420p\",\"profile\":\"77\",\"bit_rate\":\"4966375\",\"duration\":\"10.683333\",\"codec_tag\":\"0x31637661\",\"nb_frames\":\"641\",\"start_pts\":0,\"time_base\":\"1/60000\",\"codec_name\":\"h264\",\"codec_type\":\"video\",\"start_time\":\"0.000000\",\"coded_width\":736,\"color_range\":\"tv\",\"color_space\":\"smpte170m\",\"disposition\":{\"dub\":0,\"forced\":0,\"lyrics\":0,\"comment\":0,\"default\":1,\"karaoke\":0,\"original\":0,\"attached_pic\":0,\"clean_effects\":0,\"visual_impaired\":0,\"hearing_impaired\":0,\"timed_thumbnails\":0},\"duration_ts\":641000,\"coded_height\":96,\"has_b_frames\":1,\"r_frame_rate\":\"60/1\",\"avg_frame_rate\":\"60/1\",\"color_transfer\":\"smpte170m\",\"chroma_location\":\"left\",\"codec_long_name\":\"unknown\",\"codec_time_base\":\"1/120\",\"color_primaries\":\"smpte170m\",\"nal_length_size\":\"4\",\"codec_tag_string\":\"avc1\",\"bits_per_raw_sample\":\"8\"},{\"tags\":{\"language\":\"eng\",\"handler_name\":\"#Mainconcept MP4 Sound Media Handler\",\"creation_time\":\"2021-12-19T03:34:50.000000Z\"},\"index\":1,\"profile\":\"1\",\"bit_rate\":\"317375\",\"channels\":2,\"duration\":\"10.683333\",\"codec_tag\":\"0x6134706d\",\"nb_frames\":\"503\",\"start_pts\":0,\"time_base\":\"1/48000\",\"codec_name\":\"aac\",\"codec_type\":\"audio\",\"sample_fmt\":\"fltp\",\"start_time\":\"0.000000\",\"disposition\":{\"dub\":0,\"forced\":0,\"lyrics\":0,\"comment\":0,\"default\":1,\"karaoke\":0,\"original\":0,\"attached_pic\":0,\"clean_effects\":0,\"visual_impaired\":0,\"hearing_impaired\":0,\"timed_thumbnails\":0},\"duration_ts\":512800,\"sample_rate\":\"48000\",\"max_bit_rate\":\"317625\",\"r_frame_rate\":\"0/0\",\"avg_frame_rate\":\"0/0\",\"channel_layout\":\"stereo\",\"bits_per_sample\":0,\"codec_long_name\":\"unknown\",\"codec_time_base\":\"1/48000\",\"codec_tag_string\":\"mp4a\"}]}");

print_r($ffProbeResult);

$videoStream = $ffProbeResult->videoStreams[0];

print_r([
	'displayWidth' => $videoStream->displayWidth(),
	'displayHeight' => $videoStream->displayHeight(),
	'newDisplayWidth' => 2 * round($videoStream->height * $videoStream->sampleAspectRatioFloat / 2)
]);