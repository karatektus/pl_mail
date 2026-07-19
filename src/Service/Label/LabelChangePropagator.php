<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Label;
use App\Entity\Message;
use App\Message\ApplyGmailLabelsMessage;
use App\Message\ApplyImapFlagsMessage;
use App\Repository\MailboxRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Translates a semantic mail operation into provider-appropriate async
 * propagation jobs. Callers mutate the DB first (source of truth), then
 * call propagate() with the affected messages.
 *
 * Provider mapping:
 *   Gmail — every operation is a label mutation via messages.batchModify.
 *   IMAP  — star/read map to flags; archive/trash/delete map to moves as
 *           before; custom label attach is DB-only (the physical folder is
 *           untouched while the location label stays); custom label detach
 *           triggers a physical move ONLY when the detached label was the
 *           message's location label (rule: the message must live somewhere).
 *
 * Location resolution when the location label is detached, in order:
 *   1. a remaining system Trash/Spam label with a backing folder
 *   2. a remaining folder-backed custom label (last attached wins)
 *   3. no folder-backed label remains → this is an archive → 'archive' action
 *
 * IMPORTANT: for IMAP moves, callers must pass messages BEFORE flush() so
 * getMailbox() still reflects the source folder; this service captures the
 * messageId => sourceMailboxId map and optimistically re-points
 * message->mailbox to the destination for 'move'.
 */
