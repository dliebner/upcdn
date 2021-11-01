#!/bin/bash

while getopts d:i:o:b:w:h:st:m flag
do
    case "${flag}" in
        d) dir=${OPTARG};;
        i) inFile=${OPTARG};;
        o) outFile=${OPTARG};;
        b) bitRate=${OPTARG};;
        w) constrainWidth=${OPTARG};;
        h) constrainHeight=${OPTARG};;
        s) hlsOutput=1;;
        t) hlsTime=${OPTARG};;
        m) mute=1;;
    esac
done

dirParams=()

if [ ! -z "$dir" ]; then
	dirParams+=( -v "$dir":"$dir" )
	dirParams+=( -w "$dir" )
fi


encodeParams=()

if [ ! -z "$mute" ]; then
	encodeParams+=( -an )
fi

if [ ! -z "$hlsOutput" ]; then
    if [ -z "$hlsTime" ]; then
        # aiming for 400k chunks by default
        divide=400000; (( by=bitRate/8 )); (( hlsTime=(divide+by-1)/by ))
    fi
    if [ $hlsTime -lt 2 ]; then
        hlsInitTime=1
    else
        hlsInitTime=2
    fi
	encodeParams+=( -f hls )
	encodeParams+=( -hls_playlist_type vod )
	encodeParams+=( -hls_init_time "$hlsInitTime" )
	encodeParams+=( -hls_time "$hlsTime" )
fi

chown -R $(id -u bgcdn):$(id -g bgcdn) "$dir"

echo docker run "${dirParams[@]}" -d dliebner/ffmpeg-entrydefault ffmpeg -hwaccel none \
-progress /dev/stdout \
-i "$inFile" \
-c:v h264 \
-preset medium \
-b:v "$bitRate" \
-vf "select='eq(n,0)+if(gt(t-prev_selected_t,1/30.50),1,0)'",scale="$constrainWidth:$constrainHeight" -vsync 0 \
-sws_flags bicubic \
-movflags +faststart -pix_fmt yuv420p \
-map 0:v:0 -map 0:a:0? \
-map_metadata -1 \
"${encodeParams[@]}" \
"$outFile"
