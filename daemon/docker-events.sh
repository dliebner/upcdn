#!/bin/sh 

docker events --filter 'type=container' --filter 'event=die' | while read event

do

	# Pass finished docker transcoding jobs to php to finish the job (TranscodingJob->finishTranscode())
	container_id=$(echo $event | sed -r -n 's/.* container die ([a-zA-Z0-9]+) .*/\1/p')

	if [ ! -z "$container_id" ]; then
		sudo -u upcdn php /home/upcdn/scripts/docker-container-die.php "$container_id"
	fi

done
