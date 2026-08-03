<?php

namespace App\Controller\Mail;

use App\Domain\Enum\Integration\Capability;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Form\ComposeType;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\DraftAddressFields;
use App\Service\Mail\DraftAttachmentService;
use App\Service\Mail\DraftPersister;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\ReplyDraftBuilder;
use App\Service\Mail\SenderResolver;
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
 *
 * What a draft *is* — how it is addressed, saved, threaded, attached to and
 * announced — lives in App\Service\Mail, next to the JMAP writer that has to
 * agree with it. What is left here is the part that only makes sense in front
 * of a browser: which frame the window is in, which template answers, and who
 * is allowed to ask.
 */
#[Route('/compose', name: 'app_compose_')]
class ComposeController extends AbstractController
{
    private const string DOCK_FRAME   = 'compose_dock';
    private const string INLINE_FRAME = 'compose_inline';
    private const string INLINE_FORM  = 'compose_inline';

    /**
     * Per-file ceiling for compose attachments. Public because the compose
     * window reads it too, to refuse an oversized file before it is uploaded.
     *
     * The rule itself belongs to DraftAttachmentService, which enforces it;
     * this is the name the window and the file picker already know it by.
     */
    public const int MAX_ATTACHMENT_BYTES = DraftAttachmentService::MAX_BYTES;

    /** Matches the DelayStamp on the send job — the cancel window. */
    private const int SEND_DELAY_MS = 10_000;

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly MessageRepository       $messageRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly MessageBusInterface     $bus,
        private readonly ThreadLabelSynchronizer $threadLabelSynchronizer,
        private readonly IntegrationRepository   $integrationRepository,
        private readonly ReplyDraftBuilder       $replyDrafts,
        private readonly DraftPersister          $drafts,
        private readonly DraftAttachmentService  $attachments,
        private readonly DraftAddressFields      $addressFields,
        private readonly SenderResolver          $senders,
        private readonly MailChangeRecorder      $changes,
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

            // A fresh install has no account, and Message::$account is not
            // nullable — so composing used to fatal at exactly the moment the
            // app is emptiest. Say what is missing instead.
            if (null === $account) {
                return $this->render('compose/_no_account.html.twig', [], new Response(null, Response::HTTP_OK));
            }