final readonly class LabelChangePropagator
{
    public function __construct(
        private MessageBusInterface $bus,
        private MailboxRepository   $mailboxRepository,
        private LoggerInterface     $logger,
    ) {}

    /**
     * @param iterable<Message> $messages
     */
    public function star(iterable $messages, bool $starred): void
    {
        $imapAction = 'unflag';

        if (true === $starred) {
            $imapAction = 'flag';
        }

        $this->dispatchFlags($messages, $imapAction);
        $this->dispatchGmail($messages, $this->gmailStarPayload($starred));
    }

    /**
     * @param iterable<Message> $messages
     */
    public function markRead(iterable $messages, bool $read): void
    {
        $imapAction = 'unseen';

        if (true === $read) {
            $imapAction = 'seen';
        }

        $this->dispatchFlags($messages, $imapAction);
        $this->dispatchGmail($messages, $this->gmailReadPayload($read));
    }

    /**
     * Archive = remove Inbox. IMAP messages physically move to the Archive
     * folder (handler resolves the destination as before).
     *
     * @param iterable<Message> $messages
     */
    public function archive(iterable $messages): void
    {
        $this->dispatchFlags($messages, 'archive');
        $this->dispatchGmail($messages, ['add' => [], 'remove' => ['INBOX']]);
    }

    /**
     * @param iterable<Message> $messages
     */
    public function trash(iterable $messages): void
    {
        $this->dispatchFlags($messages, 'trash');
        $this->dispatchGmail($messages, ['add' => ['TRASH'], 'remove' => ['INBOX']]);
    }

    /**
     * @param iterable<Message> $messages
     */
    public function delete(iterable $messages): void
    {
        $this->dispatchFlags($messages, 'delete');
        // Gmail: permanent delete requires the full mail scope; TRASH is the
        // Gmail-native equivalent of what the UI exposes.
        $this->dispatchGmail($messages, ['add' => ['TRASH'], 'remove' => []]);
    }

    /**
     * Attach a custom label. IMAP: DB-only — the message keeps its physical
     * location as long as the location label stays attached.
     *
     * @param iterable<Message> $messages
     */
    public function attachLabel(iterable $messages, Label $label): void
    {
        $this->dispatchGmail($messages, ['add' => [(string) $label->id], 'remove' => []]);
    }

    /**
     * Detach a custom label. IMAP: physical move only when the detached
     * label was the message's location label — resolved per message.
     *
     * MUST be called after the DB label mutation but BEFORE re-pointing or
     * flushing message->mailbox; this method handles the re-point itself.
     *
     * @param iterable<Message> $messages
     */
    public function detachLabel(iterable $messages, Label $label): void
    {
        $this->dispatchGmail($messages, ['add' => [], 'remove' => [(string) $label->id]]);

        // ── IMAP location handling ────────────────────────────────────────
        /** @var array<string, array<int,int>> $moves  destinationPath → messageId => sourceMailboxId */
        $moves = [];
        /** @var array<int, Message[]> $archives  accountless bucket for rule 3 */
        $archives = [];

        foreach ($messages as $message) {
            $sourceMailbox = $message->getMailbox();

            if (null === $sourceMailbox || null === $message->getImapUid()) {
                continue;
            }

            if ($sourceMailbox->getLabel() !== $label) {
                // Not the location label — DB-only, message stays put.
                continue;
            }

            $destinationMailbox = $this->resolveDestinationMailbox($message);

            if (null === $destinationMailbox) {
                // Rule 3: nothing folder-backed remains — archive.
                $archives[] = $message;
                continue;
            }

            $moves[$destinationMailbox->getFullPath()][$message->getId()] = $sourceMailbox->getId();

            // Optimistic re-point; UID goes stale until the destination
            // folder's next sync picks the message up again.
            $message->setMailbox($destinationMailbox);
        }

        foreach ($moves as $destinationPath => $idMap) {
            $this->bus->dispatch(new ApplyImapFlagsMessage($idMap, 'move', $destinationPath));
        }

        if (count($archives) > 0) {
            $this->dispatchFlags($archives, 'archive');
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Rule 2 resolution: system Trash/Spam first, then folder-backed custom
     * labels (last attached wins).
     */
    private function resolveDestinationMailbox(Message $message): ?\App\Entity\Mailbox
    {
        $account         = $message->getMailbox()->getAccount();
        $systemCandidate = null;
        $customCandidate = null;

        foreach ($message->getLabels() as $remaining) {
            if (true === $remaining->isSystem) {
                $role = $remaining->role;

                if (null !== $role && true === in_array($role->value, ['trash', 'spam'], true)) {
                    $systemCandidate = $remaining;
                }

                continue;
            }

            $customCandidate = $remaining; // last one wins
        }

        $candidates = [];

        if (null !== $systemCandidate) {
            $candidates[] = $systemCandidate;
        }

        if (null !== $customCandidate) {
            $candidates[] = $customCandidate;
        }

        foreach ($candidates as $candidate) {
            $mailbox = $this->mailboxRepository->findOneBy([
                'account' => $account,
                'label'   => $candidate,
            ]);

            if (null !== $mailbox) {
                return $mailbox;
            }
        }

        return null;
    }

    /**
     * @param iterable<Message> $messages
     */
    private function dispatchFlags(iterable $messages, string $action): void
    {
        $idMap = [];

        foreach ($messages as $message) {
            if (null === $message->getImapUid() || null === $message->getMailbox()) {
                continue;
            }

            $idMap[$message->getId()] = $message->getMailbox()->getId();
        }

        if (count($idMap) === 0) {
            return;
        }

        $this->bus->dispatch(new ApplyImapFlagsMessage($idMap, $action));
    }

    /**
     * @param iterable<Message>                              $messages
     * @param array{add: list<string>, remove: list<string>} $payload
     */
    private function dispatchGmail(iterable $messages, array $payload): void
    {
        if (count($payload['add']) === 0 && count($payload['remove']) === 0) {
            return;
        }

        /** @var array<int, int[]> $byAccount accountId → messageIds */
        $byAccount = [];

        foreach ($messages as $message) {
            $gmailId = $message->getGmailId();

            if (null === $gmailId || '' === $gmailId) {
                continue;
            }

            $account = $this->accountOf($message);

            if (null === $account || false === $account->isGmail()) {
                continue;
            }

            $byAccount[(int) $account->getId()][] = (int) $message->getId();
        }

        foreach ($byAccount as $accountId => $messageIds) {
            $this->bus->dispatch(new ApplyGmailLabelsMessage(
                $accountId,
                $messageIds,
                $payload['add'],
                $payload['remove'],
            ));
        }
    }

    private function accountOf(Message $message): ?\App\Entity\Account
    {
        $mailbox = $message->getMailbox();

        if (null !== $mailbox) {
            return $mailbox->getAccount();
        }

        // Gmail-API messages have no mailbox — resolve via any attached label.
        foreach ($message->getLabels() as $label) {
            if (null !== $label->account) {
                return $label->account;
            }
        }

        $this->logger->warning('LabelChangePropagator: message has no resolvable account', [
            'messageId' => $message->getId(),
        ]);

        return null;
    }

    /**
     * @return array{add: list<string>, remove: list<string>}
     */
    private function gmailStarPayload(bool $starred): array
    {
        if (true === $starred) {
            return ['add' => ['STARRED'], 'remove' => []];
        }

        return ['add' => [], 'remove' => ['STARRED']];
    }

    /**
     * @return array{add: list<string>, remove: list<string>}
     */
    private function gmailReadPayload(bool $read): array
    {
        if (true === $read) {
            return ['add' => [], 'remove' => ['UNREAD']];
        }

        return ['add' => ['UNREAD'], 'remove' => []];
    }
}
