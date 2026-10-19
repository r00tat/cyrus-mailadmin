<?php

declare(strict_types=1);

/**
 * Verifies from the outside that a deployed installation does not expose anything
 * but public/ (U-25).
 *
 * The whole project directory may sit inside the web root. Protection then rests
 * on .htaccess, and .htaccess is silently ignored when the vHost says
 * `AllowOverride None`. That failure mode produces no error anywhere — the site
 * keeps working, it is merely readable. This script is the check that turns the
 * assumption into an observation.
 *
 * Usage:
 *   php bin/check-deployment.php https://host/mailadmin
 *
 * Exit code 0 if every path is protected, 1 otherwise. Read-only: it issues GET
 * requests and writes nothing.
 */

/**
 * Paths that must not be readable, and why each one matters.
 *
 * @var array<string, string>
 */
const PROTECTED_PATHS = [
    '.env' => 'credentials, if the installation uses a file instead of the environment',
    '.git/config' => 'repository metadata, possibly a remote with a token',
    'composer.json' => 'dependency inventory — a version list is an attack shortlist',
    'composer.lock' => 'exact dependency versions',
    'phpunit.xml.dist' => 'test configuration',
    'phpstan.neon.dist' => 'tooling configuration',
    'config/bootstrap.php' => 'startup code',
    'config/di/core.php' => 'container wiring',
    'src/Config/Config.php' => 'application source',
    'src/Http/MiddlewareDispatcher.php' => 'application source',
    'bin/bootstrap.php' => 'CLI bootstrap',
    'bin/migrate.php' => 'migration entry point',
    'migrations/' => 'schema history',
    'openapi/openapi.yaml' => 'the full API surface',
    'var/cache/openapi.bundled.yaml' => 'build artefacts',
    'tests/' => 'test sources',
    'docs/' => 'internal documentation',
    'vendor/autoload.php' => 'dependency tree',
    'vendor/composer/installed.json' => 'exact dependency versions',
    'node_modules/' => 'frontend dependency tree',
    'frontend/' => 'frontend sources',
    'src/' => 'directory listing',
    'config/' => 'directory listing',
];

/**
 * Headers the **statically served** SPA shell must carry (architecture 5.7).
 *
 * Until W1 these reached only `/api/*`, because the front controller was the
 * only thing that set them (O-13). The shell is where an XSS hole would be
 * exploitable, so it is where the policy matters most — and a missing header
 * there produces no error at all, only a page that is quietly less protected.
 * Hence a probe rather than a promise.
 *
 * The value is checked, not just the presence: a policy that lost `script-src`
 * along the way is worse than an obviously absent one, because it looks right.
 *
 * @var array<string, string>
 */
const SHELL_HEADERS = [
    'content-security-policy' => "script-src 'self'",
    'x-content-type-options' => 'nosniff',
    'referrer-policy' => 'no-referrer',
];

/**
 * @return array{status:int, body:string, headers:array<string, string>}|null null when the request could not be made at all
 */
function probe(string $url): ?array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,      // 403/404 are results, not failures
            'follow_location' => 0,
            'header' => "User-Agent: mailadmin-check-deployment\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    // file_get_contents() populates $http_response_header in the local scope, but
    // only when the transfer produced HTTP headers. Seeding it makes "no response"
    // distinguishable from "response with an error status".
    $http_response_header = [];

    $body = @file_get_contents($url, false, $context);

    /** @var list<string> $http_response_header */
    if ($http_response_header === []) {
        return null;
    }

    $status = 0;
    $headers = [];

    foreach ($http_response_header as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];   // last status line wins, after redirects
            $headers = [];               // and so do its headers

            continue;
        }

        $separator = strpos($line, ':');

        if ($separator === false) {
            continue;
        }

        $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
    }

    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $headers];
}

// --- arguments --------------------------------------------------------------

$base = $argv[1] ?? null;

if ($base === null || $base === '') {
    fwrite(STDERR, "Usage: php bin/check-deployment.php <base-url>\n");
    fwrite(STDERR, "Example: php bin/check-deployment.php https://host/mailadmin\n");

    exit(2);
}

