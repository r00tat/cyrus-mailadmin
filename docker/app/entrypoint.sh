#!/bin/sh
# Installs the test CA into the system trust store, then hands over.
#
# This is what lets every client — PHP streams, curl, openssl — verify the mail
# container's certificate without a single test switching verification off. The
# CA is bind-mounted by the compose file; `update-ca-certificates` is what turns
# a file in /usr/local/share/ca-certificates into a trusted issuer.

set -eu

if [ -f /usr/local/share/ca-certificates/mailadmin-testenv-ca.crt ]; then
    update-ca-certificates >/dev/null 2>&1 || true
else
    echo '[entrypoint] WARNING: the test CA is not mounted; TLS verification will fail.' >&2
    echo '[entrypoint] Run docker/certs/generate.sh and recreate this container.' >&2
fi

exec "$@"
