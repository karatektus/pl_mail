<?php

declare(strict_types=1);

namespace App\Jmap\Method\Keys;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Entity\Mail\Message;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Crypto\DecryptedMailPolicy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "DecryptedMail/pending" — plMail extension, `urn:plmail:params:jmap:keys`.
 *
 * The account's encrypted mail still without its decrypted body, newest
 * first — what a client that holds the keys works through when the account
 * keeps decrypted mail (`keepsDecryptedMail` in the account's capability).
 * Empty for any other account. Each id is an Email id: fetch its `blobId`,
 * decrypt, upload the inner MIME message and hand it over with
 * DecryptedMail/store.
 *
 * Arguments: accountId, limit (default 25, at most 100).
 */
final class DecryptedMailPendingMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver        $accounts,
        private readonly DecryptedMailPolicy    $policy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'DecryptedMail/pending';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);
        $limit   = $arguments['limit'] ?? 25;

        if (false === is_int($limit) || $limit < 1 || $limit > 100) {
            throw new MethodException('invalidArguments', '"limit" must be a number from 1 to 100.');
        }

        $ids = [];

        if (true === $this->policy->keeps($account)) {
            $ids = array_map(static fn (array $row): string => (string) $row['id'], $this->entityManager->createQuery(
                'SELECT m.id FROM ' . Message::class . ' m
                  WHERE m.account = :account AND m.crypto IN (:crypto)
                    AND m.plaintextSource = :none AND m.sealedDraft = false
                  ORDER BY m.receivedAt DESC',
            )
                ->setParameter('account', $account)
                ->setParameter('crypto', [MessageCrypto::PgpEncrypted, MessageCrypto::PgpInlineEncrypted, MessageCrypto::SmimeEncrypted])
                ->setParameter('none', PlaintextSource::None)
                ->setMaxResults($limit)
                ->getArrayResult());
        }

        return ['accountId' => (string) $account->id, 'ids' => $ids];
    }
}
