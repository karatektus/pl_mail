<?php

declare(strict_types=1);

namespace App\Jmap\Method\Keys;

use App\Entity\Crypto\ContactKey;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Crypto\ContactKeyRepository;
use DateTimeInterface;

/**
 * "ContactKey/get" — plMail extension, `urn:plmail:params:jmap:keys`.
 *
 * The public keys plMail holds for the people the person writes with, as
 * Settings → Encryption lists them: the one in use for each address
 * (`status: current`) and any changed key still waiting to be accepted, with
 * the per-recipient preferences. Read-only; keys are added by mail that
 * brings them, by WKD or a public key server (ContactKey/lookup) or in the web settings.
 * `originHost` names the key server for a key whose `origin` is `keyserver`,
 * and is null for every other origin.
 */
final class ContactKeyGetMethod implements JmapMethod
{
    use KeysMethodSupport;

    public function __construct(
        private readonly ContactKeyRepository $contactKeys,
    ) {
    }

    public function name(): string
    {
        return 'ContactKey/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $this->refuseAccountId($arguments, 'ContactKey');

        $objects = array_map(static fn (ContactKey $key): array => [
            'id'          => (string) $key->id,
            'email'       => $key->email,
            'fingerprint' => $key->fingerprint,
            'publicKey'   => $key->publicKey,
            'origin'      => $key->origin->value,
            'originHost'  => $key->originHost,
            'status'      => $key->status->value,
            'pinned'      => $key->pinned,
            'expiresAt'   => $key->expiresAt?->format(DateTimeInterface::ATOM),
            'firstSeenAt' => $key->firstSeenAt->format(DateTimeInterface::ATOM),
            'lastSeenAt'  => $key->lastSeenAt->format(DateTimeInterface::ATOM),
            'encrypt'     => $key->encrypt->value,
            'sign'        => $key->sign->value,
            'scheme'      => $key->scheme->value,
        ], $this->contactKeys->findForUser($context->user));

        return $this->getResponse($objects, $this->requestedIds($arguments['ids'] ?? null, $context));
    }
}
