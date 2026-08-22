<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What it takes to make a draft real: which account it belongs to, the flags
 * and bodies a draft carries, the conversation it joins, and telling JMAP
 * clients it happened.
 *
 * The sequence is subtle in three places — the attachment flag has to be
 * derived rather than assigned, the thread has to be told about the message and
 * not just the other way round, and the announcement has to come after the
 * flush that mints the id. Each of those was a bug once, and the comments below
 * are the record of it.
 *
 * Which is why this is a service rather than a controller method. There were
 * two copies: ComposeController for the browser and JmapDraftWriter for every
 * other client, whose docblock said in as many words that any change to draft
 * semantics had to land in both. Now there is one, and JmapDraftWriter composes
 * it out of the steps below because it differs from the web composer in exactly
 * two documented ways — see its persistDraft().
 */
final readonly class DraftPersister
{
    public function __construct(
        private EntityManagerInterface  $entityManager,
        private LabelResolver           $labelResolver,
        private MessageThreader         $threader,
        private ThreadLabelSynchronizer $threadLabelSynchronizer,
        private MailBodySanitizer       $bodySanitizer,
        private DraftAttachmentService  $attachments,
        private MailChangeRecorder      $changes,
        private InlineImageRewriter     $inlineImages,
    ) {
    }

    /**
     * Save a draft and announce it: the whole sequence, in order.
     *
     * Every route that makes a web-composed message real comes through here —
     * both autosaves and the send — so this is the one place JMAP has to hear
     * about it. Without that a draft written in the browser, and the mail it
     * turns into, never appeared in Email/changes at all.
     */
    public function save(Message $message, Account $account, ?string $sender = null): void
    {
        $this->fileUnderAccount($message, $account);

        // Before markAsDraft(), which sanitises: the stored body is the one
        // that goes on the wire, so the editor's attachment URLs become `cid:`
        // references here and nowhere else. Doing it in the send path instead
        // would leave the autosaved draft — and the copy JMAP publishes —
        // pointing at a route only a logged-in plMail session can follow.
        // MailBodySanitizer::resolveCids() then turns them back into URLs for
        // bodyHtmlSafe, which is what the thread renders.
        $message->bodyHtml = $this->inlineImages->toCid($message->bodyHtml);

        $this->markAsDraft($message, $account, $sender);

        // Derived from the HTML — unless there IS no HTML, which is what the
        // composer's plain-text mode posts. In that mode the window submits an
        // empty bodyHtml and the text the user actually typed as bodyText, and
        // that text IS the message: there is nothing to derive it from and
        // overwriting it with null (which is what plainTextBody() answers for
        // an empty body) would send an empty mail.
        //
        // Rich mode is unaffected — bodyText is a mapped field there too, so a
        // submit with the plain-text surface switched off binds it to null and
        // this line fills it in again. Emptying the editor completely still
        // clears the text rather than leaving the last version of it behind.
        if (null !== $message->bodyHtml && '' !== trim($message->bodyHtml)) {
            $message->bodyText = $this->plainTextBody($message->bodyHtml);
        }

        $created = $this->storeAndThread($message, $account);

        // After the flush inside storeAndThread(), not before: recording only
        // persists, so the id it is given has to exist already, and a thread
        // the threader created moments ago only gets one there. Same shape as
        // PostIngestPipeline — ids, record, flush the log rows.
        $this->changes->emailChanged(
            (int) $account->id,
            (string) $message->id,
            $created,
            $message->thread,
        );

        $this->entityManager->flush();
    }

    /**
     * Wire the message to its From account: Drafts label of that account, plus
     * the physical Drafts folder for plain-IMAP accounts (Gmail accounts have
     * no mailboxes — mailbox stays null).
     *
     * Switching the From account on an existing draft moves it: Drafts labels
     * of other accounts are dropped.
     */
    public function fileUnderAccount(Message $message, Account $account): void
    {
        $message->account = $account;

        $draftsLabel = $this->labelResolver->systemLabel(LabelRole::Drafts, $account);

        foreach ($message->labels as $label) {
            if (LabelRole::Drafts === $label->role && $label !== $draftsLabel) {
                $message->removeLabel($label);
            }
        }

        $message->addLabel($draftsLabel);

        $message->mailbox = $draftsLabel->bindingFor($account)?->mailbox;
    }

    /**
     * The bookkeeping a draft carries whoever wrote it: sender, the $draft
     * keyword, its own author having read it, and a render-ready body.
     *
     * The sanitiser matters: only the sync layer sanitises bodies, so without
     * it a draft (and the message it becomes) renders blank in the conversation
     * until the sent copy comes back from the provider.
     *
     * Leaves bodyText alone — see plainTextBody(), which the two callers of
     * this method do not agree on.
     */
    public function markAsDraft(Message $message, Account $account, ?string $sender = null): void
    {
        // Null means "whoever the account is", which is what a caller with no
        // From picker wants. It is a parameter rather than a read of whatever
        // the message already carries because this used to overwrite the
        // address unconditionally: the web composer set the alias the user had
        // just chosen, called through here, and had it replaced by the
        // account's own address one line later. Every mail sent from an alias
        // went out as the primary address, and nothing reported it.
        $message->fromAddress = $sender ?? $account->email;
        $message->fromName    = $account->name;
        $message->addFlag(MessageFlag::DRAFT);
        $message->seenAt ??= new DateTimeImmutable();

        $this->attachments->syncFlag($message);

        // bodyHtml first, and that ordering is the point. sanitize() reads
        // bodyHtml and writes bodyHtmlSafe — it never touched the field it read,
        // so a draft could be sanitised and still hold script in the field the
        // compose window echoes back with `|raw`, and the field that goes out
        // on the wire. Every route into this method is a caller we do not
        // control: a reply quoting a stranger's mail, a paste out of a browser,
        // a POST somebody wrote by hand.
        // null stays null rather than becoming '': "no body at all" and "a body
        // that sanitised down to nothing" are the same thing to every reader of
        // this field, but only the first is what a caller that sent no body
        // meant, and the entity's own type says so.
        if (null !== $message->bodyHtml) {
            $message->bodyHtml = $this->bodySanitizer->sanitizeComposedBody($message->bodyHtml);
        }

        $this->bodySanitizer->sanitize($message);
    }

    /**
     * Persist the draft, put it in a conversation and leave both flushed.
     *
     * @return bool true when this call minted the row, which is the only thing
     *              that tells a first save from an autosave of the same draft;
     *              afterwards every call through here looks identical, and a
     *              client would be handed "created" for an id it already holds
     */
    public function storeAndThread(Message $message, Account $account): bool
    {
        $created = null === $message->id;

        $this->entityManager->persist($message);

        if (null === $message->thread) {
            // Uses in_reply_to / references, so reply drafts land on the
            // original thread; fresh composes get a new one.
            $this->threader->assignThread($message, $account);
        }

        $this->threader->resyncDraftThreadSubject($message);

        $thread = $message->thread;

        if (null !== $thread) {
            // The threader only sets the owning side, so the thread does not
            // know about this message yet — and sync() derives a thread's
            // labels from the messages it can see. Without this it saw none of
            // them, stripped the Drafts label the threader had just copied
            // over, and the new draft never turned up in the Drafts list.
            $thread->addMessage($message);

            // Recounted from the association, never incremented — the same rule
            // ComposeController::discard() states, and for the same reason: the
            // stored counter is what the thread header renders and it drifts.
            //
            // It drifted here in one direction only, and invisibly. A reply
            // draft arrives with its thread already set (ReplyDraftBuilder
            // copies it off the message being answered), so the threader — the
            // only thing that maintained messageCount — never saw it, and the
            // count stayed one short until the Sent copy came back from the
            // server and was counted as a message of its own. Which is to say
            // the header only ever looked right because the conversation held a
            // duplicate; take the duplicate away and the arithmetic that was
            // wrong all along becomes visible.
            //
            // Idempotent, so an autosave of the same draft counts once.
            $thread->messageCount = $thread->messages->count();

            $this->threadLabelSynchronizer->sync($thread);
        }

        $this->entityManager->flush();

        return $created;
    }

    /**
     * The user's own writing as plain text: everything before the quoted
     * original (marked with data-quoted by ReplyDraftBuilder). Drives the draft
     * snippet in the conversation and the message list, and becomes the
     * text/plain part of the mail that goes out.
     */
    public function plainTextBody(?string $html): ?string
    {
        if (null === $html || '' === trim($html)) {
            return null;
        }

        // data-quoted is the current marker; the other two cut drafts written
        // before it existed (attribution div / bare blockquote).
        $ownPart = preg_split(
            '/<div[^>]*data-quote|<div[^>]*font-size:0\.85em|<blockquote/i',
            $html,
            2,
        )[0];

        $text = html_entity_decode(
            strip_tags(preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $ownPart) ?? $ownPart),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        $text = trim(preg_replace('/[ \t]*\R\s*/u', "\n", $text) ?? $text);

        return '' === $text ? null : $text;
    }
}
