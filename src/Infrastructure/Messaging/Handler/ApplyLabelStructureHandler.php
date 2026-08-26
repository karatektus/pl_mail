<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Infrastructure\Messaging\Message\ApplyGraphChangesMessage;
use App\Infrastructure\Messaging\Message\ApplyLabelStructureMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Label\LabelRepository;
use App\Domain\Enum\Mail\LabelColor;
use App\Service\Gmail\GmailLabelColorMapper;
use App\Service\Graph\GraphCategoryColorMapper;
use App\Service\Graph\GraphLabelPolicy;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GraphApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Mirrors a label create/rename/delete onto Gmail or Microsoft.
 *
 * Runs after the local write has already committed, so a provider failure
 * never rolls back what the user saw succeed. The remote id is written back
 * onto the Label so the inbound sync recognises the label as already-known
 * instead of importing it a second time — that write-back is the whole point
 * of doing this asynchronously rather than fire-and-forget.
 */
#[AsMessageHandler]
final readonly class ApplyLabelStructureHandler
{
    /**
     * How many message ids go into one re-tag job.
     *
     * The same 200 the rest of this application walks a mailbox in. Small
     * enough that a failure retries a bounded amount of work, large enough that
     * a label on ten thousand messages is fifty jobs rather than ten thousand.
     */
    private const int RETAG_BATCH = 200;

    public function __construct(
        private AccountRepository      $accountRepository,
        private LabelRepository        $labelRepository,
        private MessageRepository      $messageRepository,
        private MessageBusInterface    $bus,
        private GraphCategoryColorMapper $colorMapper,
        private GmailLabelColorMapper  $gmailColorMapper,
        private GmailApiClient         $gmailApiClient,
        private GraphApiClient         $graphApiClient,
        private GraphLabelPolicy       $labelPolicy,
        private LabelResolver          $labelResolver,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    public function __invoke(ApplyLabelStructureMessage $message): void
    {
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            return;
        }

        // Re-checked here, not just at dispatch: an account can change provider
        // shape — or stop being one plMail can push structure to — while this
        // job sits in the queue.
        if (false === $account->supportsLabelSync()) {
            return;
        }

        try {
            if (true === $account->isGmail()) {
                $this->applyGmail($account, $message);

                return;
            }

            if (true === $account->isMicrosoft()) {
                $this->applyGraph($account, $message);
            }
        } catch (\Throwable $e) {
            // Let messenger retry: provider throttling and transient 5xx are
            // routine, and the local state is already correct either way.
            $this->logger->error('ApplyLabelStructure: provider call failed', [
                'accountId' => $message->accountId,
                'action'    => $message->action,
                'label'     => $message->fullName,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    private function applyGmail(Account $account, ApplyLabelStructureMessage $message): void
    {
        if (ApplyLabelStructureMessage::ACTION_DELETE === $message->action) {
            if (null !== $message->remoteId) {
                $this->gmailApiClient->deleteLabel($account, $message->remoteId);
            }

            return;
        }

        // Gmail carries hierarchy in the name ("Work/Invoices"), which is
        // exactly what Label::$fullName already produces.
        if (ApplyLabelStructureMessage::ACTION_RENAME === $message->action && null !== $message->remoteId) {
            $this->gmailApiClient->patchLabel($account, $message->remoteId, $message->fullName);

            return;
        }

        // The label's colour goes out with it — creation is the one direction
        // that cannot lose a Gmail colour, since there is not one yet.
        $created = $this->gmailApiClient->createLabel(
            $account,
            $message->fullName,
            $this->gmailColorMapper->toGmailColor(
                LabelColor::tryFrom((string) $this->labelRepository->find($message->labelId)?->color),
            ),
        );
        $this->storeRemoteId($account, $message->labelId, (string) ($created['id'] ?? ''), true);
    }

    private function applyGraph(Account $account, ApplyLabelStructureMessage $message): void
    {
        $label = null === $message->labelId ? null : $this->labelRepository->find($message->labelId);

        // A folder-backed label is a real mail folder; anything else is a
        // category. GraphLabelPolicy already owns that decision.
        $asFolder = null !== $label && true === $this->labelPolicy->pushesAsFolder($label, $account);

        if (ApplyLabelStructureMessage::ACTION_DELETE === $message->action) {
            $this->deleteGraph($account, $message, $asFolder);

            return;
        }

        if (ApplyLabelStructureMessage::ACTION_RENAME === $message->action) {
            $this->renameGraph($account, $message, $asFolder);

            return;
        }

        if (true === $asFolder) {
            $created = $this->graphApiClient->createFolder(
                $account,
                $this->leafOf($message->fullName),
                $message->parentRemoteId,
            );

            $this->storeRemoteId($account, $message->labelId, (string) ($created['id'] ?? ''), false);

            return;
        }

        // The label's own colour goes out with it. Creating a category is the
        // one direction that cannot lose an Outlook colour, since there is not
        // one yet — see GraphCategoryColorMapper.
        $label = $this->labelRepository->find($message->labelId);

        $created = $this->graphApiClient->createMasterCategory(
            $account,
            $message->fullName,
            $this->colorMapper->toPreset(LabelColor::tryFrom((string) $label?->color)),
        );
        $this->storeCategoryId($account, $message->labelId, (string) ($created['id'] ?? ''));
    }

    /**
     * A rename, which is the one action that used to make things worse rather
     * than merely not work.
     *
     * The old code required a remote id and, without one, fell through to the
     * create branch below it — so renaming a category that had come *from*
     * Outlook created a second master category under the new name and left the
     * first standing. The next inbound sync then read that first one back and
     * put the old label beside the new one. Nothing about that looked like an
     * error anywhere.
     *
     * Two things fix it. A rename never falls through to a create: if there is
     * nothing to address, the correct outcome is to say so and stop. And an
     * Exchange master category can be addressed by the name it had, because
     * that IS its identity there — the GUID is a convenience plMail records
     * when it can, not the only handle.
     *
     * Renaming the master category is also not the whole job: Exchange stores
     * the category on each message as a STRING, so every message carrying the
     * old one keeps carrying it. Outlook's own rename dialog asks whether to
     * re-tag the items; this does it without asking, because the alternative is
     * a mailbox where the label exists twice — once as a category and once as
     * loose text on the mail that used to have it.
     */
    private function renameGraph(Account $account, ApplyLabelStructureMessage $message, bool $asFolder): void
    {
        if (true === $asFolder) {
            if (null === $message->remoteId) {
                $this->logger->warning('ApplyLabelStructure: no folder id to rename', [
                    'accountId' => $message->accountId,
                    'label'     => $message->fullName,
                ]);

                return;
            }

            $this->graphApiClient->patchFolder($account, $message->remoteId, $this->leafOf($message->fullName));

            return;
        }

        $categoryId = $message->categoryRemoteId ?? $this->categoryIdByName($account, $message->previousFullName);

        if (null === $categoryId) {
            // Deliberately not a create. A rename that cannot find its subject
            // has lost track of something, and inventing a second category is
            // how one label became two.
            $this->logger->warning('ApplyLabelStructure: no master category to rename', [
                'accountId' => $message->accountId,
                'from'      => $message->previousFullName,
                'to'        => $message->fullName,
            ]);

            return;
        }

        $this->graphApiClient->patchMasterCategory($account, $categoryId, $message->fullName);

        // Recorded now if it was only just discovered, so the next rename does
        // not have to go looking.
        $this->storeCategoryId($account, $message->labelId, $categoryId);

        $this->retagMessages($account, $message->labelId);
    }

    /**
     * The master category whose display name is this, or null.
     *
     * A list-and-scan rather than a filtered query: Graph exposes no filter on
     * masterCategories, the collection is a couple of dozen rows for any real
     * mailbox, and this runs once per rename.
     */
    private function categoryIdByName(Account $account, ?string $displayName): ?string
    {
        $needle = mb_strtolower(trim((string) $displayName));

        if ('' === $needle) {
            return null;
        }

        foreach ($this->graphApiClient->listMasterCategories($account) as $category) {
            if (mb_strtolower(trim((string) ($category['displayName'] ?? ''))) === $needle) {
                $id = (string) ($category['id'] ?? '');

                return '' === $id ? null : $id;
            }
        }

        return null;
    }

    /**
     * Push every message that carries this label again, so the category string
     * stored on each one becomes the new name.
     *
     * ApplyGraphChangesMessage derives the whole categories array from the
     * database rather than being told a delta, so re-dispatching it for a
     * message is exactly "make Exchange agree with us about this message" —
     * which after the rename is what is needed and nothing more.
     *
     * Batched, and bounded by the messages that actually hold the label rather
     * than by the mailbox. A label on ten thousand messages really is ten
     * thousand PATCHes; that is the cost of Exchange storing a tag as loose
     * text, and the alternative is leaving the old name on all of them.
     */
    private function retagMessages(Account $account, ?int $labelId): void
    {
        if (null === $labelId) {
            return;
        }

        $afterId = 0;

        while (true) {
            $ids = $this->messageRepository->findIdsWithLabelForAccount(
                (int) $account->id,
                $labelId,
                $afterId,
                self::RETAG_BATCH,
            );

            if ([] === $ids) {
                return;
            }

            $this->bus->dispatch(new ApplyGraphChangesMessage((int) $account->id, $ids));

            $afterId = (int) end($ids);
        }
    }

    private function deleteGraph(Account $account, ApplyLabelStructureMessage $message, bool $asFolder): void
    {
        if (null === $message->remoteId) {
            return;
        }

        if (true === $asFolder) {
            // Graph deletes the messages inside a folder along with it. The
            // local delete has already detached them, but the remote copies
            // would go too — so refuse rather than destroy mail.
            $this->logger->warning('ApplyLabelStructure: refusing to delete a folder-backed label remotely', [
                'accountId' => $message->accountId,
                'label'     => $message->fullName,
            ]);

            return;
        }

        $this->graphApiClient->deleteMasterCategory($account, $message->remoteId);
    }

    /**
     * Write the provider's id back so the next inbound sync matches this label
     * instead of creating a duplicate.
     *
     * It lands on the binding, not the label: the id belongs to the
     * (label, account) pair, and the same label pushed to two Gmail accounts
     * gets two different ids.
     */
    /**
     * The master category id, onto its own column.
     *
     * Emphatically not storeRemoteId(..., gmail: false), which is where this
     * used to go: that writes graphFolderId, and GraphLabelPolicy reads
     * graphFolderId to mean "this label is an Exchange folder". A category
     * recorded there turned itself into a location on the next push.
     */
    private function storeCategoryId(Account $account, ?int $labelId, string $categoryId): void
    {
        if (null === $labelId || '' === $categoryId) {
            return;
        }

        $label = $this->labelRepository->find($labelId);

        if (null === $label) {
            return;
        }

        $this->labelResolver->binding($label, $account)->graphCategoryId = $categoryId;

        $this->em->flush();
    }

    private function storeRemoteId(Account $account, ?int $labelId, string $remoteId, bool $gmail): void
    {
        if (null === $labelId || '' === $remoteId) {
            return;
        }

        $label = $this->labelRepository->find($labelId);

        if (null === $label) {
            return;
        }

        $binding = $this->labelResolver->binding($label, $account);

        if (true === $gmail) {
            $binding->gmailLabelId = $remoteId;
        } else {
            $binding->graphFolderId = $remoteId;
        }


        $this->em->flush();
    }

    /**
     * Graph folders nest structurally, so only the leaf name is sent — unlike
     * Gmail, where the full path IS the name.
     */
    private function leafOf(string $fullName): string
    {
        $segments = explode('/', $fullName);

        return (string) end($segments);
    }
}
