<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Rule\MailRule;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\UploadAttachmentsMessage;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Label\LabelRepository;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Carries out a matched rule's actions on one message.
 *
 * The ordering contract from LabelChangePropagator is the thing to respect
 * here: it does NOT touch the database, it only fans out provider-push jobs.
 * So every action mutates the entity first and calls the propagator second,
 * exactly as ThreadStatusController does for the same operations in the UI.
 *
 * Thread labels are re-synced once at the end rather than per action. The
 * engine runs after MessageThreader has already copied the message's labels
 * onto its thread, so without this the thread would keep the pre-rule set.
 */
final readonly class RuleActionExecutor
{
    public const string APPLY_LABEL = 'applyLabel';
    public const string REMOVE_LABEL = 'removeLabel';
    public const string MARK_READ = 'markRead';
    public const string STAR = 'star';
    public const string ARCHIVE = 'archive';
    public const string TRASH = 'trash';
    public const string MARK_SPAM = 'markSpam';
    public const string SAVE_TO_INTEGRATION = 'saveToIntegration';

    public const array TYPES = [
        self::APPLY_LABEL,
        self::REMOVE_LABEL,
        self::MARK_READ,
        self::STAR,
        self::ARCHIVE,
        self::TRASH,
        self::MARK_SPAM,
        self::SAVE_TO_INTEGRATION,
    ];

    public function __construct(
        private LabelRepository         $labelRepository,
        private LabelResolver           $labelResolver,
        private LabelChangePropagator   $propagator,
        private ThreadLabelSynchronizer $threadSynchronizer,
        private IntegrationRepository   $integrationRepository,
        private MessageBusInterface     $bus,
        private LoggerInterface         $logger,
    ) {}

    public function execute(MailRule $rule, Message $message): void
    {
        $touchedLabels = false;

        foreach ($rule->actions as $action) {
            if (false === is_array($action)) {
                continue;
            }

            $type = (string) ($action['type'] ?? '');

            $touchedLabels = $this->one($type, $action, $message, $rule) || $touchedLabels;
        }

        $thread = $message->getThread();

        if (true === $touchedLabels && null !== $thread) {
            $this->threadSynchronizer->sync($thread);
        }
    }

    /**
     * @param array<string,mixed> $action
     *
     * @return bool whether the message's label set changed
     */
    private function one(string $type, array $action, Message $message, MailRule $rule): bool
    {
        $account = $message->getAccount();

        switch ($type) {
            case self::APPLY_LABEL:
                $label = $this->resolveLabel($action, $rule);

                if (null === $label) {
                    return false;
                }

                // Materialise the label on this message's account before it is
                // pushed — a label the account has no binding for has no
                // provider-side identity yet.
                $this->labelResolver->binding($label, $account);

                $message->addLabel($label);
                $this->propagator->attachLabel([$message], $label);

                return true;

            case self::REMOVE_LABEL:
                $label = $this->resolveLabel($action, $rule);

                if (null === $label) {
                    return false;
                }

                $message->removeLabel($label);
                // Must follow the removal and precede the flush — the
                // propagator resolves the IMAP move from current state.
                $this->propagator->detachLabel([$message], $label);

                return true;

            case self::MARK_READ:
                if (null === $message->getSeenAt()) {
                    $message->setSeenAt(new \DateTimeImmutable());
                    $this->propagator->markRead([$message], true);
                }

                return false;

            case self::STAR:
                if (null === $message->getStarredAt()) {
                    $message->setStarredAt(new \DateTimeImmutable());
                    $this->propagator->star([$message], true);
                }

                return false;

            case self::ARCHIVE:
                return $this->moveOutOfInbox($message, null);

            case self::TRASH:
                return $this->moveOutOfInbox($message, LabelRole::Trash);

            case self::MARK_SPAM:
                return $this->moveOutOfInbox($message, LabelRole::Spam);

            case self::SAVE_TO_INTEGRATION:
                $this->queueUpload($action, $message, $rule);

                return false;

            default:
                $this->logger->warning('RuleActionExecutor: unknown action', [
                    'ruleId' => $rule->id,
                    'type'   => $type,
                ]);

                return false;
        }
    }

    /**
     * Hand the message's attachments to a worker.
     *
     * Deliberately no HTTP here. This runs inside the sync loop, once per rule
     * per batch of newly arrived mail, so an upload on this path would put a
     * network round trip per attachment between the mail arriving and it being
     * visible — and a service that is down would stall the sync itself. Same
     * discipline as LabelChangePropagator: decide here, do the I/O elsewhere.
     *
     * Messages with no attachments are skipped before dispatch rather than in
     * the handler, so a rule matching mostly plain mail does not fill the queue
     * with jobs that have nothing to do.
     *
     * @param array<string,mixed> $action
     */
    private function queueUpload(array $action, Message $message, MailRule $rule): void
    {
        $integrationId = $action['integrationId'] ?? null;

        if (false === is_int($integrationId)) {
            return;
        }

        if (true !== $message->hasAttachments()) {
            return;
        }

        $integration = $this->integrationRepository->find($integrationId);

        // Re-checked at execution time, not trusted from the stored action: the
        // connection may have been deleted, paused or handed to another user
        // since the rule was written.
        if (null === $integration
            || $integration->usr !== $rule->usr
            || false === $integration->supports(Capability::Upload)
        ) {
            $this->logger->warning('RuleActionExecutor: saveToIntegration skipped', [
                'ruleId'        => $rule->id,
                'integrationId' => $integrationId,
            ]);

            return;
        }

        $this->bus->dispatch(new UploadAttachmentsMessage(
            (int) $message->getId(),
            $integrationId,
            is_string($action['folder'] ?? null) ? $action['folder'] : null,
        ));
    }

    /**
     * Archive, trash and spam are all "leave the inbox", differing only in
     * where the message lands. Archive lands nowhere in particular — removing
     * Inbox is the whole operation, which is also how the UI models it.
     */
    private function moveOutOfInbox(Message $message, ?LabelRole $destination): bool
    {
        $account = $message->getAccount();
        $user    = $account->getUsr();

        $inbox = $this->labelRepository->findOneByRoleForUser(LabelRole::Inbox, $user);

        if (null !== $inbox) {
            $message->removeLabel($inbox);
        }

        if (null !== $destination) {
            $label = $this->labelResolver->systemLabel($destination, $account);
            $message->addLabel($label);
        }

        // One provider call for the whole move, not one per label: these carry
        // provider-specific semantics (Gmail label swap, IMAP folder move)
        // that the propagator already encodes.
        match ($destination) {
            LabelRole::Trash => $this->propagator->trash([$message]),
            default => $this->propagator->archive([$message]),
        };

        return true;
    }

    /**
     * @param array<string,mixed> $action
     */
    private function resolveLabel(array $action, MailRule $rule): ?\App\Entity\Label\Label
    {
        $labelId = $action['labelId'] ?? null;

        if (false === is_int($labelId)) {
            return null;
        }

        $label = $this->labelRepository->find($labelId);

        // Scoped to the rule's owner: a rule must never reach a label the user
        // does not own, whatever ends up in the stored json.
        if (null === $label || $label->usr !== $rule->usr) {
            $this->logger->warning('RuleActionExecutor: label not found for rule owner', [
                'ruleId'  => $rule->id,
                'labelId' => $labelId,
            ]);

            return null;
        }

        return $label;
    }
}