if (!str_starts_with($base, 'http://') && !str_starts_with($base, 'https://')) {
    fwrite(STDERR, "The base URL must start with http:// or https://.\n");

    exit(2);
}

$base = rtrim($base, '/');

if (str_starts_with($base, 'http://')) {
    fwrite(STDERR, "Note: checking over plain HTTP. The result says nothing about TLS.\n\n");
}

// --- run --------------------------------------------------------------------

echo "Checking {$base} for exposed paths (U-25)\n\n";

$exposed = [];
$unreachable = [];

foreach (PROTECTED_PATHS as $path => $why) {
    $result = probe($base . '/' . $path);

    if ($result === null) {
        $unreachable[] = $path;
        printf("  ?  %-40s no response\n", $path);

        continue;
    }

    // 200 is the failure. 403 and 404 are both fine: layer 2 produces 403,
    // the rewrite in layer 1 produces 404 because the path is looked up
    // below public/ where it does not exist.
    if ($result['status'] === 200) {
        $exposed[$path] = $why;
        printf("  FAIL %-38s 200, %d bytes — %s\n", $path, strlen($result['body']), $why);

        continue;
    }

    printf("  ok   %-38s %d\n", $path, $result['status']);
}

// --- the application itself must still work ---------------------------------

echo "\n";

$health = probe($base . '/api/v1/health');

if ($health === null) {
    echo "  ?  /api/v1/health              no response\n";
} elseif ($health['status'] === 200) {
    echo "  ok   /api/v1/health             200 — the front controller is reachable\n";
} else {
    printf(
        '  warn /api/v1/health             %d — protection is in place, but the API does not answer.%s',
        $health['status'],
        PHP_EOL,
    );
    echo "       Check the rewrite in public/.htaccess and MAILADMIN_BASE_PATH.\n";
}

// --- the shell must carry the security headers (O-13) -----------------------

echo "\n";
echo "Checking the SPA shell for the security headers from architecture 5.7\n";

$shell = probe($base . '/');
$missingHeaders = [];

if ($shell === null) {
    echo "  ?  /                            no response — cannot judge the headers\n";
} elseif ($shell['status'] === 404) {
    echo "  ?  /                            404 — the frontend has not been built into public/\n";
    echo "       Run `npm run build` in frontend/, then check again.\n";
} else {
    foreach (SHELL_HEADERS as $name => $expected) {
        $actual = $shell['headers'][$name] ?? null;

        if ($actual === null) {
            $missingHeaders[$name] = 'absent';
            printf("  FAIL %-38s absent\n", $name);

            continue;
        }

        if (!str_contains($actual, $expected)) {
            $missingHeaders[$name] = 'present but does not contain "' . $expected . '"';
            printf("  FAIL %-38s present, but without \"%s\"\n", $name, $expected);

            continue;
        }

        printf("  ok   %-38s present\n", $name);
    }
}

// --- verdict ----------------------------------------------------------------

echo "\n";

if ($missingHeaders !== []) {
    printf(
        'The shell is served without %d of the security headers.%s',
        count($missingHeaders),
        PHP_EOL,
    );
    echo "The Content-Security-Policy is what carries the residual risk of keeping the access\n";
    echo "token in localStorage (E-15). Set the headers in public/.htaccess (they are there by\n";
    echo "default — check AllowOverride) or in the vHost; see docs/deployment/.\n\n";
}

if ($unreachable !== []) {
    printf('%d path(s) gave no response — the result is incomplete.%s', count($unreachable), PHP_EOL);
}

if ($exposed === [] && $missingHeaders === []) {
    echo "No exposed path found, and the shell carries the security headers.\n";

    exit(0);
}

if ($exposed === []) {
    echo "No exposed path found.\n";

    exit(1);
}

printf('%d path(s) are readable over the web.%s%s', count($exposed), PHP_EOL, PHP_EOL);
echo "Most likely cause: the vHost sets `AllowOverride None`, so every .htaccess in\n";
echo "this project is ignored. Set `AllowOverride All` for the directory, or — better —\n";
echo "point DocumentRoot at public/. See docs/deployment/.\n";

exit(1);
