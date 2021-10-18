#!/bin/bash

# TODO: This script should be replaced by a C++ program for increased performance

# GlobalLog "|/home/bgcdn/scripts/redis-pipe.sh" "%{end:sec}t %O %>s %U%q"
# ts, bytes, status, URL

while read logline; do

	parts=(${logline})
	ts=${parts[0]}
	bytes=${parts[1]}
	status=${parts[2]}

	# Cumulative chunk bandwidth
	redis-cli INCRBY bgcdn:bw_chunk ${bytes}

	# Rolling 30s bandwidth
	start=$((${ts} - 29))

	for (( i=$start; i<=$ts; i++ ))
	do

		expires=$((${i} + 30))
		redis-cli INCRBY bgcdn:bw_30sec_exp_${expires} ${bytes}
		redis-cli EXPIREAT bgcdn:bw_30sec_exp_${expires} ${expires}

	done

	# 404s
	if [ "$status" == "404" ]; then

		uri=${parts[3]}
		redis-cli HSETNX bgcdn:404_uris "$uri" 1

	fi

done