            $message = new Message();
            $message->account = $account;

        } else {
            $this->assertOwnership($message);
            $account = $message->account;
        }

        $form = $this->composeForm($message, $ctx);
        $form->get('account')->setData($this->senders->token($account));
        $this->addressFields->hydrate($form, $message, $this->getUser());

        return $this->renderWindow($form, $message, $ctx);
    }

    #[Route('/reply/{id}', name: 'reply', methods: ['GET'])]
    public function reply(Request $request, Message $original): Response
    {
        $this->assertOwnership($original);

        $ctx            = $this->composeContext($request);
        $ctx['replyTo'] = $original->id;
        $account        = $original->account ?? $this->defaultAccount();
        $draft          = $this->replyDrafts->reply($original, $account);

        $form = $this->composeForm($draft, $ctx);
        $form->get('account')->setData($this->senders->token($account));
        $this->addressFields->hydrate($form, $draft, $this->getUser());

        return $this->renderWindow($form, $draft, $ctx);
    }

    #[Route('/reply-all/{id}', name: 'reply_all', methods: ['GET'])]
    public function replyAll(Request $request, Message $original): Response
    {
        $this->assertOwnership($original);

        $ctx            = $this->composeContext($request);
        $ctx['replyTo'] = $original->id;
        $account        = $original->account ?? $this->defaultAccount();
        $draft          = $this->replyDrafts->reply($original, $account, replyAll: true);

        $form = $this->composeForm($draft, $ctx);
        $form->get('account')->setData($this->senders->token($account));
        $this->addressFields->hydrate($form, $draft, $this->getUser());

        return $this->renderWindow($form, $draft, $ctx);
    }

    #[Route('/forward/{id}', name: 'forward', methods: ['GET'])]
    public function forwardMessage(Request $request, Message $original): Response
    {
        $this->assertOwnership($original);

        $ctx     = $this->composeContext($request);
        $account = $original->account ?? $this->defaultAccount();
        $draft   = $this->replyDrafts->forward($original);

        $form = $this->composeForm($draft, $ctx);
        $form->get('account')->setData($this->senders->token($account));
        $this->addressFields->hydrate($form, $draft, $this->getUser());

        return $this->renderWindow($form, $draft, $ctx);
    }

    #[Route('/draft', name: 'form_new', methods: ['POST'])]
    #[Route('/draft/{id}', name: 'form_edit', methods: ['POST'])]
    public function draft(Request $request, ?Message $message = null): Response
    {
        if (null === $message) {
            $message = new Message();
            $message->account = $this->defaultAccount();
        } else {
            $this->assertOwnership($message);
        }

        $ctx  = $this->composeContext($request);
        $this->applyReplyContext($message, $ctx);
        $form = $this->composeForm($message, $ctx);

        $form->handleRequest($request);

        // Apply Tom Select address fields (override whatever CollectionType bound)
        $this->addressFields->apply($form, $message);

        if ($form->isSubmitted() && $form->isValid()) {
            $token   = $form->get('account')->getData();
            $account = $this->senders->accountFor($token, $this->getUser())
                ?? $message->account
                ?? $this->defaultAccount();

            if (null === $account) {
                throw $this->createNotFoundException('No active account to compose from.');
            }

            $message->fromAddress = $this->senders->addressFor($token, $account, $this->getUser());
            $message->fromName = $account->name ?? '';

            $this->drafts->save($message, $account);

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
        $this->addressFields->apply($form, $message);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $message->sentAt) {
                return $this->sendResponse($message, $ctx);
            }

            $token   = $form->get('account')->getData();
            $account = $this->senders->accountFor($token, $this->getUser())
                ?? $message->account
                ?? $this->defaultAccount();

            if (null === $account) {
                throw $this->createNotFoundException('No active account to send from.');
            }

            $message->fromAddress = $this->senders->addressFor($token, $account, $this->getUser());
            $message->fromName = $account->name ?? '';

            $this->drafts->save($message, $account);

            $this->bus->dispatch(
                new SendMessageMessage($message->id),
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

        // Deliberately not recorded: `cancelled` is a flag SendMessageHandler
        // reads and clears, and EmailMapper publishes nothing derived from it.
        // The message is still the same unsent draft it was a moment ago, so
        // waking every client for it would be a push about nothing changing.
        $message->cancelled = true;
        $this->em->flush();

        $ctx  = $this->composeContext($request);
        $form = $this->composeForm($message, $ctx);
        $form->get('account')->setData($this->senders->token($message->account));
        $this->addressFields->hydrate($form, $message, $this->getUser());

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
                'threadEntity' => $message->thread,
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
            'thread'  => $message->thread,
            'undoUrl' => $this->generateUrl(
                'app_compose_mail_undo',
                $this->urlParams($ctx) + ['id' => $message->id],
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

        $files = array_values(array_filter(
            $request->files->all('files'),
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));

        // Nothing arrived at all. Almost always post_max_size: PHP discards the
        // whole body, so $_FILES is empty and there is no per-file error to
        // read — silence here is what made an oversized upload look like a
        // no-op instead of a refusal.
        if (0 === count($files)) {
            return $this->uploadError('Upload too large');
        }

        $refusal = $this->attachments->attach($message, $files);

        if (null !== $refusal) {
            return $this->uploadError($refusal);
        }

        return $this->render('compose/_attachments.html.twig', ['message' => $message]);
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
        $message = $part->message;

        $this->assertDraft($message);

        $this->attachments->remove($part);

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

        if (false === $message->isDraft() || null !== $message->sentAt) {
            throw $this->createAccessDeniedException('Only unsent drafts can be discarded.');
        }

        $ctx       = $this->composeContext($request);
        $messageId = $message->id;
        $thread    = $message->thread;
        $account   = $message->account;

        $this->attachments->deleteStoredFiles($message);
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
            $remaining = $thread->messages->count();
            $thread->messageCount = $remaining;
        }

        // A genuine destroy, unlike Email/set destroy — that one moves to Trash
        // and keeps the row, this one takes the id away. Ahead of the flush
        // because the ids still exist; recording only persists, so the log rows
        // go out with the removal itself.
        $this->changes->emailDestroyed((int) $account->id, (string) $messageId, $thread);

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
            return $thread->id;
        }

        if ('drafts' !== $request->query->get('scope')) {
            return null;
        }

        foreach ($thread->messages as $message) {
            // The discarded message can still sit in the loaded collection.
            if ($message->id === $discardedId) {
                continue;
            }

            if (true === $message->isDraft()) {
                return null;
            }
        }

        return $thread->id;
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
        }

        // Not inline-only: below md a reply opens in the dock instead of in
        // the thread (see compose--frame-target), and the window still has to
        // know which conversation it belongs to so its draft row lands there.
        if (null !== $ctx['thread']) {
            $params['thread'] = $ctx['thread'];
        }

        if (null !== $ctx['replyTo']) {
            $params['reply_to'] = $ctx['replyTo'];
        }

        return $params;
    }

    /**
     * Re-attach a freshly created draft to the conversation it answers.
     *
     * The lookup is the controller's half of it: `replyTo` is a number that
     * came back from the browser, so the message it names is only the original
     * if the user owns it.
     *
     * @param array{inline: bool, frame: string, thread: int|null, replyTo: int|null} $ctx
     */
    private function applyReplyContext(Message $message, array $ctx): void
    {
        if (null !== $message->thread || null === $ctx['replyTo']) {
            return;
        }

        $original = $this->messageRepository->find($ctx['replyTo']);

        if (null === $original || $original->account->usr !== $this->getUser()) {
            return;
        }

        $this->replyDrafts->linkToOriginal($message, $original);
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
            // Services this user can pull files out of. Download rather than
            // Browse is the test that matters: a service you can list but not
            // fetch from would open a picker that cannot attach anything.
            'pickerIntegrations' => $this->integrationRepository->findSupportingForUser(
                $this->getUser(),
                Capability::Download,
            ),
        ]);
    }

    private function turboStream(string $template, array $params): Response
    {
        return $this->render($template, $params, new Response(
            headers: ['Content-Type' => 'text/vnd.turbo-stream.html'],
        ));
    }

    private function defaultAccount(): ?Account
    {
        $account = $this->accountRepository->findOneBy([
            'usr' => $this->getUser(),
            'isActive' => true,
            'isPrimary' => true,
        ]);

        if (null !== $account && $account->usr !== $this->getUser()) {
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
     * Attachments may only be touched on a draft the user owns that has not
     * gone out yet — a sent message's parts are a record of what was sent.
     */
    private function assertDraft(?Message $message): void
    {
        if (null === $message) {
            throw $this->createNotFoundException();
        }

        $this->assertOwnership($message);

        if (false === $message->isDraft() || null !== $message->sentAt) {
            throw $this->createAccessDeniedException('Only unsent drafts can be edited.');
        }
    }

    private function assertOwnership(Message $message): void
    {
        $account = $message->account;

        if (null === $account || $account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}
