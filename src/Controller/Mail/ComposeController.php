<?php

namespace App\Controller\Mail;

use App\Controller\RendersTurboStreams;
use App\Domain\DTO\Mail\ComposeContext;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Mail\MessageRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Label\LabelChangePropagator;
use App\Service\Mail\ComposeFormFactory;
use App\Service\Mail\ComposeWindow;
use App\Service\Mail\DraftAddressFields;
use App\Service\Mail\DraftPersister;
use App\Service\Mail\InvalidScheduleException;
use App\Service\Mail\MessageEraser;
use App\Service\Mail\ReplyDraftBuilder;
use App\Service\Mail\ScheduledSendResolver;
use App\Service\Mail\SenderResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

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
    use RendersTurboStreams;

    /** Matches the DelayStamp on the send job. */
    private const int SEND_DELAY_MS = 10_000;

    /**
     * How long the cancel affordance is actually offered for — deliberately
     * SHORTER than the hold above, and that gap is the fix for a reproducible
     * bug rather than a comfort margin.
     *
     * The inline reply bar used to count the full ten seconds, so the last
     * click it accepted was at the very instant the worker picked the envelope
     * up. Cancelling at 9.9s then meant a POST whose UPDATE landed after the
     * send had begun: HTTP 200, "send cancelled", mail delivered. The dock's
     * toast never showed this because it happened to close at eight seconds,
     * which is where this number comes from — the two surfaces now share it
     * instead of one of them being accidentally safe.
     *
     * The two seconds are not the guarantee, they are the margin. The
     * guarantee is MessageRepository::claimForSend(): a cancel that loses
     * anyway — a slow request, a busy worker — is now TOLD it lost. This is
     * what keeps that path rare enough to be an edge case.
     */
    private const int CANCEL_WINDOW_MS = 8_000;

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly MessageRepository       $messageRepository,
        private readonly ComposeWindow           $window,
        private readonly ComposeFormFactory      $formFactory,
        private readonly MessageBusInterface     $bus,
        private readonly ReplyDraftBuilder       $replyDrafts,
        private readonly DraftPersister          $drafts,
        private readonly DraftAddressFields      $addressFields,
        private readonly SenderResolver          $senders,
        private readonly MessageEraser           $eraser,
        private readonly LabelChangePropagator   $labelChanges,
        private readonly TranslatorInterface     $translator,
        private readonly ScheduledSendResolver   $schedules,
    )
    {
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    #[Route('/edit/{id}', name: 'edit', methods: ['GET'])]
    public function compose(ComposeContext $ctx, ?Message $message = null): Response
    {
        if (null === $message) {
            $account = $this->window->defaultAccountFor($this->currentUser());

            // A fresh install has no account, and Message::$account is not
            // nullable — so composing used to fatal at exactly the moment the
            // app is emptiest. Say what is missing instead.
            if (null === $account) {
                return $this->render('compose/_no_account.html.twig', [], new Response(null, Response::HTTP_OK));
            }

            $message = new Message();
            $message->account = $account;

            // A blank message still starts signed. Seeded here rather than in
            // the browser so the body the editor opens with is the body an
            // autosave would store — a signature added client-side after
            // render is one the "has the user typed anything yet" guard
            // (minChars) would have to be taught to ignore.
            //
            // With an empty paragraph ABOVE it, and that paragraph is not
            // decoration: a body consisting only of the signature block has
            // nowhere to write, so the caret lands INSIDE the signature and
            // the first thing typed becomes part of it — and is then thrown
            // away by the next From switch, which replaces the block. Same
            // `<p><br></p>` ReplyDraftBuilder puts above a quote, for the same
            // reason.
            $message->bodyHtml = $this->window->signatureSeed($account);

        } else {
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
            $account = $message->account;
        }

        $form = $this->formFactory->create($message, $ctx, $this->currentUser());
        $form->get('account')->setData($this->senders->token($account));
        $this->addressFields->hydrate($form, $message, $this->getUser());

        return $this->renderWindow($form, $message, $ctx);
    }

    #[Route('/reply/{id}', name: 'reply', methods: ['GET'])]
    public function reply(ComposeContext $ctx, Message $original): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $original);

        $ctx     = $ctx->withReplyTo($original->id);
        $account = $original->account ?? $this->window->defaultAccountFor($this->currentUser());
        $draft   = $this->replyDrafts->reply($original, $account);

        $form = $this->formFactory->create($draft, $ctx, $this->currentUser());
        $form->get('account')->setData($this->senders->token($account));
        $this->addressFields->hydrate($form, $draft, $this->getUser());

        return $this->renderWindow($form, $draft, $ctx);
    }

    #[Route('/reply-all/{id}', name: 'reply_all', methods: ['GET'])]
    public function replyAll(ComposeContext $ctx, Message $original): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $original);

        $ctx     = $ctx->withReplyTo($original->id);
        $account = $original->account ?? $this->window->defaultAccountFor($this->currentUser());
        $draft   = $this->replyDrafts->reply($original, $account, replyAll: true);

        $form = $this->formFactory->create($draft, $ctx, $this->currentUser());
        $form->get('account')->setData($this->senders->token($account));
        $this->addressFields->hydrate($form, $draft, $this->getUser());

        return $this->renderWindow($form, $draft, $ctx);
    }

    #[Route('/forward/{id}', name: 'forward', methods: ['GET'])]
    public function forwardMessage(ComposeContext $ctx, Message $original): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $original);

        $account = $original->account ?? $this->window->defaultAccountFor($this->currentUser());
        $draft   = $this->replyDrafts->forward($original);

        // The window behaves differently for a forward — the caret belongs in
        // To (the quote IS the content; the missing piece is who gets it), and
        // the empty-body question would be asked about a body that is not
        // empty. The mode is how the client knows which case it is in.
        //
        // On the CONTEXT rather than as a render-time extra, and that is the
        // fix for a real bug: an extra is set by this action and lost by every
        // other render of the same window. Cancel a forward and the window
        // that came back was a plain new message, so sending it again asked
        // whether you meant to send an empty mail — about the forward in it.
        // The context rides in the URL, so it survives the trip.
        $ctx = $ctx->withMode(ComposeContext::MODE_FORWARD);

        $form = $this->formFactory->create($draft, $ctx, $this->currentUser());
        $form->get('account')->setData($this->senders->token($account));
        $this->addressFields->hydrate($form, $draft, $this->getUser());

        return $this->renderWindow($form, $draft, $ctx);
    }

    #[Route('/draft', name: 'form_new', methods: ['POST'])]
    #[Route('/draft/{id}', name: 'form_edit', methods: ['POST'])]
    public function draft(Request $request, ComposeContext $ctx, ?Message $message = null): Response
    {
        if (null === $message) {
            $message = new Message();
            $message->account = $this->window->defaultAccountFor($this->currentUser());
        } else {
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        }

        $this->applyReplyContext($message, $ctx);
        $form = $this->formFactory->create($message, $ctx, $this->currentUser());

        $form->handleRequest($request);

        // Apply Tom Select address fields (override whatever CollectionType bound)
        $this->addressFields->apply($form, $message);

        if ($form->isSubmitted() && $form->isValid()) {
            $token   = $form->get('account')->getData();
            $account = $this->senders->accountFor($token, $this->getUser())
                ?? $message->account
                ?? $this->window->defaultAccountFor($this->currentUser());

            if (null === $account) {
                throw $this->createNotFoundException('No active account to compose from.');
            }

            $this->drafts->save(
                $message,
                $account,
                $this->senders->addressFor($token, $account, $this->getUser()),
            );

            return $this->renderWindow($form, $message, $ctx, ['saved' => true]);
        }

        return $this->renderWindow($form, $message, $ctx);
    }

    #[Route('/send', name: 'mail_send', methods: ['POST'])]
    #[Route('/send/{id}', name: 'mail_send_draft', methods: ['POST'])]
    public function send(Request $request, ComposeContext $ctx, ?Message $message): Response
    {
        if (null === $message) {
            $message = new Message();
        } else {
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        }

        $this->applyReplyContext($message, $ctx);
        $form = $this->formFactory->create($message, $ctx, $this->currentUser(), ['Default', 'send']);

        $form->handleRequest($request);

        // Apply Tom Select address fields
        $this->addressFields->apply($form, $message);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $message->sentAt) {
                return $this->sendResponse($message, $ctx);
            }

            // Belt and braces behind the Count constraint in ComposeType.
            //
            // That rule proves the *selection* is not empty; this proves the
            // addresses about to go on the wire are addresses. They reach here
            // from Contact rows, which are only ever created from a validated
            // address — but a row can also arrive from a sync, an import or a
            // restore, and none of those are this form. Refusing here means
            // there is no path through the controller that dispatches a send
            // to something that is not an address.
            $unsendable = $this->window->firstUnsendableAddress($message);

            if (null !== $unsendable) {
                $form->get('toAddresses')->addError(new FormError(
                    $this->translator->trans(
                        'compose.recipient_invalid',
                        ['{{ address }}' => $unsendable],
                        'validators',
                    ),
                ));

                return $this->renderWindow($form, $message, $ctx);
            }

            $token   = $form->get('account')->getData();
            $account = $this->senders->accountFor($token, $this->getUser())
                ?? $message->account
                ?? $this->window->defaultAccountFor($this->currentUser());

            if (null === $account) {
                throw $this->createNotFoundException('No active account to send from.');
            }

            $this->drafts->save(
                $message,
                $account,
                $this->senders->addressFor($token, $account, $this->getUser()),
            );

            $this->bus->dispatch(
                new SendMessageMessage($message->id),
                [new DelayStamp(self::SEND_DELAY_MS)],
            );

            return $this->sendResponse($message, $ctx);
        }

        return $this->renderWindow($form, $message, $ctx);
    }

    /**
     * Send later: the same message, the same checks, a longer hold.
     *
     * Deliberately a near-twin of send() rather than a flag on it. The two
     * differ in exactly one line — the DelayStamp — and everything before it
     * has to be identical, because a scheduled send is a send: the same
     * recipient validation, the same firstUnsendableAddress() refusal, the same
     * DraftPersister::save(). A mail held until Monday that turns out on Monday
     * to have an unsendable address is a bounce nobody is watching for, three
     * days after the window that could have said so was closed.
     *
     * What it does NOT share is the send guard's fixed ten seconds. That delay
     * *is* the undo window (SEND_DELAY_MS); here the hold is whatever was
     * asked for, and the cancel affordance is the schedule itself — the toast
     * and the menu both offer it for as long as the mail has not left.
     */
    #[Route('/schedule', name: 'mail_schedule', methods: ['POST'])]
    #[Route('/schedule/{id}', name: 'mail_schedule_draft', methods: ['POST'])]
    public function schedule(Request $request, ComposeContext $ctx, ?Message $message): Response
    {
        if (null === $message) {
            $message = new Message();
        } else {
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        }

        $this->applyReplyContext($message, $ctx);
        $form = $this->formFactory->create($message, $ctx, $this->currentUser(), ['Default', 'send']);

        $form->handleRequest($request);

        $this->addressFields->apply($form, $message);

        if (false === $form->isSubmitted() || false === $form->isValid()) {
            return $this->renderWindow($form, $message, $ctx);
        }

        // Already gone. Nothing to hold, and nothing the user can be told that
        // is not already on their screen.
        if (null !== $message->sentAt) {
            return $this->sendResponse($message, $ctx);
        }

        // The time first, before anything is written: a schedule the server
        // will not accept must not leave a saved draft looking scheduled.
        try {
            $user = $this->getUser();

            $sendAt = $this->schedules->resolve(
                (string) $request->request->get('schedule_at', ''),
                $user instanceof User ? $user : null,
            );
        } catch (InvalidScheduleException $refusal) {
            $form->addError(new FormError($this->translator->trans(
                $refusal->translationKey,
                ['%days%' => ScheduledSendResolver::maxDays()],
            )));

            return $this->renderWindow($form, $message, $ctx);
        }

        $unsendable = $this->window->firstUnsendableAddress($message);

        if (null !== $unsendable) {
            $form->get('toAddresses')->addError(new FormError(
                $this->translator->trans(
                    'compose.recipient_invalid',
                    ['{{ address }}' => $unsendable],
                    'validators',
                ),
            ));

            return $this->renderWindow($form, $message, $ctx);
        }

        $token   = $form->get('account')->getData();
        $account = $this->senders->accountFor($token, $this->getUser())
            ?? $message->account
            ?? $this->window->defaultAccountFor($this->currentUser());

        if (null === $account) {
            throw $this->createNotFoundException('No active account to send from.');
        }

        $this->drafts->save(
            $message,
            $account,
            $this->senders->addressFor($token, $account, $this->getUser()),
        );

        // Set after the save, because the save is what mints the id for a draft
        // that did not have one — and this is the column EmailSubmission/get
        // reads as `pending` (with sentAt still null), so the JMAP clients see
        // the schedule the moment it exists.
        $message->submissionSendAt = $sendAt;

        // Re-scheduling a draft whose previous hold was cancelled: the flag
        // SendMessageHandler reads has to be down again, or this envelope is
        // swallowed by the last cancel. The durable half is cleared for the
        // same reason EmailSubmissionSetMethod clears it on re-submission —
        // this submission is pending, not canceled.
        $message->cancelled            = false;
        $message->submissionCancelledAt = null;

        // And the claim with them. A previous send that failed releases its own
        // claim, but a draft that was claimed and then rescheduled must not
        // arrive at SendMessageHandler already looking owned — claimForSend()
        // would refuse it and the hold would expire in silence.
        $message->sendClaimedAt = null;

        $this->em->flush();

        $this->bus->dispatch(
            new SendMessageMessage($message->id),
            [new DelayStamp($this->schedules->delayMs($sendAt))],
        );

        return $this->scheduleResponse($message, $ctx, $sendAt);
    }

    #[Route('/undo/{id}', name: 'mail_undo', methods: ['POST'])]
    public function undo(ComposeContext $ctx, Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        // Whether the cancel arrived in time is decided in one statement, and
        // this is the answer to it. Before, nothing asked: the flag was written
        // and the composer confirmed unconditionally, so a cancel that lost the
        // race to SendMessageHandler still said "send cancelled" while the mail
        // was on the wire. Clicking cancel at 9.9s of a ten-second hold did
        // exactly that, reproducibly.
        //
        // Note this must be answered BEFORE the message is re-read for the
        // form: a lost cancel means the send is in flight or done, and what the
        // user needs then is the truth, not their draft back.
        if (false === $this->callOffSend($message)) {
            return $this->sendAlreadyGoneResponse($ctx, $message);
        }

        $form = $this->formFactory->create($message, $ctx, $this->currentUser());
        $form->get('account')->setData($this->senders->token($message->account));
        $this->addressFields->hydrate($form, $message, $this->getUser());

        // Inline: pull the message back out of the thread and re-open the
        // editor where it was. Dock: the original toast + re-docked window.
        if (true === $ctx->inline) {
            return $this->renderTurboStream('compose/_inline_undo.stream.html.twig', [
                'form'         => $form,
                'message'      => $message,
                'ctx'          => $ctx,
                'threadEntity' => $message->thread,
                // The reopened editor is a full compose window, and the window
                // reads pickerIntegrations. The dock undo already learned this
                // (see below); the inline path renders the same window with
                // `only`, so without it the cancel flushed and then 500'd on
                // the reopen — the same silent draft-with-no-way-back the dock
                // undo used to leave, one frame over.
                'pickerIntegrations' => $this->window->pickerIntegrationsFor($this->currentUser()),
            ]);
        }

        // Every variable the window needs, and as a turbo-stream.
        //
        // This used to be a bare render() of form+message, which meant the
        // template reached _window.html.twig without `ctx` or
        // `pickerIntegrations` — and the window reads both. Under
        // strict_variables that is a 500; without it the integration picker
        // silently vanished and the frame/url params defaulted to the dock's.
        //
        // Either way the undo POST never returned a window, so the toast
        // faded and the draft was filed with no way back to it — while the
        // cancel above had already gone through. Cancelling a send has to
        // *reopen* what was being written, which is the whole point of it.
        return $this->renderTurboStream('compose/_dock_undo.stream.html.twig', [
            'form'    => $form,
            'message' => $message,
            'ctx'     => $ctx,
            'pickerIntegrations' => $this->window->pickerIntegrationsFor($this->currentUser()),
        ]);
    }

    /**
     * Call a scheduled send off from the list row, without opening anything.
     *
     * Same act as undo(), different surface — and the difference is the whole
     * point of it. undo() answers with the composer reopened, because it exists
     * to catch a send you regretted while the ten seconds were still running
     * and you were still looking at what you wrote. A hold set on Friday is
     * called off on Monday from a list of forty rows, where reopening the
     * window would be an interruption, not a rescue. So this one answers with
     * the row it was clicked on, redrawn.
     *
     * `type` and `draft_scope` come back with the request because the row is
     * the same partial rendered three ways: a thread row in a list, a bare
     * message row, and the Drafts variant that names the recipient rather than
     * the participants. Redrawing it from the message alone would swap one for
     * another under the pointer.
     */
    #[Route('/unschedule/{id}', name: 'mail_unschedule', methods: ['POST'])]
    public function unschedule(Request $request, Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        // Already gone: nothing is written — not even `cancelled`, which on a
        // message SendMessageHandler has finished with means nothing and reads
        // like a lie — and submissionSendAt stays, because on a sent message it
        // is the record of when the mail was due.
        //
        // Not gone: the same question undo() asks, asked the same way. The row
        // on screen was rendered some time ago, so the hold may have come due
        // while the user was looking at it; callOffSend() is what distinguishes
        // "called off" from "you were too late", and the row has to be redrawn
        // as whichever it was. It used to assume the former unconditionally.
        $cancelled = null === $message->sentAt && $this->callOffSend($message);

        $thread = $message->thread;
        $type   = 'message' === $request->query->get('type') || null === $thread
            ? 'message'
            : 'thread';

        return $this->renderTurboStream('compose/_unschedule.stream.html.twig', [
            'row'         => 'thread' === $type ? $thread : $message,
            'type'        => $type,
            'draft_scope' => $request->query->getBoolean('draft_scope'),
            'cancelled'   => $cancelled,
        ]);
    }

    /**
     * Lower the flag SendMessageHandler reads, and take the hold off.
     *
     * `cancelled` is deliberately not recorded as a JMAP change: it is a flag
     * the handler reads and clears, and EmailMapper publishes nothing derived
     * from it. The message is still the same unsent draft it was a moment ago,
     * so waking every client for it would be a push about nothing changing.
     *
     * The hold is not private traffic, though. While submissionSendAt stands
     * with sentAt null, EmailSubmissionGetMethod reports the submission as
     * `pending` and every JMAP client shows a schedule — for mail the handler
     * has just been told to drop. Leaving it would have made cancelling a
     * scheduled send invisible everywhere except the browser that did it, and
     * permanent: nothing else ever clears that column for a message that never
     * leaves.
     *
     * Only while it has not left. On a sent message the column is the record of
     * when it was due, and EmailSubmission/get falls back to it; erasing that
     * would be rewriting history to undo something that already happened.
     *
     * Answers whether the cancel actually took. False means SendMessageHandler
     * had already claimed the message and the mail is gone or going — see
     * MessageRepository::cancelSend(), which is where that is decided, and in
     * one statement so there is no window for the two to disagree.
     *
     * No longer writes through the entity, and that is the point rather than a
     * detail. Setting a property and flushing is a read-modify-write across the
     * whole request, and the handler's send used to fit inside it; the UPDATE
     * below is the same decision compressed into something the database
     * serialises for us. The entity is refreshed afterwards only so the caller
     * renders what the row now says.
     */
    private function callOffSend(Message $message): bool
    {
        $won = $this->messageRepository->cancelSend((int) $message->id);

        // The row moved underneath the entity either way — we won and it is
        // cancelled, or we lost and the handler is writing sentAt onto it.
        $this->em->refresh($message);

        return $won;
    }

    /**
     * The honest answer to a cancel that arrived too late.
     *
     * There is no draft to reopen here and nothing to undo: the message has
     * been claimed by the handler and is on its way out or already out. Saying
     * "send cancelled" and handing the window back — which is what this used to
     * do unconditionally — was the worst possible answer, because it told the
     * user the one thing that was not true about an action nobody can reverse.
     *
     * The window is not restored, deliberately. Restoring it would put an
     * editable copy of a mail that has been sent on screen, and the next
     * autosave would file it as a draft beside the sent copy.
     */
    private function sendAlreadyGoneResponse(ComposeContext $ctx, Message $message): Response
    {

        return $this->renderTurboStream('compose/_undo_too_late.html.twig', [
            'message' => $message,
            'thread'  => $message->thread,
            'ctx'     => $ctx,
        ]);
    }

    /**
     * A send changes nothing on screen — the window stays put and becomes its
     * own cancel.
     *
     * This used to branch: the dock got a toast with an Undo link and had its
     * window closed underneath it, the inline composer was replaced by a
     * countdown bar in the thread. Two surfaces, two lifetimes, and in both
     * cases the button the user had just pressed was gone by the time they
     * looked for a way back. Now there is one answer for both, and it only
     * hands the live window the URLs it cannot address by itself — see
     * compose/_sending.stream.html.twig.
     *
     * The toast is not deleted, only unused: compose/_send_toast.html.twig and
     * compose--undo-send are still here while this shape is reviewed.
     */
    private function sendResponse(Message $message, ComposeContext $ctx): Response
    {
        return $this->renderTurboStream('compose/_sending.stream.html.twig', [
            'ctx'     => $ctx,
            'undoUrl' => $this->generateUrl(
                'app_compose_mail_undo',
                $ctx->urlParams() + ['id' => $message->id],
            ),
            'settleUrl' => $this->generateUrl(
                'app_compose_mail_sent',
                $ctx->urlParams() + ['id' => $message->id],
            ),
            // The CANCEL window, not the send delay. Offering the whole delay
            // is what let a click land after the worker had already claimed
            // the message — see CANCEL_WINDOW_MS.
            'cancelWindow' => self::CANCEL_WINDOW_MS,
        ]);
    }

    /**
     * The cancel window has run out: file the message and close the window.
     *
     * Deliberately a separate request rather than something the send response
     * could have done, because it happens eight seconds after that response
     * and the difference between the two moments is the entire feature. Until
     * it runs, the composer is still on screen with the message in it and one
     * click calls the send off.
     *
     * Nothing here writes. It is a POST because it is an instruction and not a
     * resource — nothing should replay it out of a cache or a prefetch — and
     * it answers the same streams a too-late cancel does, because "this
     * message has gone" is the same fact either way.
     *
     * Safe to lose. If the browser is closed inside the window the mail still
     * goes; only the tidying is skipped, and the next render of the thread
     * shows the message where this would have put it.
     */
    #[Route('/sent/{id}', name: 'mail_sent', methods: ['POST'])]
    public function sent(ComposeContext $ctx, Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $this->renderTurboStream('compose/_sent.stream.html.twig', [
            'message' => $message,
            'thread'  => $message->thread,
            'ctx'     => $ctx,
        ]);
    }

    /**
     * A scheduled send closes the window and says when, wherever it was.
     *
     * Not sendResponse(): that one's inline branch appends the message to the
     * open conversation, because it IS going out and the countdown is the
     * cancel. A schedule has no countdown to run — the mail is not on its way
     * for hours — so the draft stays a draft, its row keeps its place in the
     * thread, and the only change on screen is the window closing and a toast
     * naming the time.
     *
     */
    private function scheduleResponse(Message $message, ComposeContext $ctx, \DateTimeImmutable $sendAt): Response
    {
        return $this->renderTurboStream('compose/_scheduled.stream.html.twig', [
            'message'   => $message,
            'ctx'       => $ctx,
            'sendAt'    => $sendAt,
            'thread'    => $message->thread,
            'cancelUrl' => $this->generateUrl(
                'app_compose_mail_undo',
                $ctx->urlParams() + ['id' => $message->id],
            ),
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
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $this->render('mail/_thread_message.html.twig', [
            'message'  => $message,
            'expanded' => false,
        ]);
    }

    /**
     * Discard: the trash button in the compose window really deletes the
     * draft instead of just closing the window on top of it.
     */
    #[Route('/discard/{id}', name: 'discard', methods: ['POST'])]
    public function discard(Request $request, ComposeContext $ctx, Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        if (false === $message->isDraft() || null !== $message->sentAt) {
            throw $this->createAccessDeniedException('Only unsent drafts can be discarded.');
        }

        $messageId = $message->id;
        $thread    = $message->thread;

        // Before the row goes, because propagation reads the address off it.
        //
        // A draft synced down from the server used to be deleted here and left
        // sitting in the provider's Drafts folder — and once the row was gone
        // nothing could ever collect it, since incremental sync never re-offers
        // a UID below the high-water mark. The discard button says the draft is
        // discarded; it has to be discarded everywhere.
        //
        // LabelChangePropagator::delete() already knew how to say that to each
        // provider in its own terms — expunge on IMAP, the TRASH label on
        // Gmail, a move to Trash on Graph — and had no caller at all. A draft
        // that only ever existed locally has no address on any of them and
        // dispatches nothing.
        $this->labelChanges->delete([$message]);

        // Files, raw bytes, the JMAP destroy, the row, and the thread's
        // counters and labels — all of it, in the order the pieces depend on
        // each other. This used to be written out here and again, differently,
        // in the sync layer; MessageEraser is the one path now.
        $this->eraser->erase($message);

        $remaining = $thread?->messages->count() ?? 0;

        $this->em->flush();

        return $this->renderTurboStream('compose/_discard.stream.html.twig', [
            'messageId' => $messageId,
            'ctx'       => $ctx,
            'threadId'  => $this->window->rowToDrop(
                $thread,
                $messageId,
                $remaining,
                'drafts' === $request->query->get('scope'),
            ),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Re-attach a freshly created draft to the conversation it answers.
     *
     * The lookup is the controller's half of it: `replyTo` is a number that
     * came back from the browser, so the message it names is only the original
     * if the user owns it.
     *
     */
    private function applyReplyContext(Message $message, ComposeContext $ctx): void
    {
        if (null !== $message->thread || null === $ctx->replyTo) {
            return;
        }

        $original = $this->messageRepository->find($ctx->replyTo);

        if (null === $original || false === $this->isGranted(OwnershipVoter::OWN, $original)) {
            return;
        }

        $this->replyDrafts->linkToOriginal($message, $original);
    }

    /**
     */
    private function renderWindow(FormInterface $form, Message $message, ComposeContext $ctx, array $extra = []): Response
    {
        return $this->render('compose/_window.html.twig', $extra + [
            'form'    => $form,
            'message' => $message,
            'ctx'     => $ctx,
            'pickerIntegrations' => $this->window->pickerIntegrationsFor($this->currentUser()),
        ]);
    }

    /**
     * The signed-in user, narrowed. Same shape as CalendarController's — the
     * services this controller delegates to take a User, not a UserInterface,
     * because they are about one person's mail rather than about a session.
     */
    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
