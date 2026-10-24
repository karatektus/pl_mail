<?php

namespace App\Service\Imap;

use App\Domain\Helper\ImapFolderLocator;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Crypto\AutocryptHeader;
use App\Service\Crypto\SealedDrafts;
use App\Service\Crypto\SealGuard;
use App\Service\Crypto\SendingKey;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\DraftAttachmentService;
use App\Service\Mail\Mime\DataImageEmbedder;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\MailSenderRegistry;
use App\Service\Mail\Mime\SealedEntityPart;
use App\Service\Mail\RawMessageResolver;
use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Exception\SendFailureException;
use App\Domain\Exception\SendOutcomeUnknownException;
use App\Domain\Exception\SendRefusedException;
use App\Domain\Interface\MailDeliveryInterface;
use App\Domain\Interface\MailSenderInterface;
use App\Domain\Interface\SentCopyLookupInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds the outgoing MIME once, then dispatches it to the sender that handles
 * the account (Gmail API for Google OAuth, SMTP for password accounts).
 */
class MessageSendService
{
    /** The outer subject of a message whose real one is encrypted. */
    public const string PROTECTED_SUBJECT = '...';

    public function __construct(
        private readonly MailboxRepository       $mailboxRepository,
        private readonly EntityManagerInterface  $em,
        private readonly MailSenderRegistry      $senderRegistry,
        private readonly ImapConnectionFactory   $imapConnectionFactory,
        private readonly AttachmentResolver      $attachmentResolver,
        private readonly LabelResolver           $labelResolver,
        private readonly LabelRepository         $labelRepository,
        private readonly MailChangeRecorder      $changes,
        private readonly MessageThreader         $threader,
        private readonly ThreadLabelSynchronizer $threadLabels,
        private readonly SealGuard               $sealGuard,
        private readonly SealedDrafts            $sealedDrafts,
        private readonly AutocryptHeader         $autocrypt,
        private readonly SendingKey              $sendingKeys,
        private readonly RawMessageResolver      $rawMessages,
        private readonly DraftAttachmentService  $draftAttachments,
        private readonly LoggerInterface         $logger = new NullLogger(),
        private readonly DataImageEmbedder       $dataImages = new DataImageEmbedder(),
    ) {
    }

    /**
     * Send the message, or say why not.
     *
     * @return bool true once the provider has the mail and the row says so;
     *              false only for a message with no account to send from
     *
     * @throws SendFailureException a send that did not go through, classified
     *                              by what the next attempt should do — see
     *                              SendMessageHandler
     */
    public function send(Message $message): bool
    {
        $account = $message->account;

        if (null === $account) {
            return false;
        }

        // A message the browser built goes out as it was built or not at all:
        // the server holds no key to build it again. See SealGuard.
        $refusal = $this->sealGuard->refusal($message);

        if (null !== $refusal) {
            $this->logger->warning('MessageSendService: a message built in the browser was not sent: {reason}', [
                'reason'  => $refusal,
                'message' => $message->id,
            ]);

            return false;
        }

        $email = $this->buildEmail($message, $account);

        // Committed before the first attempt, not after the last: a send whose
        // answer is lost is settled by looking for this id in Sent, and the
        // handler clears the entity manager after a failed attempt, which took
        // a freshly minted id with it. The retry then minted another, and
        // looked for a mail that had never been sent under it (#125).
        if (true === $this->stampMessageId($message, $email)) {
            $this->em->flush();
        }

        $sender = $this->senderRegistry->resolve($account);

        // An earlier attempt lost its answer, so the mail may already be out.
        // Look before sending it again. See SendOutcomeUnknownException.
        if (null !== $message->sendUnconfirmedAt) {
            if (true === $this->foundInSent($sender, $account, $message)) {
                return $this->recordSent($message, $account, $email, $sender);
            }

            $message->sendUnconfirmedAt = null;
            $this->em->flush();
        }

        if ($sender instanceof MailDeliveryInterface) {
            // Throws what kind of failure it was; the handler decides what
            // happens next. Nothing is recorded on the row here.
            $sender->deliver($email, $account);
        } elseif (false === $sender->send($email, $account)) {
            // A sender that only says yes or no — the demo sender, a test
            // double. No reason to go on, so it is taken at its word as a
            // refusal: not retried, and said at once, as it always was.
            throw new SendRefusedException('The sender refused the message.', SendFailure::Refused);
        }

        return $this->recordSent($message, $account, $email, $sender);
    }

