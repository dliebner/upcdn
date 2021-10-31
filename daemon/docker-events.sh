#!/bin/sh 

docker events --filter 'type=container' --filter 'event=die' | while read event

do

	container_id=$(echo $event | sed -r -n 's/.* container die ([a-zA-Z0-9]+) .*/\1/p')

	if [ ! -z "$container_id" ]; then
		php /home/bgcdn/scripts/docker-container-die.php "$container_id"
	fi

done
