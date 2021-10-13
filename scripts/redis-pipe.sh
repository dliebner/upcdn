#!/bin/bash

# TODO: This script should be replaced by a C++ program for increased performance

# CustomLog "|/home/transcodey/redis-pipe.sh" "%{end:sec}t %O"

while read logline; do

	parts=(${logline})
	ts=${parts[0]}
	bytes=${parts[1]}

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

done