    /**
     * Whether this message's sender can be asked if a mail went out, which is
     * what decides how a lost answer is handled. See SendMessageHandler.
     */
    public function canCheckSent(Message $message): bool
    {
        return $this->senderRegistry->resolve($message->account) instanceof SentCopyLookupInterface;
    }

    /**
     * Whether the provider's Sent mail already holds this message.
     *
     * @throws SendOutcomeUnknownException when it cannot be asked, or this
     *                                      sender is not one that can be: then
     *                                      nobody knows, and the mail must not
     *                                      be sent again
     */
    private function foundInSent(MailSenderInterface $sender, Account $account, Message $message): bool
    {
        $messageId = MessageIdHelper::normalise((string) $message->messageId);

        if (false === $sender instanceof SentCopyLookupInterface || '' === $messageId) {
            throw new SendOutcomeUnknownException('Whether the earlier attempt delivered the mail cannot be checked.');
        }

        try {
            return $sender->isInSent($account, $messageId);
        } catch (Throwable $failure) {
            throw new SendOutcomeUnknownException('Sent could not be searched for the earlier attempt: ' . $failure->getMessage(), $failure);
        }
    }

    /**
     * The provider has the mail: write that down first, then the rest.
     */
    private function recordSent(Message $message, Account $account, Email $email, MailSenderInterface $sender): bool
    {
        // THE PROVIDER HAS THE MAIL. From this line on nothing may throw out of
        // this method, and the order below is what guarantees it.
        //
        // Anything that escaped used to reach SendMessageHandler's catch, which
        // released the claim — sent_at still NULL — and handed the exception to
        // Messenger, whose retry claimed the row again and sent the mail again:
        // up to six copies in the recipient's inbox because the IMAP server
        // holding the Sent folder was slow to answer. So sentAt is written and
        // committed first, on its own, which makes every later claimForSend()
        // refuse; the bookkeeping after it is best effort and only logged.
        $message->sentAt = new DateTimeImmutable();

        // Whatever an earlier attempt left on the row is over: no warning, no
        // retry clock, nothing waiting to be checked.
        $message->sendFailedAt      = null;
        $message->sendFailure       = null;
        $message->sendRetryingSince = null;
        $message->sendUnconfirmedAt = null;

        try {
            $this->em->flush();
        } catch (Throwable $failure) {
            // The one outcome with no good answer. Returning true keeps the
            // claim standing, so the retry ladder is refused and the mail is not
            // sent twice; what is lost is the draft->sent transition on the row,
            // which is a far smaller wrong than a duplicate on the wire.
            $this->logger->critical('MessageSendService: mail was sent but recording it failed', [
                'message'   => $message->id,
                'exception' => $failure,
            ]);

            return true;
        }

        try {
            $this->recordDelivery($message, $account, $email, $sender);
        } catch (Throwable $failure) {
            $this->logger->error('MessageSendService: mail was sent, post-send bookkeeping failed', [
                'message'   => $message->id,
                'exception' => $failure,
            ]);
        }

        return true;
    }

