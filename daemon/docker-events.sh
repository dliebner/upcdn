#!/bin/sh 

docker events --filter 'type=container' | while read event

do
  
    echo $event >> log.txt

done
