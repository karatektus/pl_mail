<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Ai\WritingTask;
use App\Entity\Mail\Message;
use App\Repository\Mail\MessageRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\WritingAssistant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Help me write this."
 *
 * NO DRAFT ID IN THE ROUTE, DELIBERATELY
 * ──────────────────────────────────────
 * A composer opens with no message id — one is minted by the first autosave —
 * so a URL built at render time with an id in it is stale in exactly the window
 * where somebody is most likely to ask for help: the empty one they just
 * opened. Everything this needs travels in the body instead, and nothing is
 * saved as a side effect. The draft is the browser's business; this only
 * answers.
 *
 * JSON, NOT A TURBO STREAM
 * ────────────────────────
 * The answer goes into a contenteditable at a caret position only the browser
 * knows, and it has to be insertable without disturbing a selection or a
 * pending autosave. A stream would replace a region and lose both.
 *
 * THE SERVER MAKES THE CALL, AND THAT IS NOT AN IMPLEMENTATION DETAIL
 * ──────────────────────────────────────────────────────────────────
 * `connect-src 'self'` is enforced in production, so a browser cannot reach the
 * model host at all — and should not: the endpoint is an address on the
 * operator's private network and putting it in a page hands it to every script
 * that page ever loads.
 */
#[Route('/compose/assist', name: 'app_compose_assist')]
#[IsGranted('IS_AUTHENTICATED')]
final class ComposeAssistController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly WritingAssistant  $assistant,
        private readonly MessageRepository $messages,
    ) {
    }

    #[Route('', name: '', methods: ['POST'])]
    public function assist(Request $request): Response
    {
        // Same token the composer's other posts carry. This spends time on
        // another machine on the user's behalf and returns text that lands in
        // their draft, so it is not a thing to leave open to a cross-site post.
        $this->assertCsrf($request, 'ajax');

        if (false === $this->assistant->isAvailable()) {
            // 409 rather than 403: nothing is forbidden, the feature is simply
            // not switched on — and the browser should stop offering it rather
            // than report an error the user can do nothing about.
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_CONFLICT);
        }

        $task = WritingTask::tryFrom((string) $request->request->get('task', ''));

        if (null === $task) {
            return new JsonResponse(['error' => 'unknown_task'], Response::HTTP_BAD_REQUEST);
        }

        $text = $this->assistant->write(
            $task,
            (string) $request->request->get('draft', ''),
            $this->context($request),
            (string) $request->request->get('subject', ''),
        );

        if (null === $text) {
            // The host is down, the model said nothing usable, or there was
            // nothing to work from. One answer for all of them, because the
            // browser's response to each is the same: say so, and leave the
            // draft exactly as it was.
            return new JsonResponse(['error' => 'no_answer'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['text' => $text]);
    }

    /**
     * The same answer, arriving as it is written.
     *
     * WHY THIS IS NOT MERCURE
     * ───────────────────────
     * Mercure is the right tool for background fan-out — a sync finishing, mail
     * arriving, a job the user is not watching. This is one person watching one
     * composer for the length of one request they started themselves. Publishing
     * a token at a time to a hub so it can be pushed back down a second
     * connection to the same browser adds a process hop per token, churns the
     * hub for a stream nobody else may see, and outlives the request it belongs
     * to. A response to the request that asked is shorter in every dimension.
     *
     * WHY THE FRAMES ARE NDJSON RATHER THAN SERVER-SENT EVENTS
     * ────────────────────────────────────────────────────────
     * EventSource cannot POST, cannot carry the CSRF header, and cannot be
     * aborted from script — and the draft this is about is a POST body of a few
     * kilobytes that must not become a query string. So the transport is a plain
     * fetch() whose body is read as a stream, and one JSON object per line is the
     * least framing that survives a chunk boundary landing mid-object. The reader
     * buffers to the newline exactly as OllamaClient does with Ollama itself.
     *
     * WHY THE STATES ARE ON THE WIRE AND NOT GUESSED IN THE BROWSER
     * ─────────────────────────────────────────────────────────────
     * The first frame says whether the model was already in the host's memory.
     * Only the server can know that — /api/ps is on the operator's private
     * network — and it is the difference between thirteen seconds of honest
     * "the model is loading" and thirteen seconds of a spinner, which is the
     * bug this whole feature was reported as.
     */
    #[Route('/stream', name: '_stream', methods: ['POST'])]
    public function assistStream(Request $request): Response
    {
        $this->assertCsrf($request, 'ajax');

        if (false === $this->assistant->isAvailable()) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_CONFLICT);
        }

        $task = WritingTask::tryFrom((string) $request->request->get('task', ''));

        if (null === $task) {
            return new JsonResponse(['error' => 'unknown_task'], Response::HTTP_BAD_REQUEST);
        }

        // Read here rather than inside the callback. By the time the callback
        // runs the kernel has finished with the Request, and the database read
        // behind context() wants to happen while there is still a controller
        // around it to turn a denied ownership check into a 403 — inside the
        // stream it would be an exception thrown at a response already sent.
        $draft   = (string) $request->request->get('draft', '');
        $subject = (string) $request->request->get('subject', '');
        $context = $this->context($request);

        $response = new StreamedResponse(function () use ($task, $draft, $context, $subject): void {
            $this->generate($task, $draft, $context, $subject);
        });

        // Not application/json: this is a sequence of JSON documents and never
        // parses as one. Naming it honestly also keeps anything that sniffs a
        // body — a debug toolbar, a proxy that rewrites JSON — from trying.
        $response->headers->set('Content-Type', 'application/x-ndjson');

        // A stream of somebody's half-written mail. Nothing may hold a copy.
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');

        // nginx's opt-out, honoured by nginx and several hosted proxies and
        // ignored by Caddy, which is what plMail actually ships. Sent anyway,
        // because the deployment this breaks in is the one behind somebody
        // else's reverse proxy — and there the header costs nothing and is the
        // only thing that helps.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * The body of the stream.
     *
     * A method rather than the closure's own body, so that the generator lives
     * in a scope that ENDS. A `use`-captured generator is held by the Closure
     * object, which the StreamedResponse holds, which the kernel holds until the
     * request is torn down — so an abandoned stream would keep the model
     * generating long after the reader had gone, which is the exact thing this
     * is written to prevent. A local in a method is freed when the method
     * returns, and freeing it is what cancels the call.
     */
    private function generate(WritingTask $task, string $draft, ?string $context, string $subject): void
    {
        // PHP's default is to kill the script the moment a write finds the
        // browser gone. That sounds like what we want and is the opposite of
        // it: the kill lands mid-echo, part-way through the unwinding that
        // cancels the model call and records the row. Taking the abort into our
        // own hands means we notice it at a line we chose and unwind properly.
        ignore_user_abort(true);

        $tokens = $this->assistant->stream($task, $draft, $context, $subject);

        if (null === $tokens) {
            // Switched off between the check above and here, or nothing to work
            // from. Same answer the unstreamed action gives, in the frame shape
            // this one speaks.
            $this->frame(['type' => 'error', 'kind' => 'no_answer']);

            return;
        }

        // Asked BEFORE the generator is iterated, which is the only moment the
        // answer is true: the request below is what loads the model, so a probe
        // after it would report the model as warm on precisely the cold call
        // that needed explaining. Safe to ask at all only because /api/ps is
        // passive — it reports, it never loads, and it does not touch the
        // keep-alive it is reporting on.
        $this->frame([
            'type'  => 'state',
            'value' => true === $this->assistant->isModelWarm() ? 'generating' : 'waiting',
        ]);

        foreach ($tokens as $token) {
            $this->frame(['type' => 'token', 'text' => $token]);

            // Checked AFTER a write, because a write is how a PHP process finds
            // out at all: a browser closing the connection is not an event, it
            // is a send that fails and is noticed on the next one.
            if (0 !== connection_aborted()) {
                break;
            }
        }

        // True only if the loop was broken out of — a finished generator is not
        // valid. There is nobody left to send a frame to, so the whole of the
        // work here is falling off the end of this method, which frees $tokens,
        // which runs the recording in AiAssistant::recorded() and drops the
        // upstream response so the host stops generating.
        if (true === $tokens->valid()) {
            return;
        }

        $result = $tokens->getReturn();

        if (false === $result->succeeded || null === $result->content) {
            $this->frame([
                'type' => 'error',
                // A category from the closed set, never a message: an HTTP
                // client's exception text quotes the request body back, and the
                // request body here is somebody's mail.
                'kind' => $result->errorKind ?? OllamaClient::ERROR_UNEXPECTED,
            ]);

            return;
        }

        $this->frame([
            'type' => 'done',
            // The whole answer again, tidied. The browser has the raw tokens
            // and could join them itself, but tidy() strips a code fence that
            // can only be recognised from both ends — so what a person INSERTS
            // is the server's cleaned version rather than a second, browser-side
            // imitation of the same rule that would drift from it.
            'text'         => $result->content,
            'promptTokens' => $result->timing->promptTokens,
            'evalTokens'   => $result->timing->evalTokens,
            // Milliseconds, converted here. Ollama counts in nanoseconds
            // because that is what Go's time package hands it; nothing a person
            // reads wants eleven digits, and the arithmetic belongs on the side
            // that already knows the unit.
            'loadMs'  => self::millis($result->timing->loadDurationNs),
            'evalMs'  => self::millis($result->timing->evalDurationNs),
            'totalMs' => self::millis($result->timing->totalDurationNs),
        ]);
    }

    /**
     * One NDJSON frame, actually on the wire.
     *
     * BOTH FLUSHES, IN THIS ORDER, AND NEITHER IS OPTIONAL
     * ────────────────────────────────────────────────────
     * They empty different buffers. php.ini-development and php.ini-production
     * — the two files this image ships, one per build target — both set
     * `output_buffering = 4096`, so `echo` lands in a userland buffer that
     * `flush()` does not touch: flush() pushes the SAPI's buffer out, and the
     * SAPI has not been given anything yet. ob_flush() is what hands it over.
     *
     * With only flush() — which is what the one existing StreamedResponse in
     * this codebase does, because an .ics export is measured in whole files and
     * does not care — the first four kilobytes are held back. A short reply is
     * less than four kilobytes, so the entire stream would arrive in one lump at
     * the end, be indistinguishable from the unstreamed endpoint, and pass every
     * test that did not measure the ARRIVAL TIME of individual frames.
     *
     * @param array<string, mixed> $frame
     */
    private function frame(array $frame): void
    {
        // JSON_INVALID_UTF8_SUBSTITUTE rather than letting a bad byte return
        // false: a token is whatever the model emitted, and one malformed
        // sequence must cost that token rather than silently ending the stream
        // with no frame and no reason.
        echo json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";

        if (0 < ob_get_level()) {
            ob_flush();
        }

        flush();
    }

    /** Nanoseconds as whole milliseconds, keeping "not measured" distinct from zero. */
    private static function millis(?int $nanoseconds): ?int
    {
        return null === $nanoseconds ? null : intdiv($nanoseconds, 1000000);
    }

    /**
     * The message being replied to, read from the server rather than the page.
     *
     * The browser sends an id; the body comes out of the database. Trusting the
     * page for it would let anything that can post here choose what the model
     * is told — with the answer landing in the user's own draft, which is
     * exactly the shape of thing that should not be steerable from outside.
     *
     * Ownership is checked, so an id belonging to somebody else is refused
     * rather than summarised.
     */
    private function context(Request $request): ?string
    {
        $id = $request->request->get('inReplyTo');

        if (null === $id || '' === trim((string) $id)) {
            return null;
        }

        $message = $this->messages->find((int) $id);

        if (false === $message instanceof Message) {
            return null;
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $message->bodyText;
    }
}
