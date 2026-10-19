#!/bin/sh
# Readiness for the mail container (F5, open point 4).
#
# "The port is open" is not readiness here. Cyrus opens 993 before its databases
# are usable, and saslauthd answers its socket before it has ever reached MySQL.
# A test that starts against a half-ready container fails in a way that looks
# like a bug in the test.
#
# So this checks the thing that actually has to work: an end-to-end
# authentication through saslauthd -> pam_mysql -> allusers -> crypt(3), using a
# fixture account. It costs one round trip and removes the whole class of
# "worked on the second run".
#
# It only reports healthy once the fixtures are loaded — which is correct:
# before that the environment cannot authenticate anyone.

set -eu

: "${CYRUS_ADMIN:=cyrus}"
: "${CYRUS_ADMIN_PASSWORD:=}"

fail() { printf 'unhealthy: %s\n' "$*" >&2; exit 1; }

# 1. The listeners.
for port in 25 143 587 993 4190; do
    nc -z 127.0.0.1 "$port" 2>/dev/null || fail "nothing listening on port $port"
done

# 2. The saslauthd socket.
[ -S /run/saslauthd/mux ] || fail 'saslauthd has no socket at /run/saslauthd/mux'

# 3. The chain, for real. `testsaslauthd` speaks the same protocol Cyrus and
#    Postfix speak, and asks the `imap` PAM stack — the two pam_mysql lines.
testsaslauthd -u "$CYRUS_ADMIN" -p "$CYRUS_ADMIN_PASSWORD" -s imap >/dev/null 2>&1 \
    || fail "saslauthd refused the administrator ($CYRUS_ADMIN) — fixtures not loaded yet?"

exit 0