    /**
     * Everything that follows a send the provider accepted: the Sent copy, the
     * Drafts->Sent move, the thread's ordering and labels, the change log.
     *
     * None of it decides whether the mail left — that is already committed by
     * the time this runs — so a failure here is reported and survived, never
     * propagated. See send().
     */
    private function recordDelivery(Message $message, Account $account, Email $email, MailSenderInterface $sender): void
    {
        if (null !== $message->outgoingSeal) {
            $this->keepWhatWasSent($message, $email);
        }

        // API senders file their own Sent copy; only append manually for SMTP.
        //
        // Its own try, so an unreachable IMAP server costs the Sent copy and
        // nothing else: the labels and the change log below still describe
        // what actually happened.
        if (false === $sender->filesSentCopy()) {
            try {
                $this->appendToSentFolder($email, $account);
            } catch (Throwable $failure) {
                $this->logger->error('MessageSendService: mail was sent, appending the Sent copy failed', [
                    'message'   => $message->id,
                    'exception' => $failure,
                ]);
            }
        }

        $sentLabel   = $this->labelResolver->systemLabel(LabelRole::Sent, $account);
        $draftsLabel = $this->labelRepository->findOneByRoleForUser(LabelRole::Drafts, $account->usr);

        $message->addLabel($sentLabel);

        if (null !== $draftsLabel) {
            $message->removeLabel($draftsLabel);
        }

        $message->removeFlag(MessageFlag::DRAFT);

        // Plain-IMAP: physical Sent folder; Gmail: no mailbox.
        $message->mailbox = $sentLabel->bindingFor($account)?->mailbox;

        // The conversation just moved, and until now nothing said so. Thread
        // lists all sort on lastMessageAt and only the threader ever wrote it,
        // so a thread ranked where its last *incoming* message left it: you
        // answered a mail and the conversation stayed buried under everything
        // that had arrived since. See MessageThreader::recordActivity().
        $thread = $message->thread;

        if (null !== $thread) {
            $this->threader->recordActivity($message, $thread);

            // Swapping Drafts for Sent is a message-level label mutation, and
            // this is what such a mutation is always followed by — the thread's
            // labels are the union of its messages'. Missing it, the
            // conversation kept a Drafts label with no draft in it and never
            // gained a Sent one, so an answered thread was absent from the Sent
            // list until the server copy came back and dragged the label along
            // as a side effect of being a duplicate.
            $this->threadLabels->sync($thread);
        }

        // The draft->sent transition rewrites three properties JMAP publishes:
        // keywords (the $draft keyword goes away), mailboxIds (EmailMapper
        // reads those off the labels, so swapping Drafts for Sent is a move as
        // far as a client is concerned) and sentAt. Nothing was announced here,
        // so a client saw the draft appear and never heard another word about
        // it — mail that left the building hours ago sat in its cache as an
        // unsent draft until a full resync.
        //
        // Ahead of the flush below on purpose. The message was persisted long
        // before the send was queued and so was its thread, so both ids exist,
        // and recording only persists — putting it here commits the log rows in
        // the same unit of work as the transition they describe, rather than
        // leaving a window where the mail is sent and the log does not say so.
        //
        // The Sent Mailbox itself needs nothing: systemLabel() above goes
        // through LabelResolver::binding(), which records the Mailbox create
        // when it mints a binding, and per-mailbox counts are not a change
        // this codebase logs (see EmailSetMethod, which moves messages between
        // mailboxes and records only the Email and its Thread).
        $this->changes->emailChanged(
            (int) $account->id,
            (string) $message->id,
            created: false,
            thread: $message->thread,
        );

        // Only for mail that arrived here through EmailSubmission/set, which is
        // what submissionSendAt marks. A send from the web composer has no
        // submission a client was told about — EmailSubmission/get reports it
        // as final because any sent Email is describable that way, but nothing
        // was ever "pending" for it, so announcing a change would wake clients
        // for a transition none of them was watching.
        //
        // For the ones that were: the submission this client is holding says
        // pending and a release time, and it has just become final. Without
        // this the only announcement was the Email's, and a client that follows
        // the submission — which is the object that told it "sending at 09:00"
        // — would show a schedule for mail that has already gone.
        if (null !== $message->submissionSendAt) {
            $this->changes->submissionChanged((int) $account->id, (string) $message->id);
        }

        $this->em->flush();
    }

