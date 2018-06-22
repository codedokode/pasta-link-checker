#!/bin/bash

set -e

trap killServer EXIT
self="run-tests"

function killServer() 
{
    if [ -n "$SERVER_PID" ]
    then
        echo "$self: Terminate PHP server with PID $SERVER_PID"
        kill -TERM "$SERVER_PID"
    fi
}

export LINK_CHECKER_TEST_SERVER_PORT=${LINK_CHECKER_TEST_SERVER_PORT:=10001}
PHPUNIT=${PHPUNIT:=vendor/bin/phpunit}

php -S 127.0.0.1:$LINK_CHECKER_TEST_SERVER_PORT -t tests/public/ & 
SERVER_PID=$!
sleep 1

$PHPUNIT "$@"