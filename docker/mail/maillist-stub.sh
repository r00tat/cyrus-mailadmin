#!/bin/sh
# Test stand-in for maillist.py: records which list key Postfix handed over.
#
# Readable by others (the app container reads the log), and one append per
# call: the entry is built first and written with a single printf, so parallel
# pipe processes cannot interleave their lines.
set -eu
umask 022
log=/var/log/maillist-stub/calls.log
[ "${1:-}" = "--" ] && shift
message=$(cat)
printf 'key=%s\n%s\n\n--end--\n' "${1:-}" "$message" >> "$log"
exit 0
