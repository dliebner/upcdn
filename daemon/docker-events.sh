#!/bin/sh 

docker events --filter 'type=container' --filter 'event=start' --filter 'event=stop' | while read event

do
  
    echo $event >> log.txt

done
