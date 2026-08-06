<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * One of the two Firebase files an admin pasted is not what it claims to be, or
 * the pair does not belong together.
 *
 * Carries the missing or wrong fields by name, because the admin form renders
 * getMessage() verbatim and "invalid credentials" would send someone back to
 * the Firebase console with nothing to look for. The console offers several
 * downloadable JSON files — the web app config, an OAuth client, the service
 * account key, google-services.json — and they are all valid JSON, so the
 * useful message is which keys this one is missing rather than that it failed.
 *
 * **One class for the service-account key, the client config and the mismatch
 * between them**, and deliberately: the exception hierarchy here is shaped by
 * what the caller should do, and the answer to all three is the same — put the
 * message on the field and let the admin fetch a different file. Splitting them
 * would produce three catch blocks that do one thing.
 */
final class InvalidFirebaseCredentialsException extends \RuntimeException
{
}
