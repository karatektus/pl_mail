<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Account;
use App\Entity\Label;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps a Graph message's parentFolderId onto a local Label.
 *
 * The counterpart to GmailLabelResolver, with one structural difference: a
 * Gmail message carries N labelIds and gets all of them, whereas an Exchange
 * message lives in exactly one folder and therefore gets exactly one location
 * label. Graph `categories` are the many-to-many axis and are resolved
 * separately, by name, in resolveCategories().
 *
 * Caches label IDs (never entities) per account so batch handlers survive
 * flush()/clear() cycles without detached entity errors.
 */
final class GraphFolderResolver
{
    /** @var array<int, array<string, int|null>> accountId → graphFolderId → labelId|null */
    private array $folderIdCache = [];

    /** @var array<int, array<string, int|null>> accountId → category name → labelId|null */
    private array $categoryIdCache = [];

    public function __construct(
        private readonly LabelRepository        $labelRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    public function resolveFolder(string $graphFolderId, Account $account): ?Label
    {
        if ('' === $graphFolderId) {
            return null;
        }

        $accountId = (int) $account->getId();

        if (true === array_key_exists($graphFolderId, $this->folderIdCache[$accountId] ?? [])) {
            $cachedId = $this->folderIdCache[$accountId][$graphFolderId];

            if (null === $cachedId) {
                return null;
            }

            return $this->em->find(Label::class, $cachedId);
        }

        $label = $this->labelRepository->findOneByGraphFolderId($graphFolderId, $account);

        if (null === $label) {
            $this->logger->warning('GraphFolderResolver: unknown folder id, will map on next folder sync', [
                'accountId'     => $accountId,
                'graphFolderId' => $graphFolderId,
            ]);

            $this->folderIdCache[$accountId][$graphFolderId] = null;

            return null;
        }

        $this->folderIdCache[$accountId][$graphFolderId] = (int) $label->id;

        return $label;
    }

    /**
     * Graph categories are free-text colour tags with no server-side id — the
     * name IS the identity. They map onto custom labels by name; unknown names
     * are skipped rather than created, so a category only becomes a label once
     * the user has a matching label.
     *
     * @param list<string> $categories
     * @return list<Label>
     */
    public function resolveCategories(array $categories, Account $account): array
    {
        $accountId = (int) $account->getId();
        $labels    = [];

        foreach ($categories as $category) {
            $name = trim($category);

            if ('' === $name) {
                continue;
            }

            if (true === array_key_exists($name, $this->categoryIdCache[$accountId] ?? [])) {
                $cachedId = $this->categoryIdCache[$accountId][$name];

                if (null === $cachedId) {
                    continue;
                }

                $label = $this->em->find(Label::class, $cachedId);

                if (null !== $label) {
                    $labels[] = $label;
                }

                continue;
            }

            $label = $this->labelRepository->findOneByFullNameForAccount($name, $account);

            if (null === $label) {
                $this->categoryIdCache[$accountId][$name] = null;
                continue;
            }

            $this->categoryIdCache[$accountId][$name] = (int) $label->id;
            $labels[] = $label;
        }

        return $labels;
    }
}
