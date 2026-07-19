<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\Enum\LabelRole;
use App\Entity\Account;
use App\Entity\Label;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Central find-or-create for labels. Both sync layers use this:
 *   - MailboxSyncer maps IMAP folders onto labels (system role or nested
 *     custom chain derived from the folder path).
 *   - GmailLabelSyncer maps Gmail API labels onto the same model, splitting
 *     Gmail's "Work/Invoices" naming into a parent chain.
 *
 * Name uniqueness per (account, parent) is enforced here, not in the DB.
 *
 * Caches entity IDs (never entities) per account so long-running handlers
 * survive em->clear() / flush() cycles.
 */
final class LabelResolver
{
    /** @var array<int, array<string, int>> accountId → role-value → labelId */
    private array $roleIdCache = [];

    /** @var array<int, array<string, int>> accountId → fullName → labelId */
    private array $pathIdCache = [];

    public function __construct(
        private readonly LabelRepository        $labelRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function systemLabel(LabelRole $role, Account $account): Label
    {
        $accountId = (int) $account->getId();
        $cachedId  = $this->roleIdCache[$accountId][$role->value] ?? null;

        if (null !== $cachedId) {
            $label = $this->em->find(Label::class, $cachedId);

            if (null !== $label) {
                return $label;
            }
        }

        $label = $this->labelRepository->findOneByRoleForAccount($role, $account);

        if (null === $label) {
            $label = new Label()
                ->setAccount($account)
                ->setName($role->displayName())
                ->setRole($role)
                ->setSortOrder($role->sortOrder())
                ->setIsVisible($role->isVisible());

            $this->em->persist($label);
            $this->em->flush();
        }

        $this->roleIdCache[$accountId][$role->value] = (int) $label->id;

        return $label;
    }

    /**
     * Find-or-create a nested custom label chain and return the leaf.
     *
     * @param list<string> $segments  e.g. ['Work', 'Invoices']
     */
    public function customChain(array $segments, Account $account): ?Label
    {
        $segments = array_values(array_filter($segments, function (string $segment): bool {
            return '' !== trim($segment);
        }));

        if (count($segments) === 0) {
            return null;
        }

        $accountId = (int) $account->getId();
        $fullName  = implode('/', $segments);
        $cachedId  = $this->pathIdCache[$accountId][$fullName] ?? null;

        if (null !== $cachedId) {
            $label = $this->em->find(Label::class, $cachedId);

            if (null !== $label) {
                return $label;
            }
        }

        $parent = null;
        $label  = null;

        foreach ($segments as $segment) {
            $label = $this->labelRepository->findOneChildByName($account, $parent, $segment);

            if (null === $label) {
                $label = new Label()
                    ->setAccount($account)
                    ->setParent($parent)
                    ->setName($segment);

                $this->em->persist($label);
                $this->em->flush();
            }

            $parent = $label;
        }

        $this->pathIdCache[$accountId][$fullName] = (int) $label->id;

        return $label;
    }

    /**
     * Split an IMAP folder full path into label segments, honouring the
     * account delimiter and stripping a leading INBOX namespace segment
     * (Courier/Dovecot-style "INBOX.Work.Invoices").
     *
     * @return list<string>
     */
    public function segmentsFromImapPath(string $fullPath, ?string $delimiter): array
    {
        if (null === $delimiter || '' === $delimiter) {
            $delimiter = '/';
        }

        $segments = explode($delimiter, $fullPath);
        $segments = array_values(array_filter($segments, function (string $segment): bool {
            return '' !== trim($segment);
        }));

        if (count($segments) > 1 && 'INBOX' === strtoupper($segments[0])) {
            array_shift($segments);
        }

        return $segments;
    }
}
