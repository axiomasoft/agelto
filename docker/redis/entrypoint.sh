#!/bin/sh

set -eu

[ "$#" -ge 3 ] || {
    echo 'Redis command must include redis-server --aclfile <path>' >&2
    exit 64
}

[ "$1" = 'redis-server' ] || {
    echo 'Redis command must start with redis-server' >&2
    exit 64
}

[ "$2" = '--aclfile' ] || {
    echo 'Redis command must declare --aclfile as its first option' >&2
    exit 64
}

source_acl=$3
runtime_dir=/run/agelto-redis
runtime_acl=$runtime_dir/users.acl

umask 077
mkdir -p "$runtime_dir"
cp "$source_acl" "$runtime_acl"
chown redis:redis "$runtime_dir" "$runtime_acl"
chmod 0700 "$runtime_dir"
chmod 0600 "$runtime_acl"

shift 3

exec /usr/local/bin/docker-entrypoint.sh redis-server --aclfile "$runtime_acl" "$@"
