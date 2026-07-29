<?php

namespace App\Controller;

use App\Domain\Trait\ParsesAddressFields;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Account;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Entity\MessageThread;
use App\Form\ComposeType;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\AccountRepository;
use App\Repository\ContactRepository;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\MailBodySanitizer;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Label-based compose: the From selector is an Account (unmapped form field),
 * not a Mailbox. Drafts carry the chosen account's Drafts label; for plain-
 * IMAP accounts the physical Drafts folder is attached as mailbox, for Gmail
 * accounts mailbox stays null.
 */
#[Route('/compose', name: 'app_compose_')]
class ComposeController extends AbstractController
{
    use ParsesAddressFields;

    private const string DOCK_FRAME   = 'compose_dock';
    private const string INLINE_FRAME = 'compose_inline';
    private const string INLINE_FORM  = 'compose_inline';

    /**
     * Per-file ceiling for compose attachments. Public because the compose
     * window reads it too, to refuse an oversized file before it is uploaded.
     * Must stay under upload_max_filesize (frankenphp/conf.d/10-app.ini).
     */
    public const int MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    /** Matches the DelayStamp on the send job — the cancel window. */
    private const int SEND_DELAY_MS = 10_000;

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly MailboxRepository       $mailboxRepository,
        private readonly MessageRepository       $messageRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly LabelResolver           $labelResolver,
        private readonly MessageBusInterface     $bus,
        private readonly MessageThreader         $threader,
        private readonly ThreadLabelSynchronizer $threadLabelSynchronizer,
        private readonly ContactRepository       $contactRepository,
        private readonly MailBodySanitizer       $bodySanitizer,
        private readonly AttachmentStorageHelper $attachmentStorage,
    )
    {
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    #[Route('/edit/{id}', name: 'edit', methods: ['GET'])]
    public function compose(Request $request, ?Message $message = null): Response
    {
        $ctx = $this->composeContext($request);

        if (null === $message) {
            $account = $this->defaultAccount();
            $message = new Message()
                ->setAccount($account)
                ->setCreatedAt(new DateTimeImmutable());

        } else {
            $this->assertOwnership($message);
            $account = $message->getAccount();
        }

        $form = $this->composeForm($message, $ctx);
        $form->get('account')->setData($this->senderToken($account));
        $this->hydrateAddressFields($form, $message);

        return $this->renderWindow($form, $message, $ctx);
    }

    #[Route('/reply/{id}', name: 'reply', methods: ['GET'])]
    public function reply(Request $request, Message $original): Response
    {
        $this->assertOwnership($original);

        $ctx            = $this->composeContext($request);
        $ctx['replyTo'] = $original->getId();
        $account        = $original->getAccount() ?? $this->defaultAccount();
        $draft          = $this->buildReply($original, replyAll: false, account: $account);

        $form = $this->composeForm($draft, $ctx);
        $form->get('account')->setData($this->senderToken($account));
        $this->hydrateAddressFields($form, $draft);

        return $this->renderWindow($form, $draft, $ctx);
    }

    #[Route('/reply-all/{id}', name: 'reply_all', methods: ['GET'])]
    public function replyAll(Request $request, Message $original): Response
    {
        $this->assertOwnership($original);

        $ctx            = $this->composeContext($request);
        $ctx['replyTo'] = $original->getId();
        $account        = $original->getAccount() ?? $this->defaultAccount();
        $draft          = $this->buildReply($original, replyAll: true, account: $account);

        $form = $this->composeForm($draft, $ctx);
        $form->get('account')->setData($this->senderToken($account));
        $this->hydrateAddressFields($form, $draft);

        return $this->renderWindow($form, $draft, $ctx);
    }

    #[Route('/forward/{id}', name: 'forward', methods: ['GET'])]
    public function forwardMessage(Request $request, Message $original): Response
    {
        $this->assertOwnership($original);

        $ctx     = $this->composeContext($request);
        $account = $original->getAccount() ?? $this->defaultAccount();
        $draft   = $this->buildForward($original);

        $form = $this->composeForm($draft, $ctx);
        $form->get('account')->setData($this->senderToken($account));
        $this->hydrateAddressFields($form, $draft);

        return $this->renderWindow($form, $draft, $ctx);
    }

    #[Route('/draft', name: 'form_new', methods: ['POST'])]
    #[Route('/draft/{id}', name: 'form_edit', methods: ['POST'])]
    public function draft(Request $request, ?Message $message = null): Response
    {
        if (null === $message) {
            $message = new Message()
                ->setAccount($this->defaultAccount())
                ->setCreatedAt(new DateTimeImmutable());
        } else {
            $this->assertOwnership($message);
        }

        $ctx  = $this->composeContext($request);
        $this->applyReplyContext($message, $ctx);
        $form = $this->composeForm($message, $ctx);

        $form->handleRequest($request);

        // Apply Tom Select address fields (override whatever CollectionType bound)
        $this->applyAddressFields($form, $message);

        if ($form->isSubmitted() && $form->isValid()) {
            $account = $this->resolveAccount($form, $message);

            if (null === $account) {
                throw $this->createNotFoundException('No active account to compose from.');
            }

            $message->setFromAddress($this->resolveFromAddress($form, $account));
            $message->setFromName($account->getName() ?? '');

            $this->applyAccount($message, $account);
            $this->persistDraft($message, $account);

            return $this->renderWindow($form, $message, $ctx, ['saved' => true]);
        }

        return $this->renderWindow($form, $message, $ctx);
    }

    #[Route('/send', name: 'mail_send', methods: ['POST'])]
    #[Route('/send/{id}', name: 'mail_send_draft', methods: ['POST'])]
    public function send(Request $request, ?Message $message): Response
    {
        if (null === $message) {
            $message = new Message();
        } else {
            $this->assertOwnership($message);
        }

        $ctx  = $this->composeContext($request);
        $this->applyReplyContext($message, $ctx);
        $form = $this->composeForm($message, $ctx, ['Default', 'send']);

        $form->handleRequest($request);

        // Apply Tom Select address fields
        $this->applyAddressFields($form, $message);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $message->getSentAt()) {
                return $this->sendResponse($message, $ctx);
            }

            $account = $this->resolveAccount($form, $message);

            if (null === $account) {
                throw $this->createNotFoundException('No active account to send from.');
            }

            $message->setFromAddress($this->resolveFromAddress($form, $account));
            $message->setFromName($account->getName() ?? '');

            $this->applyAccount($message, $account);
            $this->persistDraft($message, $account);

            $this->bus->dispatch(
                new SendMessageMessage($message->getId()),
                [new DelayStamp(self::SEND_DELAY_MS)],
            );

            return $this->sendResponse($message, $ctx);
        }

        return $this->renderWindow($form, $message, $ctx);
    }

    #[Route('/undo/{id}', name: 'mail_undo', methods: ['POST'])]
    public function undo(Request $request, Message $message): Response
    {
        $this->assertOwnership($message);

        $message->setCancelled(true);
        $this->em->flush();

        $ctx  = $this->composeContext($request);
        $form = $this->composeForm($message, $ctx);
        $form->get('account')->setData($this->senderToken($message->getAccount()));
        $this->hydrateAddressFields($form, $message);

        // Inline: pull the message back out of the thread and re-open the
        // editor where it was. Dock: the original toast + re-docked window.
        if (true === $ctx['inline']) {
            return $this->turboStream('compose/_inline_undo.stream.html.twig', [
                'form'         => $form,
                'message'      => $message,
                'thread'       => $ctx['thread'],
                'inline'       => true,
                'frame'        => $ctx['frame'],
                'urlParams'    => $this->urlParams($ctx),
                'threadEntity' => $message->getThread(),
            ]);
        }

        return $this->render('compose/_undo_toast.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    /**
     * Inline sends skip the toast: the message is appended to the open thread
     * and the reply bar becomes a countdown the user can click to cancel.
     *
     * @param array{inline: bool, frame: string, thread: int|null} $ctx
     */
    private function sendResponse(Message $message, array $ctx): Response
    {
        if (false === $ctx['inline']) {
            return $this->turboStream('compose/_send_toast.html.twig', [
                'message' => $message,
            ]);
        }

        return $this->turboStream('compose/_inline_send.stream.html.twig', [
            'message' => $message,
            'thread'  => $message->getThread(),
            'undoUrl' => $this->generateUrl(
                'app_compose_mail_undo',
                $this->urlParams($ctx) + ['id' => $message->getId()],
            ),
            'delay'   => self::SEND_DELAY_MS,
        ]);
    }

    /**
     * The conversation row for a draft, as its own turbo-frame. Used to put
     * the row back after the in-place editor closes, and to materialise the
     * row for a draft the autosave created while the reply box was open.
     */
    #[Route('/draft-row/{id}', name: 'draft_row', methods: ['GET'])]
    public function draftRow(Message $message): Response
    {
        $this->assertOwnership($message);

        return $this->render('mail/_thread_message.html.twig', [
            'message'  => $message,
            'expanded' => false,
        ]);
    }

    /**
     * Attach files to a draft. The window forces a save before uploading, so
     * there is always a Message to hang the parts off; the sending side
     * already turns MessageParts into MIME attachments.
     *
     * Answers with the attachment strip for the window to swap in.
     */
    #[Route('/attachments/{id}', name: 'attachments_add', methods: ['POST'])]
    public function addAttachments(Request $request, Message $message): Response
    {
        $this->assertDraft($message);

        $account = $message->getAccount();
        $files   = array_filter(
            $request->files->all('files'),
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        );

        // Nothing arrived at all. Almost always post_max_size: PHP discards the
        // whole body, so $_FILES is empty and there is no per-file error to
        // read — silence here is what made an oversized upload look like a
        // no-op instead of a refusal.
        if (0 === count($files)) {
            return $this->uploadError('Upload too large');
        }

        // Everything is checked before anything is stored, so a rejected file
        // cannot leave the ones before it attached.
        foreach ($files as $file) {
            $error = $this->attachmentError($file);

            if (null !== $error) {
                return $this->uploadError($error);
            }
        }

        foreach ($files as $file) {
            // Bucketed like synced attachments: account / mailbox (0 where the
            // account has none) / message. Drafts have no UID, so the message
            // id keeps one draft's files out of another's directory.
            $storagePath = $this->attachmentStorage->store(
                (int) $account->getId(),
                (int) ($message->getMailbox()?->getId() ?? 0),
                (int) $message->getId(),
                (string) $file->getClientOriginalName(),
                (string) file_get_contents($file->getPathname()),
            );

            $part = new MessagePart()
                ->setMessage($message)
                // Guessed from the bytes, not from the client's header — this
                // value comes back out as a Content-Type on download.
                ->setContentType($file->getMimeType() ?? 'application/octet-stream')
                ->setFilename(basename((string) $file->getClientOriginalName()))
                ->setDisposition('attachment')
                ->setSize($file->getSize())
                ->setStoragePath($storagePath)
                ->setIsInline(false);

            $message->addMessagePart($part);
            $this->em->persist($part);
        }

        $this->syncAttachmentFlag($message);
        $this->em->flush();

        return $this->render('compose/_attachments.html.twig', ['message' => $message]);
    }

    /**
     * Why this upload cannot be attached, or null when it can. Short enough to
     * show in the window's status line.
     */
    private function attachmentError(UploadedFile $file): ?string
    {
        if (UPLOAD_ERR_INI_SIZE === $file->getError() || UPLOAD_ERR_FORM_SIZE === $file->getError()) {
            return 'File too large';
        }

        if (false === $file->isValid()) {
            return 'Upload failed';
        }

        if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            return sprintf('File too large (max %d MB)', intdiv(self::MAX_ATTACHMENT_BYTES, 1024 * 1024));
        }

        return null;
    }

    /**
     * Plain text so the window can show the reason as-is; the HTML answer of a
     * successful upload is the attachment strip.
     */
    private function uploadError(string $message): Response
    {
        return new Response(
            $message,
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }

    #[Route('/attachment/{id}/remove', name: 'attachment_remove', methods: ['POST'])]
    public function removeAttachment(MessagePart $part): Response
    {
        $message = $part->getMessage();

        $this->assertDraft($message);

        $this->attachmentStorage->delete($part->getStoragePath());

        $message->removeMessagePart($part);
        $this->em->remove($part);

        $this->syncAttachmentFlag($message);
        $this->em->flush();

        return $this->render('compose/_attachments.html.twig', ['message' => $message]);
    }

    /**
     * Discard: the trash button in the compose window really deletes the
     * draft instead of just closing the window on top of it.
     */
    #[Route('/discard/{id}', name: 'discard', methods: ['POST'])]
    public function discard(Request $request, Message $message): Response
    {
        $this->assertOwnership($message);

        if (false === $message->isDraft() || null !== $message->getSentAt()) {
            throw $this->createAccessDeniedException('Only unsent drafts can be discarded.');
        }

        $ctx       = $this->composeContext($request);
        $messageId = $message->getId();
        $thread    = $message->getThread();

        $this->deleteStoredAttachments($message);
        $this->em->remove($message);

        // Recount from the association, never from the stored counter — it
        // drifts, and the thread cascades removes to every message in it.
        // An emptied thread is left in place: harmless, and the sync layer
        // reuses it if the conversation comes back.
        $remaining = 0;

        if (null !== $thread) {
            // Off the collection as well as out of the database: sync() reads
            // the thread's labels off the messages it still holds, and a
            // deleted draft left in there kept the thread in the Drafts list.
            $thread->removeMessage($message);
            $remaining = $thread->getMessages()->count();
            $thread->setMessageCount($remaining);
        }

        $this->em->flush();

        if (null !== $thread) {
            $this->threadLabelSynchronizer->sync($thread);
            $this->em->flush();
        }

        return $this->turboStream('compose/_discard.stream.html.twig', [
            'messageId' => $messageId,
            'frame'     => $ctx['frame'],
            'inline'    => $ctx['inline'],
            'threadId'  => $this->rowToDrop($thread, $messageId, $remaining, $request),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * The thread whose list row the discard should take with it, or null to
     * leave every row standing.
     *
     * List rows stand for threads, so an emptied thread loses its row wherever
     * it is shown. The Drafts list is the other case: its rows are there
     * because of a draft, so a conversation that just lost its last one drops
     * out of that view even though the thread lives on. Which view is asking
     * comes from the window (`scope`), because the same thread must keep its
     * row in the Inbox.
     */
    private function rowToDrop(?MessageThread $thread, ?int $discardedId, int $remaining, Request $request): ?int
    {
        if (null === $thread) {
            return null;
        }

        if (0 === $remaining) {
            return $thread->getId();
        }

        if ('drafts' !== $request->query->get('scope')) {
            return null;
        }

        foreach ($thread->getMessages() as $message) {
            // The discarded message can still sit in the loaded collection.
            if ($message->getId() === $discardedId) {
                continue;
            }

            if (true === $message->isDraft()) {
                return null;
            }
        }

        return $thread->getId();
    }

    /**
     * Where this compose window lives. The dock is the default; the thread
     * view passes ?frame=compose_inline&thread={id} on every URL it hands to
     * the client (open, autosave, send, undo) so the round trip stays
     * self-describing — the autosave fetch sends no Turbo-Frame header.
     *
     * `replyTo` is the message being answered. It has to survive the round
     * trip because the first autosave POSTs to /compose/draft with no id, so
     * the server builds a brand new Message that would otherwise have lost
     * the thread and In-Reply-To the reply was created with.
     *
     * @return array{inline: bool, frame: string, thread: int|null, replyTo: int|null}
     */
    private function composeContext(Request $request): array
    {
        $frame = (string) $request->query->get('frame', self::DOCK_FRAME);

        // compose_inline is the reply box at the foot of the thread;
        // compose_draft_{id} is a draft being edited in place, in its own row.
        $inline = 1 === preg_match('/^compose_(inline|draft_\d+)$/', $frame);

        return [
            'inline'  => $inline,
            'frame'   => $inline ? $frame : self::DOCK_FRAME,
            'thread'  => $request->query->has('thread') ? $request->query->getInt('thread') : null,
            'replyTo' => $request->query->has('reply_to') ? $request->query->getInt('reply_to') : null,
        ];
    }

    /**
     * Query params to bake into the draft/send URLs the window reports back.
     *
     * @param array{inline: bool, frame: string, thread: int|null, replyTo: int|null} $ctx
     *
     * @return array<string, int|string>
     */
    private function urlParams(array $ctx): array
    {
        $params = [];

        if (true === $ctx['inline']) {
            $params['frame'] = $ctx['frame'];

            if (null !== $ctx['thread']) {
                $params['thread'] = $ctx['thread'];
            }
        }

        if (null !== $ctx['replyTo']) {
            $params['reply_to'] = $ctx['replyTo'];
        }

        return $params;
    }

    /**
     * Re-attach a freshly created draft to the conversation it answers.
     *
     * @param array{inline: bool, frame: string, thread: int|null, replyTo: int|null} $ctx
     */
    private function applyReplyContext(Message $message, array $ctx): void
    {
        if (null !== $message->getThread() || null === $ctx['replyTo']) {
            return;
        }

        $original = $this->messageRepository->find($ctx['replyTo']);

        if (null === $original || $original->getAccount()->getUsr() !== $this->getUser()) {
            return;
        }

        $message->setThread($original->getThread());

        if ([] === ($message->getInReplyTo() ?? [])) {
            $references = array_merge(
                $original->getReferences() ?? [],
                array_filter([$original->getMessageId()]),
            );

            $message
                ->setInReplyTo(array_filter([$original->getMessageId()]))
                ->setReferences(array_values(array_unique($references)));
        }
    }

    /**
     * Inline windows get their own form name so their DOM ids can't collide
     * with a dock window open at the same time (compose_inline_subject vs
     * compose_subject). The CSRF token id is shared, so tokens interchange.
     *
     * @param array{inline: bool, frame: string, thread: int|null} $ctx
     * @param list<string>                                         $groups
     */
    private function composeForm(Message $message, array $ctx, array $groups = ['Default']): FormInterface
    {
        $options = [
            'user'              => $this->getUser(),
            'validation_groups' => $groups,
        ];

        if (false === $ctx['inline']) {
            return $this->createForm(ComposeType::class, $message, $options);
        }

        return $this->container->get('form.factory')
            ->createNamed(self::INLINE_FORM, ComposeType::class, $message, $options);
    }

    /**
     * @param array{inline: bool, frame: string, thread: int|null} $ctx
     */
    private function renderWindow(FormInterface $form, Message $message, array $ctx, array $extra = []): Response
    {
        return $this->render('compose/_window.html.twig', $extra + [
            'form'      => $form,
            'message'   => $message,
            'inline'    => $ctx['inline'],
            'frame'     => $ctx['frame'],
            'thread'    => $ctx['thread'],
            'urlParams' => $this->urlParams($ctx),
        ]);
    }

    /**
     * The user's own writing as plain text: everything before the quoted
     * original (marked with data-quoted by buildQuotedHtml). Drives the draft
     * snippet in the conversation and the message list.
     */
    private function plainTextBody(?string $html): ?string
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

    private function turboStream(string $template, array $params): Response
    {
        return $this->render($template, $params, new Response(
            headers: ['Content-Type' => 'text/vnd.turbo-stream.html'],
        ));
    }

    /**
     * Submitted From token → the sending account, falling back to the
     * message's current account, then the user's default.
     */
    private function resolveAccount(FormInterface $form, Message $message): ?Account
    {
        $parsed = $this->parseSenderToken($form->get('account')->getData());

        if (null !== $parsed) {
            return $parsed[0];
        }

        return $message->getAccount() ?? $this->defaultAccount();
    }

    /**
     * The exact From address the user picked, falling back to the account's
     * display address when the token is absent or points elsewhere.
     */
    private function resolveFromAddress(FormInterface $form, Account $account): string
    {
        $parsed = $this->parseSenderToken($form->get('account')->getData());

        if (null !== $parsed && $parsed[0] === $account) {
            return $parsed[1];
        }

        return $account->getDisplayAddress() ?? $account->getEmail() ?? '';
    }

    /**
     * @return array{0: Account, 1: string}|null
     */
    private function parseSenderToken(mixed $token): ?array
    {
        if (false === is_string($token) || false === str_contains($token, '|')) {
            return null;
        }

        [$id, $address] = explode('|', $token, 2);
        $account = $this->accountRepository->find((int) $id);

        if (
            null === $account
            || $account->getUsr() !== $this->getUser()
            || false === (bool) $account->isActive()
        ) {
            return null;
        }

        return [$account, $address];
    }

    private function senderToken(Account $account): string
    {
        return sprintf('%d|%s', $account->getId(), $account->getDisplayAddress() ?? $account->getEmail() ?? '');
    }

    private function defaultAccount(): ?Account
    {
        $account = $this->accountRepository->findOneBy([
            'usr' => $this->getUser(),
            'isActive' => true,
            'isPrimary' => true,
        ]);

        if (null !== $account && $account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (null !== $account) {
            return $account;
        }

        return $this->accountRepository->findOneBy([
            'usr' => $this->getUser(),
            'isActive' => true,
        ]);
    }

    /**
     * Wire the message to its From account: Drafts label of that account,
     * plus the physical Drafts folder for plain-IMAP accounts (Gmail
     * accounts have no mailboxes — mailbox stays null).
     *
     * Switching the From account on an existing draft moves it: Drafts
     * labels of other accounts are dropped.
     */
    private function applyAccount(Message $message, Account $account): void
    {
        $message->setAccount($account);

        $draftsLabel = $this->labelResolver->systemLabel(LabelRole::Drafts, $account);

        foreach ($message->getLabels() as $label) {
            if (LabelRole::Drafts === $label->role && $label !== $draftsLabel) {
                $message->removeLabel($label);
            }
        }

        $message->addLabel($draftsLabel);

        $message->setMailbox($draftsLabel->bindingFor($account)?->mailbox);
    }

    /**
     * Read compose_to[], compose_cc[], compose_bcc[] from the Tom Select
     * fields and write them onto the Message, replacing whatever the
     * Symfony CollectionType may have bound.
     */
    private function applyAddressFields(FormInterface $form, Message $message): void
    {
        $extract = static function (string $field) use ($form): array {
            /** @var Collection $contacts */
            $contacts = $form->get($field)->getData();

            if (empty($contacts)) {
                return [];
            }

            $result = [];
            foreach ($contacts as $contact) {
                $result[] = [
                    'name' => $contact->getDisplayName() ?? '',
                    'address' => $contact->getEmail() ?? '',
                ];
            }

            return array_values(array_filter($result, static fn(array $a): bool => $a['address'] !== ''));
        };

        $to = $extract('toAddresses');
        $cc = $extract('ccAddresses');
        $bcc = $extract('bccAddresses');

        if (!empty($to)) {
            $message->setToAddresses($to);
        }
        if (!empty($cc)) {
            $message->setCcAddresses($cc);
        }
        if (!empty($bcc)) {
            $message->setBccAddresses($bcc);
        }
    }

    private function buildReply(Message $original, bool $replyAll, Account $account): Message
    {
        $to = [[
            'name' => $original->getFromName() ?? '',
            'address' => $original->getFromAddress() ?? '',
        ]];

        $cc = [];
        if (true === $replyAll) {
            $ownAddresses = $account->getOwnedAddresses();
            $candidates = array_merge(
                $original->getToAddresses() ?? [],
                $original->getCcAddresses() ?? [],
            );
            foreach ($candidates as $addr) {
                if (false === in_array(strtolower($addr['address'] ?? ''), $ownAddresses, true)) {
                    $cc[] = $addr;
                }
            }
        }

        $subject = $this->prefixSubject('Re', $original->getSubject());

        $references = array_merge(
            $original->getReferences() ?? [],
            array_filter([$original->getMessageId()]),
        );

        $quotedBody = $this->buildQuotedHtml($original, 'reply');

        $draft = new Message()
            ->setAccount($account)
            ->setSubject($subject)
            ->setToAddresses($to)
            ->setCcAddresses($cc)
            ->setBodyHtml($quotedBody)
            ->setInReplyTo(array_filter([$original->getMessageId()]))
            ->setReferences(array_values(array_unique($references)))
            ->setHasAttachments(false)
            ->setCreatedAt(new DateTimeImmutable())
            ->setUpdatedAt(new DateTimeImmutable());

        if (null !== $original->getThread()) {
            $draft->setThread($original->getThread());
        }

        return $draft;
    }

    private function buildForward(Message $original): Message
    {
        $subject = $this->prefixSubject('Fwd', $original->getSubject());
        $quotedBody = $this->buildQuotedHtml($original, 'forward');

        return new Message()
            ->setAccount($original->getAccount())
            ->setSubject($subject)
            ->setToAddresses([])
            ->setBodyHtml($quotedBody)
            ->setHasAttachments(false)
            ->setCreatedAt(new DateTimeImmutable())
            ->setUpdatedAt(new DateTimeImmutable());
    }

    private function prefixSubject(string $prefix, ?string $subject): string
    {
        $subject = trim($subject ?? '');

        if ($subject === '') {
            return $prefix . ': ';
        }

        $pattern = '/^(re|fwd?)\s*:\s*/i';
        if (preg_match($pattern, $subject)) {
            if (strtolower($prefix) === 're') {
                return $subject;
            }
        }

        return $prefix . ': ' . $subject;
    }

    // NOTE: keep YOUR existing buildQuotedHtml() body — only the callers
    // changed. The reply branch below is reconstructed and may differ from
    // your version; the forward branch is verbatim.
    private function buildQuotedHtml(Message $original, string $mode): string
    {
        $dateStr = $original->getReceivedAt() ? $original->getReceivedAt()->format('D, M j, Y \a\t g:i a') : '';
        $fromName = htmlspecialchars($original->getFromName() ?? '', ENT_QUOTES, 'UTF-8');
        $fromAddr = htmlspecialchars($original->getFromAddress() ?? '', ENT_QUOTES, 'UTF-8');
        $fromLine = $fromName !== '' ? "{$fromName} &lt;{$fromAddr}&gt;" : $fromAddr;

        $bodyHtml = trim($original->getBodyHtml() ?? '');
        $bodyText = trim($original->getBodyText() ?? '');
        $innerBody = $bodyHtml !== '' ? $bodyHtml : nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));

        // data-quoted marks the whole quoted block — attribution line
        // included — so the editor can collapse it and the autosave guard can
        // tell the user's own writing from the mail they are answering.
        if ('reply' === $mode) {
            return <<<HTML
                <p><br></p>
                <div data-quoted="1">
                    <div style="font-size:0.85em;color:#555;margin-bottom:0.25em">
                        On {$dateStr}, {$fromLine} wrote:
                    </div>
                    <blockquote style="border-left:2px solid #e0e0e0;margin:0;padding-left:0.75em;color:#555">
                        {$innerBody}
                    </blockquote>
                </div>
                HTML;
        }

        $subjectLine = htmlspecialchars($original->getSubject() ?? '', ENT_QUOTES, 'UTF-8');
        $toLine = implode(', ', array_map(
            static fn(array $a) => htmlspecialchars(
                ($a['name'] ? $a['name'] . ' <' . $a['address'] . '>' : $a['address']),
                ENT_QUOTES,
                'UTF-8',
            ),
            $original->getToAddresses() ?? [],
        ));

        return <<<HTML
            <p><br></p>
            <div data-quoted="1" style="border-top:1px solid #e0e0e0;padding-top:0.75em;margin-top:0.5em;font-size:0.85em;color:#555">
                <p style="margin:0 0 0.25em"><strong>---------- Forwarded message ----------</strong></p>
                <p style="margin:0 0 0.1em"><strong>From:</strong> {$fromLine}</p>
                <p style="margin:0 0 0.1em"><strong>Date:</strong> {$dateStr}</p>
                <p style="margin:0 0 0.1em"><strong>Subject:</strong> {$subjectLine}</p>
                <p style="margin:0 0 0.75em"><strong>To:</strong> {$toLine}</p>
                <div>{$innerBody}</div>
            </div>
            HTML;
    }

    private function persistDraft(Message $message, Account $account): void
    {
        $now = new DateTimeImmutable();

        $message
            ->setFromAddress($account->getEmail())
            ->setFromName($account->getName())
            ->addFlag(MessageFlag::DRAFT)
            ->setSeenAt($message->getSeenAt() ?? $now)
            ->setUpdatedAt($now);

        // Autosave runs on every keystroke: hardcoding false here used to wipe
        // the flag off a draft that had files attached to it.
        $this->syncAttachmentFlag($message);

        // Only the sync layer sanitizes bodies, so without this a draft (and
        // the message it becomes) renders blank in the conversation until the
        // sent copy comes back from IMAP.
        $this->bodySanitizer->sanitize($message);
        $message->setBodyText($this->plainTextBody($message->getBodyHtml()));

        $this->em->persist($message);

        if (null === $message->getThread()) {
            // Uses in_reply_to / references, so reply drafts land on the
            // original thread; fresh composes get a new one.
            $this->threader->assignThread($message, $account);
        }
        $this->threader->resyncDraftThreadSubject($message);

        $thread = $message->getThread();

        if (null !== $thread) {
            // The threader only sets the owning side, so the thread does not
            // know about this message yet — and sync() derives a thread's
            // labels from the messages it can see. Without this it saw none of
            // them, stripped the Drafts label the threader had just copied
            // over, and the new draft never turned up in the Drafts list.
            $thread->addMessage($message);
            $this->threadLabelSynchronizer->sync($thread);
        }

        $this->em->flush();
    }

    /**
     * Attachments may only be touched on a draft the user owns that has not
     * gone out yet — a sent message's parts are a record of what was sent.
     */
    private function assertDraft(?Message $message): void
    {
        if (null === $message) {
            throw $this->createNotFoundException();
        }

        $this->assertOwnership($message);

        if (false === $message->isDraft() || null !== $message->getSentAt()) {
            throw $this->createAccessDeniedException('Only unsent drafts can be edited.');
        }
    }

    /** Keep the attachment flag in step with the parts actually stored. */
    private function syncAttachmentFlag(Message $message): void
    {
        $hasAttachments = false;

        foreach ($message->getMessageParts() as $part) {
            if (false === (bool) $part->isInline()) {
                $hasAttachments = true;

                break;
            }
        }

        $message->setHasAttachments($hasAttachments);
    }

    /** Drop the files a discarded draft uploaded; the rows cascade. */
    private function deleteStoredAttachments(Message $message): void
    {
        foreach ($message->getMessageParts() as $part) {
            $this->attachmentStorage->delete($part->getStoragePath());
        }
    }

    private function assertOwnership(Message $message): void
    {
        $account = $message->getAccount();

        if (null === $account || $account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Inverse of applyAddressFields(): turn the stored {name, address} JSON
     * back into the Contact entities the autocomplete field renders as
     * selected options. Addresses typed freehand may have no contact row yet,
     * so those are harvested on the spot — the field cannot represent an
     * address that is not a Contact.
     */
    private function hydrateAddressFields(FormInterface $form, Message $message): void
    {
        $groups = [
            'toAddresses'  => $message->getToAddresses() ?? [],
            'ccAddresses'  => $message->getCcAddresses() ?? [],
            'bccAddresses' => $message->getBccAddresses() ?? [],
        ];

        $pending = [];

        foreach ($groups as $addresses) {
            foreach ($addresses as $addr) {
                $email = mb_strtolower(trim($addr['address'] ?? ''));

                if ($email === '') {
                    continue;
                }

                $pending[$email] = ['email' => $email, 'name' => $addr['name'] ?? null];
            }
        }

        if (count($pending) === 0) {
            return;
        }

        $user     = $this->getUser();
        $contacts = $this->contactRepository->findByEmailsForUser($user, array_keys($pending));

        $missing = array_values(array_filter(
            $pending,
            static fn(array $addr): bool => false === array_key_exists($addr['email'], $contacts),
        ));

        // Only upsert what is genuinely absent — upsertBatch bumps frequency,
        // and merely opening a draft is not a new contact signal.
        if (count($missing) > 0) {
            $this->contactRepository->upsertBatch($user, $missing);
            $contacts = $this->contactRepository->findByEmailsForUser($user, array_keys($pending));
        }

        foreach ($groups as $field => $addresses) {
            $selected = [];

            foreach ($addresses as $addr) {
                $email = mb_strtolower(trim($addr['address'] ?? ''));

                if (true === array_key_exists($email, $contacts)) {
                    $selected[] = $contacts[$email];
                }
            }

            $form->get($field)->setData($selected);
        }
    }
}
