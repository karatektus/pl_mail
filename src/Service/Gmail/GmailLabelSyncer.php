<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Repository\Label\LabelRepository;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GmailApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs the Gmail labels.list result into local Label rows for an account.
 * Runs before message sync so every labelId on a message payload can be
 * resolved to an existing Label.
 *
 * Mapping:
 *   - System labelIds (INBOX, SENT, DRAFT, TRASH, SPAM) → role labels.
 *   - type=user labels → custom label chains, splitting Gmail's
 *     "Work/Invoices" naming into the parent tree. The Gmail label id is
 *     stored on the LEAF of the chain (Gmail itself has no id for implicit
 *     parents unless they exist as their own label — in which case they get
 *     their own row and id from their own list entry).
 *   - STARRED / UNREAD / IMPORTANT / CHAT / CATEGORY_* are intentionally
 *     skipped: starred and read state are message columns, the rest are
 *     not modelled.
 *
 * Remote labelListVisibility is adopted ONCE, when the remote label is
 * first linked. After that, visibility belongs to the user (label settings)
 * and is never overwritten by sync.
 */
final readonly class GmailLabelSyncer
{
    private const array SYSTEM_MAP = [
        'INBOX' => LabelRole::Inbox,
        'SENT'  => LabelRole::Sent,
        'DRAFT' => LabelRole::Drafts,
        'TRASH' => LabelRole::Trash,
        'SPAM'  => LabelRole::Spam,
    ];

    public function __construct(
        private GmailApiClient         $apiClient,
        private LabelResolver          $labelResolver,
        private LabelRepository        $labelRepository,
        private GmailLabelColorMapper  $colorMapper,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    public function sync(Account $account): void
    {
        $remoteLabels = $this->apiClient->listLabels($account);

        $synced = 0;

        foreach ($remoteLabels as $remoteLabel) {
            $gmailLabelId = (string) ($remoteLabel['id'] ?? '');
            $name         = (string) ($remoteLabel['name'] ?? '');
            $type         = (string) ($remoteLabel['type'] ?? 'user');

            if ('' === $gmailLabelId || '' === $name) {
                continue;
            }

            if (true === array_key_exists($gmailLabelId, self::SYSTEM_MAP)) {
                $label   = $this->labelResolver->systemLabel(self::SYSTEM_MAP[$gmailLabelId], $account);
                $binding = $this->labelResolver->binding($label, $account);

                if ($binding->gmailLabelId !== $gmailLabelId) {
                    $binding->gmailLabelId = $gmailLabelId;
                }

                $synced++;
                continue;
            }

            if ('user' !== $type) {
                // STARRED, UNREAD, IMPORTANT, CHAT, CATEGORY_* — not modelled.
                continue;
            }

            // Was this remote label already linked before this run? Only a
            // first-time link adopts Gmail's visibility; afterwards the user
            // owns it via the label settings.
            $firstLink = null === $this->labelRepository->findOneByGmailLabelId($gmailLabelId, $account);

            $segments = explode('/', $name);
            $label    = $this->labelResolver->customChain($segments, $account);

            if (null === $label) {
                continue;
            }

            // Gmail's colour, but only onto a label that has none — the same
            // rule as the Outlook mapper. 89 colours onto 9 is lossy, so a
            // value that is read back on every sync would drift; read once and
            // then left alone, it cannot. A colour picked in plMail always
            // wins.
            if (null === $label->color) {
                $mapped = $this->colorMapper->toLabelColor(
                    $remoteLabel['color']['backgroundColor'] ?? null,
                );

                if (null !== $mapped) {
                    $label->setColor($mapped->value);
                }
            }

            $binding = $this->labelResolver->binding($label, $account);

            if ($binding->gmailLabelId !== $gmailLabelId) {
                $binding->gmailLabelId = $gmailLabelId;
            }

            if (true === $firstLink) {
                $visibility = (string) ($remoteLabel['labelListVisibility'] ?? 'labelShow');

                if ('labelHide' === $visibility && true === $label->isVisible) {
                    $label->setIsVisible(false);
                }
            }

            $synced++;
        }

        $this->em->flush();

        $this->logger->info('GmailLabelSyncer: labels synced', [
            'accountId' => $account->getId(),
            'count'     => $synced,
        ]);
    }
}
