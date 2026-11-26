<?php

declare(strict_types=1);

namespace App\Jmap\Method\Keys;

use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Domain\Exception\ContactKeyRefused;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Crypto\RecipientPolicies;

/**
 * "RecipientPolicy/set" — plMail extension, `urn:plmail:params:jmap:keys`.
 *
 * Whether mail to one address is encrypted from now on (#703): what the web
 * composer's lock popover sets, for an app's. The same service writes it
 * (RecipientPolicies), so ContactKey/lookup answers with it afterwards in
 * `encrypt`, for a contact and for one of the person's own addresses alike.
 *
 * Not a standard /set: there is no object with an id to create or destroy,
 * only one value per address, and a client that has the address in hand
 * should not have to find a ContactKey id first — own addresses have none.
 *
 * Arguments: email, encrypt (`auto`, `always` or `never`). Answers
 * `{email, encrypt}`. `notFound` when plMail has no key for the address.
 */
final class RecipientPolicySetMethod implements JmapMethod
{
    use KeysMethodSupport;

    public function __construct(
        private readonly RecipientPolicies $policies,
    ) {
    }

    public function name(): string
    {
        return 'RecipientPolicy/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $this->refuseAccountId($arguments, 'RecipientPolicy');

        $email  = is_string($arguments['email'] ?? null) ? strtolower(trim($arguments['email'])) : '';
        $policy = RecipientPolicy::tryFrom(is_string($arguments['encrypt'] ?? null) ? $arguments['encrypt'] : '');

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MethodException('invalidArguments', '"email" must be an address.');
        }

        if (null === $policy) {
            throw new MethodException('invalidArguments', '"encrypt" must be "auto", "always" or "never".');
        }

        try {
            $stored = $this->policies->setEncrypt($context->user, $email, $policy);
        } catch (ContactKeyRefused) {
            throw new MethodException('notFound', 'plMail has no key for this address, so mail to it goes out unencrypted whatever is set.');
        }

        return ['email' => $email, 'encrypt' => $stored->value];
    }
}