    /**
     * Give the outgoing mail one Message-ID and put it on the row as well.
     *
     * This is the durable identity of the message, and before this nothing had
     * one. The row was stored with message_id NULL, and the MIME was left for
     * Symfony to label: Message::getPreparedHeaders() mints an id only into the
     * clone it returns, so the transport's copy and the `toString()` used for
     * the Sent APPEND each got a *different* random id. Three stores, three
     * identities, nothing to reconcile on — so when the Sent copy came back
     * from the server the syncer had no way to recognise it as the message it
     * had already stored, and inserted it again. That is the duplicate.
     *
     * Set on the headers before either use of $email, so the recipient's copy,
     * the Sent copy and the row all name the same message — and so a reply to
     * it references an id we can actually find.
     *
     * Kept if the row already has one: a resend of the same row is the same
     * message, and re-minting would strand the copy already on the server.
     */
    /** @return bool whether the id was minted just now, and so is not on the row yet */
    private function stampMessageId(Message $message, Email $email): bool
    {
        $existing = MessageIdHelper::normalise((string) $message->messageId);
        $minted   = false;

        if ('' === $existing) {
            $minted = true;
            $existing = MessageIdHelper::mint(
                (string) ($email->getFrom()[0]->getAddress() ?? ''),
            );

            $message->messageId = $existing;
        }

        $headers = $email->getHeaders();

        if (true === $headers->has('Message-ID')) {
            $headers->remove('Message-ID');
        }

        // addIdHeader wants the bare id and writes the angle brackets itself,
        // which is why the column stores the canonical bracket-less form.
        $headers->addIdHeader('Message-ID', $existing);

        return $minted;
    }

    /**
     * File a copy of mail that has already left into the account's IMAP Sent
     * folder. For a sender whose filesSentCopy() is false; a provider API
     * files its own.
     *
     * Public for the one other mail plMail sends as the user and wants in
     * their Sent folder: feedback from the What's new dialog (#165). Throws
     * whatever the IMAP server throws; the caller decides what a missing copy
     * is worth.
     */
    public function appendToSentFolder(Email $email, Account $account): void
    {
        $sentMailbox = $this->mailboxRepository->findSentMailboxForAccount($account);

        if (null === $sentMailbox) {
            return;
        }

        $client = $this->imapConnectionFactory->connect($account);
        $folder = ImapFolderLocator::of($client, $sentMailbox);

        $folder->appendMessage(
            $email->toString(),
            [MessageFlag::SEEN->value],
        );

        $client->disconnect();
    }

