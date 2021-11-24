To build the executable binary, use the script `./build.sh`.

Dependencies on Ubuntu: `apt install g++ make cmake git redis-server`

$ time for n in {1..1000}; do echo $n 1000; done | ./redis-pipe