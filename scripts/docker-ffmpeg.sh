#!/bin/bash

while getopts d:i:o:b:w:h:s:m flag
do
    case "${flag}" in
        d) dir=${OPTARG};;
        i) inFile=${OPTARG};;
        o) outFile=${OPTARG};;
        b) bitRate=${OPTARG};;
        w) constrainWidth=${OPTARG};;
        h) constrainHeight=${OPTARG};;
        s) hlsOutputDir=${OPTARG};;
        m) mute=1;;
    esac
done

dirParams=()

if [ ! -z "$dir" ]; then
	dirParams+=( -v "$dir":"$dir" )
	dirParams+=( -w "$dir" )
fi


encodeParams=()
postEncodeChownParams=()

if [ ! -z "$mute" ]; then
	encodeParams+=( -an )
fi

if [ ! -z "$hlsOutputDir" ]; then
	encodeParams+=( -f hls )
	encodeParams+=( -hls_playlist_type vod )
	encodeParams+=( -hls_init_time 2 )
	encodeParams+=( -hls_time 7 )
    postEncodeChownParams+=( -R $UID:$UID $hlsOutputDir )
else
    postEncodeChownParams+=( $UID:$UID $outFile )
fi


docker run "${dirParams[@]}" -d dliebner/ffmpeg-entrydefault ffmpeg -hwaccel none \
-progress /dev/stdout \
-i "$inFile" \
-c:v h264 \
-preset medium \
-b:v "$bitRate" \
-vf "select='eq(n,0)+if(gt(t-prev_selected_t,1/30.50),1,0)'",scale="$constrainWidth:$constrainHeight" -vsync 0 \
-sws_flags bicubic \
-movflags +faststart -pix_fmt yuv420p \
-map 0:v:0 -map 0:a:0 \
-map_metadata -1 \
"${encodeParams[@]}" \
"${hlsOutputDir}${outFile}"

# TODO: figure out how to safely chown the output to bgcdn user