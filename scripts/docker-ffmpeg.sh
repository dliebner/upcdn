#!/bin/bash

while getopts d:i:o:b:w:h:sm flag
do
    case "${flag}" in
        d) dir=${OPTARG};;
        i) inFile=${OPTARG};;
        o) outFile=${OPTARG};;
        b) bitRate=${OPTARG};;
        w) constrainWidth=${OPTARG};;
        h) constrainHeight=${OPTARG};;
        s) hlsFormat=1;;
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

if [ ! -z "$hlsFormat" ]; then
	encodeParams+=( -f hls )
	encodeParams+=( -hls_playlist_type vod )
	encodeParams+=( -hls_init_time 2 )
	encodeParams+=( -hls_time 7 )
fi


docker run "${dirParams[@]}" -d dliebner/ffmpeg-entrydefault /bin/bash -c \
"ffmpeg -hwaccel none \
    -progress /dev/stdout \
    -i $inFile \
    -c:v h264 \
    -preset medium \
    -b:v $bitRate \
    -vf \"select='eq(n,0)+if(gt(t-prev_selected_t,1/30.50),1,0)'\",scale=$constrainWidth:$constrainHeight -vsync 0 \
    -sws_flags bicubic \
    -movflags +faststart -pix_fmt yuv420p \
    -map 0:v:0 -map 0:a:0 \
    -map_metadata -1 \
    ${encodeParams[@]}
    $outFile && \
chown $UID:$UID $outFile"
