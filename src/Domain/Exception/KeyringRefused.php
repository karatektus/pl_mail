<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * A browser asked the keyring to store something it will not store.
 *
 * Always a fault on the browser's side — a bug, a stale page, a hand-made
 * request — and never something the person can fix by typing differently, so
 * the message is for the console and the log, and the page says only that
 * nothing was saved. What matters is that nothing WAS saved: a keyring
 * half-written is a keyring the next page load cannot open.
 */
final class KeyringRefused extends RuntimeException
{
}
