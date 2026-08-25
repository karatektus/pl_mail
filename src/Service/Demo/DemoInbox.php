<?php

declare(strict_types=1);

namespace App\Service\Demo;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Service\Label\LabelResolver;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Mail\SyncNotifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Puts a piece of scripted mail into a demo mailbox as though it had just been
 * synced.
 *
 * Deliberately goes through PostIngestPipeline rather than writing a Message
 * and a MessageThread by hand the way the seed commands do. The seeders are
 * building a fixed picture and can afford to fake the threading; this is
 * answering a button a visitor pressed, and what the visitor is being shown is
 * that plMail *does* something when mail arrives — the reply lands in the
 * thread it belongs to, the category is decided, the filters they just built
 * run against it, JMAP clients hear about it. All of that is the pipeline. A
 * hand-written row would put the message on screen and none of it would be
 * true, which is a worse demo than no button.
 *
 * The Mercure publish afterwards is the same tail SyncImapMailboxMessageHandler
 * has, and is what makes the mail appear without a reload — without it the
 * button looks broken until the visitor navigates.
 */
final readonly class DemoInbox
{
    public function __construct(
        private EntityManagerInterface  $entityManager,
        private LabelResolver           $labelResolver,
        private PostIngestPipeline      $pipeline,
        private SyncNotifier            $syncNotifier,
        private AttachmentStorageHelper $attachmentStorage,
    ) {
    }

    /**
     * Delivers one scenario into the account's inbox and returns the message.
     *
     * `$inReplyTo` threads the delivery onto an existing conversation, which is
     * what the auto-reply passes: the Message-ID of the mail the visitor just
     * sent. The threader reads inReplyTo/references first and only falls back
     * to the subject, so a reply lands in the right conversation even though
     * the subject line was rewritten by the sender.
     */
    public function deliver(
        Account       $account,
        DemoScenario  $scenario,
        ?string       $inReplyTo = null,
        ?string       $subject = null,
    ): Message {
        $now   = new DateTimeImmutable();
        $inbox = $this->labelResolver->systemLabel(LabelRole::Inbox, $account);

        $message = new Message();
        $message->account        = $account;
        // Minted and stored WITHOUT angle brackets, which is not cosmetic.
        // MessageThreader normalises In-Reply-To and References before looking
        // the parent up, then compares against message_id as stored — so a
        // bracketed id here can never match, and the auto-reply would silently
        // start its own thread instead of landing on the conversation it
        // answers. MessageSendService::stampMessageId() writes the same shape
        // for the same reason; the brackets belong on the MIME header only.
        $message->messageId      = MessageIdHelper::mint($scenario->fromAddress);
        $message->subject        = $subject ?? $scenario->subject;
        $message->fromName       = $scenario->fromName;
        $message->fromAddress    = $scenario->fromAddress;
        $message->toAddresses    = [['name' => $account->name, 'address' => (string) $account->email]];
        $message->bodyText       = $scenario->bodyText;
        $message->bodyHtml       = $scenario->bodyHtml;
        $message->receivedAt     = $now;
        $message->sentAt         = $now;
        $message->syncedAt       = $now;
        $message->flags          = [];
        $message->hasAttachments = null !== $scenario->attachment;

        if (null !== $inReplyTo) {
            // Normalised on the way in too: it arrives off a MIME header, so it
            // is bracketed, and both columns are read back through
            // MessageIdHelper::normaliseList().
            $parentId = MessageIdHelper::normalise($inReplyTo);

            if ('' !== $parentId) {
                $message->inReplyTo  = [$parentId];
                $message->references = [$parentId];
            }
        }

        $message->addLabel($inbox);

        if (null !== $scenario->label) {
            $message->addLabel($this->labelResolver->customChain([$scenario->label], $account));
        }

        $this->entityManager->persist($message);

        // Ahead of the attachment and of the pipeline, both of which need the
        // id: the storage path is built from it, and the pipeline's docblock
        // makes a flushed row its precondition.
        $this->entityManager->flush();

        if (null !== $scenario->attachment) {
            $this->attach($message, $account, $scenario->attachment);
        }

        $this->pipeline->run($account, [new IngestedMessage($message, $account)]);

        // Same tail as a real IMAP sync. account.synced rather than
        // mailbox.synced because a demo account has no Mailbox rows — nothing
        // ever connected to an IMAP server to enumerate them — and the sidebar
        // listens for both.
        $this->syncNotifier->publishAccountSynced($account);

        return $message;
    }

    /**
     * @param array{string, string} $attachment
     */
    private function attach(Message $message, Account $account, array $attachment): void
    {
        [$filename, $contents] = $attachment;

        $storagePath = $this->attachmentStorage->store(
            (int) $account->id,
            0,
            (int) $message->id,
            $filename,
            $contents,
        );

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = 'text/plain';
        $part->filename    = $filename;
        $part->disposition = 'attachment';
        $part->size        = strlen($contents);
        $part->storagePath = $storagePath;
        $part->isInline    = false;

        $message->addMessagePart($part);

        $this->entityManager->persist($part);
        $this->entityManager->flush();
    }
}
