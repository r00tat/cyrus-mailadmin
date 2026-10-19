#!/bin/sh
# Generates the test CA and the server certificate for the mail container.
#
# Self-signed with our own CA, and the CA certificate goes into the trust store
# of the application container. That is the point: certificate verification stays
# switched ON in every test (architecture 9.2, finding S-5). A test environment
# that needs `verify_peer => false` proves nothing about a client whose whole job
# is to verify.
#
# Output lands in docker/certs/out/ and is gitignored — `*.pem` and this
# directory. Keys are regenerated, never committed.
#
# Everything here is a test value with a one-year lifetime.

set -eu

here="$(cd "$(dirname "$0")" && pwd)"
out="$here/out"

# The name the certificate is issued for. The compose file gives the mail
# container this network alias, and bin/test-env pins it to 127.0.0.1 for the
# host side, so the same certificate verifies from inside and outside.
hostname='mail.example.test'

if [ -f "$out/server.crt" ] && [ "${1:-}" != '--force' ]; then
    echo "certs: $out already populated (use --force to regenerate)"
    exit 0
fi

mkdir -p "$out"
rm -f "$out"/*.crt "$out"/*.key "$out"/*.srl "$out"/*.csr "$out"/*.cnf

# --- CA ---------------------------------------------------------------------
openssl req -x509 -newkey rsa:2048 -sha256 -days 365 -nodes \
    -keyout "$out/ca.key" -out "$out/ca.crt" \
    -subj '/CN=mailadmin test CA/O=mailadmin test environment' \
    -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
    -addext 'keyUsage=critical,keyCertSign,cRLSign' 2>/dev/null

# --- Server certificate -----------------------------------------------------
cat >"$out/server.cnf" <<EOF
[req]
distinguished_name = dn
prompt             = no

[dn]
CN = $hostname
O  = mailadmin test environment

[ext]
basicConstraints       = critical,CA:FALSE
keyUsage               = critical,digitalSignature,keyEncipherment
extendedKeyUsage       = serverAuth
subjectAltName         = DNS:$hostname, DNS:mail, DNS:localhost, IP:127.0.0.1, IP:::1
EOF

openssl req -newkey rsa:2048 -sha256 -nodes \
    -keyout "$out/server.key" -out "$out/server.csr" \
    -config "$out/server.cnf" 2>/dev/null

openssl x509 -req -in "$out/server.csr" -CA "$out/ca.crt" -CAkey "$out/ca.key" \
    -CAcreateserial -out "$out/server.crt" -days 365 -sha256 \
    -extfile "$out/server.cnf" -extensions ext 2>/dev/null

# Cyrus wants to read the key as user `cyrus`; the container chowns it on start.
chmod 644 "$out/server.crt" "$out/ca.crt"
chmod 640 "$out/server.key"
rm -f "$out/server.csr"

echo "certs: wrote CA and server certificate for $hostname to $out"
