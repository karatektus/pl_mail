<?php

declare(strict_types=1);

namespace App\Jmap\Method\Keys;

use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\JmapContext;
use App\Service\Crypto\KeyringView;

/**
 * "Keyring/get" — plMail extension, `urn:plmail:params:jmap:keys`.
 *
 * The person's keyring as the browser keeps it, for a native client to open
 * with what it holds: the user keys wrapped under the key derived from the
 * keyring's root — `root` says whether that is the password or an encryption
 * passphrase, and `rootKdf` how to stretch it, for a client that is given the
 * secret once (a passphrase-rooted keyring is one a single sign-on account
 * set up, and the app must ask for the passphrase, never the password),
 * and the address keys with their tokens wrapped under a user key. Nothing in
 * it is readable here; the constants to open it are in the Session's
 * capability object. One object, id `singleton`, like the browser's GET
 * /keyring — minus the recovery copies, which only a browser or the phrase
 * itself has any use for.
 */
final class KeyringGetMethod implements JmapMethod
{
    use KeysMethodSupport;

    public function __construct(
        private readonly KeyringView $view,
    ) {
    }

    public function name(): string
    {
        return 'Keyring/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $this->refuseAccountId($arguments, 'Keyring');

        $keyring = $this->view->build($context->user);
        unset($keyring['copies']);

        return $this->getResponse([['id' => 'singleton'] + $keyring], $this->requestedIds($arguments['ids'] ?? null, $context));
    }
}
