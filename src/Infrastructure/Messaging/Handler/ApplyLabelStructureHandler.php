<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Account;
use App\Entity\Label;
use App\Infrastructure\Messaging\Message\ApplyLabelStructureMessage;
use App\Repository\AccountRepository;
use App\Repository\LabelRepository;
use App\Service\Graph\GraphLabelPolicy;
use App\Service\Label\LabelResolver;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GraphApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
    public function __construct(
        private AccountRepository      $accountRepository,
        private LabelRepository        $labelRepository,
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

        // Re-checked here, not just at dispatch: the user may have switched the
        // toggle off while this job sat in the queue.
        if (false === $account->isLabelSyncEnabled()) {
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

        $created = $this->gmailApiClient->createLabel($account, $message->fullName);
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

        if (ApplyLabelStructureMessage::ACTION_RENAME === $message->action && null !== $message->remoteId) {
            if (true === $asFolder) {
                $this->graphApiClient->patchFolder($account, $message->remoteId, $this->leafOf($message->fullName));

                return;
            }

            $this->graphApiClient->patchMasterCategory($account, $message->remoteId, $message->fullName);

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

        $created = $this->graphApiClient->createMasterCategory($account, $message->fullName);
        $this->storeRemoteId($account, $message->labelId, (string) ($created['id'] ?? ''), false);
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