    private function buildEmail(Message $message, Account $account): Email
    {
        $subject = $message->subject;
        if (null === $subject) {
            $subject = '';
        }

        // Header protection (RFC 9788): the browser put the real subject
        // inside the encryption, and the outside says only what Thunderbird
        // and others show for it. The row keeps the subject the person wrote.
        if (true === ($message->outgoingSeal['protectedSubject'] ?? false)) {
            $subject = self::PROTECTED_SUBJECT;
        }

        $fromAddress = $message->fromAddress;

        if (null === $fromAddress || '' === $fromAddress) {
            $fromAddress = $account->displayAddress ?? $account->email ?? '';
        }

        // After the address, because the name depends on it: an alias with a
        // display name of its own sends under that, and only one without falls
        // back to the account's. This read the account's name unconditionally,
        // so the From selector offered "Mr and Mrs Smith <family@…>" and the
        // mail went out as the account holder.
        $fromName = $account->senderNameFor($fromAddress) ?? '';

        $email = new Email()
            ->from(new Address($fromAddress, $fromName))
            ->subject($subject);

        $this->applyPriority($message, $email);
        $this->applyReceiptRequest($message, $email, $fromAddress);
        $this->applyThreadingHeaders($message, $email);
        $this->applyAutocrypt($account, $email, $fromAddress);

        $toAddresses = $message->toAddresses;
        if (null !== $toAddresses) {
            foreach ($toAddresses as $addr) {
                $email->addTo($this->toAddress($addr));
            }
        }

        $ccAddresses = $message->ccAddresses;
        if (null !== $ccAddresses) {
            foreach ($ccAddresses as $addr) {
                $email->addCc($this->toAddress($addr));
            }
        }

        $bccAddresses = $message->bccAddresses;
        if (null !== $bccAddresses) {
            foreach ($bccAddresses as $addr) {
                $email->addBcc($this->toAddress($addr));
            }
        }

        // Built in the browser: the body is that message, byte for byte, and
        // nothing on the row — body, parts — is any part of it.
        $entity = $this->sealedDrafts->entityOf($message);

        if (null !== $entity) {
            $email->setBody(new SealedEntityPart($entity));

            return $email;
        }

        if ($message->bodyHtml) {
            // `data:` images become inline parts on the way out — most mail
            // clients do not draw a data URI. See DataImageEmbedder.
            $email->html($this->dataImages->embed($email, $message->bodyHtml));
        }

        if ($message->bodyText) {
            $email->text($message->bodyText);
        }

        foreach ($message->messageParts as $part) {
            if (true === $part->isInline) {
                $contentId = $part->contentId;
                if (null === $contentId) {
                    $contentId = $part->filename;
                }

                $email->embedFromPath(
                    $this->attachmentResolver->absolutePathFor($part),
                    $contentId,
                    $part->contentType,
                );
            } else {
                $email->attachFromPath(
                    $this->attachmentResolver->absolutePathFor($part),
                    $part->filename,
                    $part->contentType,
                );
            }
        }

        $this->attachPublicKey($account, $email, $fromAddress);

        return $email;
    }

    /**
     * The sender's public key in an Autocrypt header, so the program on the
     * other end can answer encrypted. See AutocryptHeader; nothing is written
     * when the person has switched it off or has no key for this address.
     */
    private function applyAutocrypt(Account $account, Email $email, string $fromAddress): void
    {
        if (null === $account->usr) {
            return;
        }

        $header = $this->autocrypt->for($account->usr, $fromAddress);

        if (null !== $header) {
            $email->getHeaders()->addTextHeader('Autocrypt', $header);
        }
    }

    /**
     * The sender's public key as a file, when they asked for that (off by
     * default — User::$attachPublicKey). Mail the browser built carries it
     * inside, where the browser put it; this is for the mail built here.
     */
    private function attachPublicKey(Account $account, Email $email, string $fromAddress): void
    {
        if (null === $account->usr || false === $account->usr->attachPublicKey) {
            return;
        }

        $key = $this->sendingKeys->for($account->usr, $fromAddress);

        if (null !== $key) {
            $email->attach($key->publicKey, sprintf('OpenPGP_0x%s.asc', strtoupper(substr($key->fingerprint, -16))), 'application/pgp-keys');
        }
    }

    /**
     * What a message built in the browser leaves behind once it has gone.
     *
     * The bytes that went out become the row's raw source: for encrypted mail
     * that is the only copy of the text there is, and the reading pane opens
     * it the way it opens any encrypted mail. Attachments the browser sealed
     * are ciphertext nobody could open as files, and for encrypted mail every
     * attachment is inside the message already — so those go. What is left of
     * a signed or plain message's attachments is what it sent.
     */
    private function keepWhatWasSent(Message $message, Email $email): void
    {
        // Encrypted mail drops every attachment — they are inside it — unless
        // its account keeps decrypted mail, which keeps the readable ones.
        $encrypted = SealGuard::KIND_ENCRYPTED === ($message->outgoingSeal['kind'] ?? null)
            && PlaintextSource::Client !== $message->plaintextSource;

        $this->rawMessages->store($message, $email->toString());

        foreach ($message->messageParts->toArray() as $part) {
            if (true === $encrypted || true === $part->sealed) {
                $this->draftAttachments->remove($part);
            }
        }

        $this->sealedDrafts->sent($message);
    }

