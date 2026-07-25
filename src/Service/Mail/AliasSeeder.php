<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\EmailAliasSource;
use App\Domain\Enum\EmailAliasStatus;
use App\Entity\Account;
use App\Entity\EmailAlias;
use App\Repository\EmailAliasRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Populates an account's alias set from what the provider reports. Every
 * account type participates: Microsoft via the Graph profile emails, Gmail via
 * the send-as settings, and plain-IMAP/password accounts via their own address.
 *
 * Rules that keep re-seeds safe:
 *  - Discovered addresses arrive as Active; a provider-internal canonical
 *    address (the immutable outlook_… backing address) is tagged System.
 *  - An address already present is never re-statused — a refresh won't undo a
 *    user's Primary/Inactive choice; it only fills in a missing display name.
 *  - After seeding, if there is still no Primary, one is chosen (the provider's
 *    preferred/default address if known, else the account's own email, else the
 *    first non-System alias) so the display address is never null.
 */
final class AliasSeeder
{
    public function __construct(
        private readonly GraphApiClient         $graphApiClient,
        private readonly GmailApiClient         $gmailApiClient,
        private readonly EmailAliasRepository   $aliasRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function seed(Account $account): void
    {
        [$entries, $preferred] = $this->discover($account);

        foreach ($entries as $entry) {
            $existing = $this->aliasRepository->findOneByAccountAndAddress($account, $entry['address']);

            if (null !== $existing) {
                if (null === $existing->displayName && null !== $entry['displayName']) {
                    $existing->displayName = $entry['displayName'];
                }

                continue;
            }

            $alias = new EmailAlias(
                account: $account,
                address: $entry['address'],
                source: true === $entry['isSystem'] ? EmailAliasSource::System : EmailAliasSource::Provider,
                status: EmailAliasStatus::Active,
                displayName: $entry['displayName'],
            );

            $account->addAlias($alias);
            $this->em->persist($alias);
        }

        $this->ensurePrimary($account, $preferred);
        $this->em->flush();
    }

    /**
     * @return array{0: list<array{address: string, displayName: ?string, isSystem: bool}>, 1: ?string}
     */
    private function discover(Account $account): array
    {
        if (true === $account->isMicrosoft()) {
            return $this->fromGraph($account);
        }

        if (true === $account->isGmail()) {
            return $this->fromGmail($account);
        }

        return $this->fromImap($account);
    }

    /**
     * @return array{0: list<array{address: string, displayName: ?string, isSystem: bool}>, 1: ?string}
     */
    private function fromGraph(Account $account): array
    {
        $entries = [];

        foreach ($this->graphApiClient->listProfileEmails($account) as $email) {
            $entries[] = [
                'address'     => $email['address'],
                'displayName' => $email['displayName'],
                'isSystem'    => $this->isCanonicalOutlookAddress($email['address']),
            ];
        }

        return [$entries, null !== $account->getEmail() ? strtolower($account->getEmail()) : null];
    }

    /**
     * @return array{0: list<array{address: string, displayName: ?string, isSystem: bool}>, 1: ?string}
     */
    private function fromGmail(Account $account): array
    {
        $entries   = [];
        $preferred = null;

        foreach ($this->gmailApiClient->listSendAs($account) as $sendAs) {
            $entries[] = [
                'address'     => $sendAs['address'],
                'displayName' => $sendAs['displayName'],
                'isSystem'    => false,
            ];

            if (true === $sendAs['isDefault']) {
                $preferred = $sendAs['address'];
            }
        }

        if (null === $preferred && null !== $account->getEmail()) {
            $preferred = strtolower($account->getEmail());
        }

        return [$entries, $preferred];
    }

    /**
     * Plain-IMAP/password: the account's own login address is its one alias.
     *
     * @return array{0: list<array{address: string, displayName: ?string, isSystem: bool}>, 1: ?string}
     */
    private function fromImap(Account $account): array
    {
        $address = $account->getEmail() ?? $account->getUsername();

        if (null === $address || '' === $address) {
            return [[], null];
        }

        $address = strtolower($address);

        return [
            [[
                'address'     => $address,
                'displayName' => $account->getName(),
                'isSystem'    => false,
            ]],
            $address,
        ];
    }

    private function ensurePrimary(Account $account, ?string $preferred): void
    {
        if (null !== $account->getPrimaryAlias()) {
            return;
        }

        $preferred      = null !== $preferred ? EmailAlias::normalize($preferred) : null;
        $firstNonSystem = null;
        $first          = null;

        foreach ($account->getAliases() as $alias) {
            $first ??= $alias;

            if (null === $firstNonSystem && EmailAliasSource::System !== $alias->source) {
                $firstNonSystem = $alias;
            }

            if (null !== $preferred && $alias->address === $preferred) {
                $alias->status = EmailAliasStatus::Primary;

                return;
            }
        }

        $chosen = $firstNonSystem ?? $first;

        if (null !== $chosen) {
            $chosen->status = EmailAliasStatus::Primary;
        }
    }

    /** The immutable outlook_<hex>@outlook.com backing address. */
    private function isCanonicalOutlookAddress(string $address): bool
    {
        return 1 === preg_match('/^outlook_[0-9a-f]+@outlook\.com$/i', $address);
    }
}
