#!/bin/sh
# Brings the container from "freshly started with empty volumes" to "Cyrus,
# Postfix and saslauthd can be started".
#
# Everything here is idempotent: the volumes survive a restart, and a restart is
# the normal way this environment comes back.

set -eu

log() { printf '[entrypoint] %s\n' "$*"; }

: "${MAIL_DB_HOST:=db}"
: "${MAIL_DB_NAME:=mail}"
: "${MAIL_DB_USER:=mailreader}"
: "${MAIL_DB_PASSWORD:=}"

# ---------------------------------------------------------------------------
# Render the files that carry the database credentials.
#
# They live in the compose file, which is the single place a reader has to look.
# The rendered files contain the password in the clear — unavoidable, both
# pam_mysql and the Postfix maps read it from disk (this is finding S-4 in
# production; here it is a test value on a read-only account).
# ---------------------------------------------------------------------------
render() {
    sed -e "s|__DB_HOST__|${MAIL_DB_HOST}|g" \
        -e "s|__DB_NAME__|${MAIL_DB_NAME}|g" \
        -e "s|__DB_USER__|${MAIL_DB_USER}|g" \
        -e "s|__DB_PASSWORD__|${MAIL_DB_PASSWORD}|g" \
        "$1" >"$2"
    chmod 640 "$2"
}

for tmpl in /etc/postfix/templates/*.cf.tmpl; do
    target="/etc/postfix/$(basename "$tmpl" .tmpl)"
    render "$tmpl" "$target"
    chown root:postfix "$target"
done

# saslauthd is asked for a different PAM service name by every caller: Cyrus
# imapd says `imap`, pop3d says `pop`, timsieved says `sieve`, lmtpd says
# `lmtp`, Postfix says `smtp`. All of them get the same two-line stack.
mkdir -p /etc/pam.d
for service in imap pop sieve lmtp smtp smtpd; do
    render /etc/pam.d/templates/pam_mysql.tmpl "/etc/pam.d/$service"
    chmod 600 "/etc/pam.d/$service"
done
log "rendered PAM stacks and Postfix maps for ${MAIL_DB_USER}@${MAIL_DB_HOST}/${MAIL_DB_NAME}"

# ---------------------------------------------------------------------------
# TLS material. Mounted read-only from docker/certs/out; Cyrus reads the key as
# user `cyrus`, so it gets its own copy with the right owner.
# ---------------------------------------------------------------------------
if [ ! -f /etc/ssl/mailadmin/server.crt ]; then
    log "FATAL: /etc/ssl/mailadmin/server.crt is missing."
    log "Run docker/certs/generate.sh (bin/test-env up does it for you)."
    exit 1
fi

install -d -m 0755 /etc/ssl/cyrus
install -m 0644 -o cyrus -g mail /etc/ssl/mailadmin/server.crt /etc/ssl/cyrus/server.crt
install -m 0640 -o cyrus -g mail /etc/ssl/mailadmin/server.key /etc/ssl/cyrus/server.key
install -m 0644 -o cyrus -g mail /etc/ssl/mailadmin/ca.crt /etc/ssl/cyrus/ca.crt
sed -i \
    -e 's|^tls_server_cert:.*|tls_server_cert:     /etc/ssl/cyrus/server.crt|' \
    -e 's|^tls_server_key:.*|tls_server_key:      /etc/ssl/cyrus/server.key|' \
    -e 's|^tls_client_ca_file:.*|tls_client_ca_file:  /etc/ssl/cyrus/ca.crt|' \
    /etc/imapd.conf

# Postfix reads its copy as root before dropping privileges, so the mount is
# fine as it is.

# ---------------------------------------------------------------------------
# Cyrus directories. The volumes come up empty, which wipes what the package
# created — so the structure is rebuilt here rather than at build time.
# ---------------------------------------------------------------------------
install -d -m 0750 -o cyrus -g mail /var/lib/cyrus /var/spool/cyrus /var/spool/sieve
install -d -m 0755 -o cyrus -g mail /run/cyrus
install -d -m 0750 -o cyrus -g mail /run/cyrus/socket
chown -R cyrus:mail /var/lib/cyrus /var/spool/cyrus /var/spool/sieve

if [ ! -f /var/lib/cyrus/mailboxes.db ]; then
    # `cyrus mkimap` builds the partition and configuration directory layout.
    # Cyrus master would create most of it on its own through `ctl_cyrusdb -r`,
    # but doing it here means a failure shows up in the entrypoint log rather
    # than as a mailbox that cannot be created ten minutes later.
    log "initialising the Cyrus databases"
    su cyrus -s /bin/sh -c '/usr/sbin/cyrus mkimap' || \
        log "WARNING: cyrus mkimap failed; ctl_cyrusdb -r will have to do it"
fi

# The log of the maillist stub. A volume that comes up owned by root; the pipe
# transport runs the stub as `nobody`, and the app container reads the log.
install -d -m 0755 -o nobody -g nogroup /var/log/maillist-stub

# saslauthd's socket directory. Nothing is chrooted (see master.cf), so this is
# the one place Cyrus and Postfix both look.
install -d -m 0755 /run/saslauthd

# ---------------------------------------------------------------------------
# Wait for the database. pam_mysql opens its connection on the first
# authentication, but a Postfix map that cannot reach MySQL at startup makes for
# a confusing first minute.
# ---------------------------------------------------------------------------
log "waiting for ${MAIL_DB_HOST}:3306"
attempt=0
until mariadb -h "$MAIL_DB_HOST" -u "$MAIL_DB_USER" -p"$MAIL_DB_PASSWORD" \
        "$MAIL_DB_NAME" -e 'SELECT 1 FROM allusers LIMIT 1' >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        log "FATAL: the database did not answer within 120 seconds."
        exit 1
    fi
    sleep 2
done
log "database reachable, view allusers readable"

# Postfix wants its own directories in order and a compiled aliases file.
newaliases >/dev/null 2>&1 || true
postfix set-permissions >/dev/null 2>&1 || true
postfix check || true

log "starting supervisord"
exec "$@"
