#!/usr/bin/perl
# Creates (or lists, or deletes) Cyrus mailboxes for the fixture accounts.
#
# WHY NOT cyradm: cyradm's TLS handling is awkward to drive from a script and it
# does not verify the server certificate. Verification is the one thing this
# environment must not switch off (architecture 9.2), so the ~90 lines below
# speak IMAPS directly, with the test CA and a checked host name. If the
# certificate is wrong, seeding fails — which is exactly what should happen.
#
# It is not a substitute for M1. M1 writes the client the application uses; this
# is scaffolding so the mailboxes exist before M1 has anything to test against.
#
#   create-mailboxes.pl create <cyrus-user> ...
#   create-mailboxes.pl delete <cyrus-user> ...
#   create-mailboxes.pl list   [<pattern>]
#   create-mailboxes.pl exists <cyrus-user>      exit 0 = yes, 1 = no

use strict;
use warnings;
use IO::Socket::SSL qw(SSL_VERIFY_PEER $SSL_ERROR);
use MIME::Base64 qw(encode_base64);

my $HOST    = $ENV{CYRUS_HOST}     // 'localhost';
my $PORT    = $ENV{CYRUS_PORT}     // 993;
my $CA      = $ENV{CYRUS_CA_FILE}  // '/etc/ssl/cyrus/ca.crt';
my $USER    = $ENV{CYRUS_ADMIN}    // 'cyrus';
my $PASS    = $ENV{CYRUS_ADMIN_PASSWORD} // '';

my $action = shift @ARGV // '';
$action =~ /^(create|delete|list|exists)$/
    or die "usage: $0 create|delete|list|exists [arguments]\n";

my $sock = IO::Socket::SSL->new(
    PeerHost        => $HOST,
    PeerPort        => $PORT,
    SSL_ca_file     => $CA,
    SSL_verify_mode => SSL_VERIFY_PEER,
    # The certificate carries DNS:localhost and DNS:mail.example.test; whichever
    # name we dialled has to be on it.
    SSL_verifycn_name   => $HOST,
    SSL_verifycn_scheme => 'imap',
    Timeout         => 15,
) or die "cannot connect to $HOST:$PORT over TLS: $!, $SSL_ERROR\n";

$sock->autoflush(1);

my $greeting = <$sock>;
defined $greeting or die "no greeting from $HOST:$PORT\n";

my $tag = 0;

sub command {
    my ($line) = @_;
    my $id = sprintf 'a%03d', ++$tag;
    print {$sock} "$id $line\r\n";

    my @response;
    while (my $reply = <$sock>) {
        $reply =~ s/\r?\n\z//;
        push @response, $reply;
        return (($reply =~ /^\Q$id\E OK/) ? 1 : 0, \@response)
            if $reply =~ /^\Q$id\E (OK|NO|BAD)/;
    }
    die "connection closed while waiting for the answer to: $line\n";
}

# SASL PLAIN as the administrator. The empty authzid means "act as myself";
# proxy authentication (architecture 9.3) puts the target user there instead,
# and that is M2's job, not this script's.
my $credential = encode_base64("\0$USER\0$PASS", '');
my ($ok, $answer) = command("AUTHENTICATE PLAIN $credential");
$ok or die "administrator login failed: @{$answer}\n";

my $status = 0;

if ($action eq 'list') {
    my $pattern = shift @ARGV // '*';
    (undef, $answer) = command(qq{LIST "" "$pattern"});
    print "$_\n" for grep { /^\* LIST/ } @{$answer};
}
elsif ($action eq 'exists') {
    my $user = shift @ARGV or die "exists needs a user name\n";
    (undef, $answer) = command(qq{LIST "" "user/$user"});
    $status = (grep { /^\* LIST/ } @{$answer}) ? 0 : 1;
}
else {
    for my $user (@ARGV) {
        my $verb = $action eq 'create' ? 'CREATE' : 'DELETE';

        # Deleting somebody else's mailbox needs the right to do so first.
        command(qq{SETACL "user/$user" "$USER" c}) if $verb eq 'DELETE';

        ($ok, $answer) = command(qq{$verb "user/$user"});

        if ($ok) {
            printf "%s user/%s\n", lc $verb, $user;
        }
        # Already there / already gone is not an error: seeding runs again
        # between test runs.
        elsif (grep { /already exists|does not exist|Mailbox does not exist/i } @{$answer}) {
            printf "%s user/%s (nothing to do)\n", lc $verb, $user;
        }
        else {
            printf STDERR "FAILED %s user/%s: %s\n", lc $verb, $user, join ' | ', @{$answer};
            $status = 1;
        }
    }
}

command('LOGOUT');
close $sock;
exit $status;
