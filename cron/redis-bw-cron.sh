#!/bin/bash

START=`date +%s`

while [ $(( $(date +%s) - 60 )) -lt $START ]; do

	redis-cli GET bgcdn:bw_30sec_exp_$(($(date +%s) + 1)) > /home/transcodey/30s_bw.txt
	sleep 1

done