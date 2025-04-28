#!/bin/bash

# Parse the command-line options
while getopts d:i:o:f:w:h:q: flag
do
    case "${flag}" in
        d) dir=${OPTARG};;
        i) inFile=${OPTARG};;
        o) outFile=${OPTARG};;
        f) captureFrameIndex=${OPTARG};;
        w) constrainWidth=${OPTARG};;
        h) constrainHeight=${OPTARG};;
        q) jpegQuality=${OPTARG};;
    esac
done

# Check if width and/or height are provided
scaleCommand=""
if [ ! -z "$constrainWidth" ] || [ ! -z "$constrainHeight" ]; then
    # If one of the dimensions is omitted, set it to -2
    if [ -z "$constrainWidth" ]; then
        constrainWidth=-2
    fi
    if [ -z "$constrainHeight" ]; then
        constrainHeight=-2
    fi
    # Form the scale command
    scaleCommand="-vf scale=$(printf "%q:%q" "$constrainWidth" "$constrainHeight")"
fi

# Directory parameters
dirParams=()

if [ ! -z "$dir" ]; then
    dirParams+=( -v "$dir":"$dir" )
    dirParams+=( -w "$dir" )
fi

# Change the ownership of the directory to 'upcdn' user
chown -R $(id -u upcdn):$(id -g upcdn) "$dir"

# Run the docker container for ffmpeg command with the collected parameters
docker run "${dirParams[@]}" -d dliebner/ffmpeg-entrydefault ffmpeg -i "$inFile" -vf "select=eq(n\,$captureFrameIndex)" $scaleCommand -q:v "$jpegQuality" "$outFile"
