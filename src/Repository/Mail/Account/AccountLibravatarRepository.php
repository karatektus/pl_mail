<?php

declare(strict_types=1);

namespace App\Repository\Mail\Account;

use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The addresses and domains this server answers Libravatar requests for
 * (#642).
 *
 * @extends ServiceEntityRepository<Account>
 */
class AccountLibravatarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Every address of every user who publishes their picture and has one:
     * each account's own address and every alias that counts as theirs.
     * Both the account's address and its sign-in name count, each only when
     * it is an address, as Account::$ownedAddresses falls back to both —
     * the address column holds a display name on some accounts.
     * Lower-cased, as LibravatarDirectory hashes them.
     *
     * Raw DBAL for the jsonb settings bag, which DQL cannot look into, and
     * the UNION of the two kinds of address. The alias statuses are passed in
     * from the enum's own rule (EmailAliasStatus::countsForOwnership()), so
     * "an address of theirs" means here what it means for reply matching.
     *
     * @return list<array{userId: int, address: string}>
     */
    public function publishedAddresses(): array
    {
        $owning = array_values(array_map(
            static fn (EmailAliasStatus $status): string => $status->value,
            array_filter(EmailAliasStatus::cases(), static fn (EmailAliasStatus $status): bool => $status->countsForOwnership()),
        ));

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT u.id AS user_id, LOWER(TRIM(own.address)) AS address
                  FROM "user" u
                  JOIN account a ON a.usr_id = u.id
                 CROSS JOIN LATERAL (VALUES (a.email), (a.username)) AS own(address)
                 WHERE u.avatar IS NOT NULL
                   AND u.settings -> :key = 'true'::jsonb
                   AND own.address LIKE '%@%'
                UNION
                SELECT u.id AS user_id, LOWER(TRIM(ea.address)) AS address
                  FROM "user" u
                  JOIN account a ON a.usr_id = u.id
                  JOIN email_alias ea ON ea.account_id = a.id
                 WHERE u.avatar IS NOT NULL
                   AND u.settings -> :key = 'true'::jsonb
                   AND ea.status IN (:owning)
                SQL,
            ['key' => User::SETTING_LIBRAVATAR_PUBLISH, 'owning' => $owning],
            ['owning' => ArrayParameterType::STRING],
        );

        $addresses = [];

        foreach ($rows as $row) {
            if ('' !== (string) $row['address']) {
                $addresses[] = ['userId' => (int) $row['user_id'], 'address' => (string) $row['address']];
            }
        }

        return $addresses;
    }

    /**
     * Every domain any user here has an address at, for the admin card that
     * says which SRV records to publish. All users, not only those who
     * opted in: the record is the administrator's to add before anybody
     * can usefully opt in.
     *
     * @return list<string> lower-cased, sorted
     */
    public function addressDomains(): array
    {
        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT domain FROM (
                    SELECT LOWER(SPLIT_PART(TRIM(own.address), '@', 2)) AS domain
                      FROM account
                     CROSS JOIN LATERAL (VALUES (email), (username)) AS own(address)
                     WHERE own.address LIKE '%@%'
                    UNION
                    SELECT LOWER(SPLIT_PART(TRIM(address), '@', 2)) AS domain FROM email_alias
                ) domains
                 WHERE domain <> ''
                 ORDER BY domain
                SQL,
        );
    }
}
