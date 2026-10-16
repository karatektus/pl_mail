<?php

declare(strict_types=1);

namespace App\Jmap\Method\Keys;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Crypto\RecipientKeys;

/**
 * "ContactKey/lookup" — plMail extension, `urn:plmail:params:jmap:keys`.
 *
 * What a composer needs to know about the people a message is going to — the
 * same answer the web composer gets (RecipientKeys): for each address the key
 * to encrypt to, whether a changed key is waiting, and the per-recipient
 * preferences; with `lookup: true`, an address without a key is looked up
 * first — its domain's WKD, then the public key servers the installation and
 * the person allow — through the server, so every client behaves alike. Each
 * key carries `origin` and `originHost`, as ContactKey/get has them.
 *
 * With `accountId` (and optionally `from`, the address it sends from), also
 * the sender's own key and whether that account keeps decrypted mail.
 *
 * Arguments: emails (list, at most RecipientKeys::MAX_RECIPIENTS), lookup
 * (bool, default false), accountId (optional), from (optional).
 */
final class ContactKeyLookupMethod implements JmapMethod
{
    public function __construct(
        private readonly RecipientKeys   $recipientKeys,
        private readonly AccountResolver $accounts,
    ) {
    }

    public function name(): string
    {
        return 'ContactKey/lookup';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $emails = $arguments['emails'] ?? null;

        if (false === is_array($emails)) {
            throw new MethodException('invalidArguments', '"emails" must be a list of addresses.');
        }

        $sender = null;

        if (null !== ($arguments['accountId'] ?? null)) {
            $account = $this->accounts->resolve($context->user, $arguments['accountId']);
            $from    = is_string($arguments['from'] ?? null) && '' !== $arguments['from'] ? $arguments['from'] : (string) ($account->displayAddress ?? $account->email);
            $sender  = $this->recipientKeys->sender($context->user, $account, $from);
        }

        return [
            'recipients' => $this->recipientKeys->describe($context->user, array_values($emails), true === ($arguments['lookup'] ?? false)),
            'sender'     => $sender,
        ];
    }
}
