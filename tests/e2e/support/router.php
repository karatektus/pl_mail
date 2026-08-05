<?php

declare(strict_types=1);

/**
 * The front controller PHP's built-in server uses for the browser suite.
 *
 * `php -S … -t public public/index.php` looks like it would do, and it does
 * answer /login — but naming index.php as the router means index.php answers
 * EVERYTHING, including the 56 compiled asset files /login pulls in. Each one
 * boots a Symfony kernel and gets a 404 back. Returning false instead hands the
 * request back to the server, which serves the file off disk with the right
 * content type and no PHP at all.
 *
 * Two things are then fixed up before index.php is included, because the server
 * has pointed both at this file:
 *
 *   SCRIPT_NAME     — Symfony derives the base URL from it, so every generated
 *                     link would be prefixed with /tests/e2e/support/router.php
 *   SCRIPT_FILENAME — read by the Runtime component and by the profiler
 *
 * Not in public/, deliberately: anything in there is web-reachable in a real
 * deployment, and a second front controller that nobody audits is not something
 * to ship for the sake of a test server.
 *
 * @see playwright.config.ts, which explains why the server is PHP's own rather
 *      than `symfony serve`
 */

$publicDir = dirname(__DIR__, 3) . '/public';

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = \is_string($path) ? urldecode($path) : '/';

// str_contains rather than a realpath check below: the server has already
// normalised the request path, so a '..' surviving to here is an encoded one,
// which is an attempt rather than an accident. This binds to 127.0.0.1 and
// serves a test fixture database, but "test-only" is exactly the argument that
// puts a traversal into a real deployment later.
if ('/' !== $path && !str_contains($path, '..') && is_file($publicDir . $path)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicDir . '/index.php';

// The environment, which the cli-server SAPI does not hand over.
//
// It builds $_SERVER from the HTTP request and stops there: getenv() answers,
// $_SERVER and $_ENV do not. Symfony's Dotenv reads $_SERVER — populate() skips
// a name only when `isset($_SERVER[$name])` — so without this every variable
// the server was started with loses to the committed default in .env. On CI
// that means DATABASE_URL silently reverts to `app:!ChangeMe!@127.0.0.1`, which
// is the failure this whole file was written to get away from, arriving by a
// different road. APP_ENV goes the same way, and the suite would quietly run
// against the dev environment.
//
// ??= rather than assignment: REQUEST_URI and friends are already in there and
// describe this request, not the process.
foreach (getenv() as $name => $value) {
    // HTTP_ is excluded for the reason Dotenv excludes it — $_SERVER['HTTP_X']
    // is indistinguishable from an X header once a Request is built, so an
    // ambient HTTP_PROXY would arrive as a client-supplied Proxy header. That
    // is httpoxy (CVE-2016-5385), and it is cheaper to not open than to argue
    // about whether a test server can be reached.
    if (str_starts_with($name, 'HTTP_')) {
        continue;
    }

    $_SERVER[$name] ??= $value;
    $_ENV[$name] ??= $value;
}

require $publicDir . '/index.php';
