#!/bin/sh
# Test stand-in for maillist.py: records which list key Postfix handed over.
set -eu
log=/var/log/maillist-stub/calls.log
[ "${1:-}" = "--" ] && shift
{ printf 'key=%s\n' "${1:-}"; cat; printf '\n--end--\n'; } >> "$log"
exit 0
