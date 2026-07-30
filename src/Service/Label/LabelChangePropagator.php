<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ApplyGmailLabelsMessage;
use App\Infrastructure\Messaging\Message\ApplyGraphChangesMessage;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Graph\GraphLabelPolicy;
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
        private LabelRepository     $labelRepository,
        private GraphLabelPolicy    $labelPolicy,
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
        $this->dispatchGraph($messages);
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
        $this->dispatchGraph($messages);
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
        $this->dispatchGraph($messages, fn (Account $account): ?int => $this->graphDestinationLabelId($account, LabelRole::Archive));
    }

    /**
     * @param iterable<Message> $messages
     */
    public function trash(iterable $messages): void
    {
        $this->dispatchFlags($messages, 'trash');
        $this->dispatchGmail($messages, ['add' => ['TRASH'], 'remove' => ['INBOX']]);
        $this->dispatchGraph($messages, fn (Account $account): ?int => $this->graphDestinationLabelId($account, LabelRole::Trash));
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
        $this->dispatchGraph($messages, fn (Account $account): ?int => $this->graphDestinationLabelId($account, LabelRole::Trash));
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

        // Graph: a folder-backed label is a move into that folder; a plain
        // (category) label is derived from DB state by the handler. Folder
        // backing is per-account (it lives on the binding), so the decision
        // is made once per account group rather than once per call.
        $this->dispatchGraph($messages, function (Account $account) use ($label): ?int {
            if (true === $this->labelPolicy->pushesAsFolder($label, $account)) {
                return $label->id;
            }

            return null;
        });
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
        $this->dispatchGraph($messages);

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
    private function resolveDestinationMailbox(Message $message): ?\App\Entity\Mail\Mailbox
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
            // The binding for this account is the folder link — no query.
            $mailbox = $candidate->bindingFor($account)?->mailbox;

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

            if (false === $account->isGmail()) {
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
    /**
     * @param iterable<Message>        $messages
     * @param (callable(Account): ?int)|null $moveToLabelResolver  per-account
     *        destination-label resolver, or null for a state-only push
     */
    private function dispatchGraph(iterable $messages, ?callable $moveToLabelResolver = null): void
    {
        /** @var array<int, int[]> $byAccount */
        $byAccount = [];
        /** @var array<int, Account> $accounts */
        $accounts = [];

        foreach ($messages as $message) {
            $graphId = $message->getGraphId();

            if (null === $graphId || '' === $graphId) {
                continue;
            }

            $account = $this->accountOf($message);

            if (false === $account->isMicrosoft()) {
                continue;
            }

            $accountId               = (int) $account->getId();
            $byAccount[$accountId][] = (int) $message->getId();
            $accounts[$accountId]    = $account;
        }

        foreach ($byAccount as $accountId => $messageIds) {
            $moveToLabel = null !== $moveToLabelResolver
                ? $moveToLabelResolver($accounts[$accountId])
                : null;

            $this->bus->dispatch(new ApplyGraphChangesMessage($accountId, $messageIds, $moveToLabel));
        }
    }

    private function graphDestinationLabelId(Account $account, LabelRole $role): ?int
    {
        $label = $this->labelRepository->findOneByRoleForUser($role, $account->getUsr());

        if (null === $label) {
            $this->logger->warning('LabelChangePropagator: no destination label for Graph move', [
                'accountId' => $account->getId(),
                'role'      => $role->value,
            ]);

            return null;
        }

        return $label->id;
    }
    /**
     * Message::$account is non-null, so this is direct now. It used to fall
     * back to an attached label's account, which stopped being meaningful
     * once labels became user-scoped — and was never needed, since every
     * message has carried its own account all along.
     */
    private function accountOf(Message $message): Account
    {
        return $message->getAccount();
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
