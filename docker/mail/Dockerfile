# Postfix + Cyrus IMAP + timsieved + saslauthd/pam_mysql, one container.
#
# WHY DEBIAN AND NOT A COMMUNITY IMAGE (F5, open point 1)
# There is no maintained official Cyrus image, and a community one would tie the
# environment to a version and a maintainer we cannot see. Debian trixie carries
# exactly the versions the production host runs:
#
#     Cyrus IMAP   3.10.2   production: 3.10.2
#     Postfix      3.10.x   production: 3.10.5
#     Cyrus SASL   2.1.28   saslauthd
#
# so "it works here" means something about production. The build takes a couple
# of minutes once and then sits in the layer cache.
#
# WHY NO SOURCE BUILD OF pam_mysql (F5, open point 2 — the flagged risk)
# The package was expected to be gone from current distributions. It is not:
# `libpam-mysql` 0.8.2 is in trixie main. Nothing is compiled here, and the
# highest-risk item of this work package turned out to cost one apt line.
#
# WHY ALL FOUR IN ONE CONTAINER
# Production runs them in one container under supervisord, and saslauthd is
# reached over a UNIX socket that Postfix and Cyrus both open. Splitting them
# would mean inventing a socket-sharing arrangement that production does not
# have.

FROM debian:trixie-slim

# Nothing in this image may try to start a service through invoke-rc.d — there
# is no init during the build.
RUN set -eux; \
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; \
    chmod +x /usr/sbin/policy-rc.d

ARG DEBIAN_FRONTEND=noninteractive

RUN set -eux; \
    echo 'postfix postfix/main_mailer_type select Internet Site' | debconf-set-selections; \
    echo 'postfix postfix/mailname string mail.example.test' | debconf-set-selections; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        busybox-syslogd \
        ca-certificates \
        cyrus-admin \
        cyrus-clients \
        cyrus-imapd \
        libio-socket-ssl-perl \
        libpam-mysql \
        libsasl2-modules \
        mariadb-client \
        netcat-openbsd \
        openssl \
        postfix \
        postfix-mysql \
        procps \
        sasl2-bin \
        supervisor; \
    rm -rf /var/lib/apt/lists/*

# --- Cyrus ------------------------------------------------------------------
COPY cyrus/imapd.conf /etc/imapd.conf
COPY cyrus/cyrus.conf /etc/cyrus.conf

# --- Postfix ----------------------------------------------------------------
COPY postfix/main.cf /etc/postfix/main.cf
COPY postfix/master.cf /etc/postfix/master.cf
COPY postfix/mysql-mydestination.cf.tmpl \
     postfix/mysql-virtual.cf.tmpl \
     postfix/mysql-canonical.cf.tmpl \
     postfix/mysql-sender.cf.tmpl \
     /etc/postfix/templates/
COPY postfix/sasl/smtpd.conf /etc/postfix/sasl/smtpd.conf

# --- PAM / saslauthd --------------------------------------------------------
# One template, installed under every service name saslauthd can be asked for.
COPY pam/pam_mysql.tmpl /etc/pam.d/templates/pam_mysql.tmpl

# --- Scripts ----------------------------------------------------------------
COPY supervisord.conf /etc/supervisor/supervisord.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY healthcheck.sh /usr/local/bin/healthcheck.sh
COPY create-mailboxes.pl /usr/local/bin/create-mailboxes.pl
RUN chmod +x /usr/local/bin/entrypoint.sh \
             /usr/local/bin/healthcheck.sh \
             /usr/local/bin/create-mailboxes.pl

EXPOSE 25 143 587 993 4190

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