    /**
     * In-Reply-To and References, so a reply threads for whoever receives it.
     *
     * The draft always carried them (ReplyDraftBuilder fills them, and JMAP
     * Email/set stores what the client sent) and nothing wrote them onto the
     * mail, so every reply left as a message of its own and Outlook, Gmail and
     * Apple Mail showed a new conversation. The ids are written bracket-less
     * because that is how Symfony's ID header takes them.
     *
     * References ends with the parent: RFC 5322 §3.6.4 has a reply carry its
     * parent's References plus the parent's Message-ID, and a draft whose
     * list lacks the parent (a JMAP client that set only inReplyTo) would
     * otherwise send a chain that stops one short. The parent is taken out of
     * the list before it is appended, so a list that already names it
     * somewhere else — a client's own ordering — still ends with it rather
     * than keeping it where normaliseList()'s de-duplication found it first.
     */
    private function applyThreadingHeaders(Message $message, Email $email): void
    {
        $inReplyTo  = MessageIdHelper::normaliseList($message->inReplyTo);
        $references = MessageIdHelper::normaliseList(array_merge(
            array_diff(MessageIdHelper::normaliseList($message->references), $inReplyTo),
            $inReplyTo,
        ));

        if ([] !== $inReplyTo) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $inReplyTo);
        }

        if ([] !== $references) {
            $email->getHeaders()->addIdHeader('References', $references);
        }
    }

    /**
     * Both priority headers, or neither.
     *
     * Two headers for one fact because no single one is read everywhere:
     * X-Priority is the numeric Outlook/Eudora convention that predates any
     * standard and is still what a lot of desktop clients look at, Importance
     * is the RFC 4021 field that Gmail, Graph and modern IMAP clients surface.
     * A message setting only one of them shows as urgent in half the world and
     * ordinary in the other half, which is worse than not marking it at all —
     * so they are written together, from one mapping, and can never disagree.
     *
     * Nothing is written when the row has no priority. An absent header is the
     * correct way to say "ordinary": every client already treats it that way,
     * and emitting `X-Priority: 3` on every message would put a header on all
     * outgoing mail to express the default.
     */
    private function applyPriority(Message $message, Email $email): void
    {
        $priority = $message->priority;

        if (null === $priority) {
            return;
        }

        $headers = $email->getHeaders();

        $headers->addTextHeader('X-Priority', $priority->xPriority());
        $headers->addTextHeader('Importance', $priority->importance());
    }

    /**
     * Ask to be told when this message is read.
     *
     * Two headers again, and again because the field is not settled:
     * Disposition-Notification-To is the RFC 8098 one every current client
     * honours, Return-Receipt-To is the older informal convention some MTAs
     * and legacy clients act on. Both name the same address.
     *
     * That address is the sending identity's, taken from the same $fromAddress
     * the From header was built with rather than from the account — a message
     * sent from an alias must have its receipt come back to that alias, or the
     * MDN arrives addressed to a mailbox the recipient was never writing to
     * and the correlation on the way back in has nothing to match. It is also
     * the pairing the receiving end is entitled to expect: a
     * Disposition-Notification-To that disagrees with From is exactly what
     * ReadReceiptPolicy refuses to auto-answer, and plMail must not send mail
     * that trips its own guard.
     */
    private function applyReceiptRequest(Message $message, Email $email, string $fromAddress): void
    {
        if (false === $message->wantsReadReceipt()) {
            return;
        }

        if ('' === $fromAddress) {
            return;
        }

        $headers = $email->getHeaders();

        $headers->addMailboxListHeader('Disposition-Notification-To', [new Address($fromAddress)]);
        $headers->addMailboxListHeader('Return-Receipt-To', [new Address($fromAddress)]);
    }

    /**
     * @param array{name?: string|null, address: string} $addr
     */
    private function toAddress(array $addr): Address
    {
        $name = '';

        if (array_key_exists('name', $addr) && null !== $addr['name']) {
            $name = $addr['name'];
        }

        return new Address($addr['address'], $name);
    }
}
