<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Ai\OllamaClient;
use App\Service\Ai\ThreadSummariser;
use App\Service\Ai\ThreadSummaryStore;
use App\Service\Ai\ThreadTranscript;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "What is this conversation about?"
 *
 * STREAMED, AND AT THIS LENGTH THAT IS NOT A PREFERENCE
 * ────────────────────────────────────────────────────
 * Three reasons, and the composer paid for all three already.
 *
 * ComposeAssistController measured the same wall and lost: thirteen silent
 * seconds was "the whole of the 'I press the button and nothing happens'
 * report." A summary is worse. Measured on the reference host, a cold call is
 * about forty seconds before the FIRST TOKEN — eighteen loading the model,
 * twenty-three evaluating a whole conversation — and sixty-four end to end.
 *
 * Only the server can know the model is cold. /api/ps is on the operator's
 * private network and `connect-src 'self'` is enforced in production, so the
 * `state` frame is the difference between an honest "the model is loading" and
 * forty seconds of a spinner.
 *
 * And cancellation. Closing the pane, opening another thread or navigating away
 * has to stop a 20.3 GiB model on a one-GPU host, and the only mechanism that
 * does it is the browser aborting the fetch → this method noticing at
 * connection_aborted() after a write → returning → the generator frame being
 * freed → AiAssistant::recorded()'s `finally` running → the upstream response
 * dropping. A non-streamed POST has no abort path at all.
 *
 * WHAT IS STORED, AND WHEN
 * ────────────────────────
 * Only a summary somebody stayed for. An abandoned stream writes nothing: the
 * text is half a summary, and half a summary sitting on the thread the next
 * time it is opened is worse than no summary, because it reads as a finished
 * one. See generate().
 */
final class ThreadSummaryController extends AbstractController
{
    /** Above the upstream's own bound, so it is the upstream that gives up first. */
    private const int STREAM_TIME_LIMIT_SECONDS = 300;

    /**
     * How far above the client's timeout PHP's own ceiling sits on a full run.
     *
     * Enough for the streamed answer itself plus the tidy, the insert and the
     * closing frames — none of which is bounded by the idle timeout, because
     * tokens are arriving by then.
     */
    private const int STREAM_TIME_LIMIT_MARGIN_SECONDS = 180;

    use ChecksCsrf;

    public function __construct(
        private readonly ThreadSummariser   $summariser,
        private readonly ThreadTranscript   $transcript,
        private readonly ThreadSummaryStore $store,
        private readonly LoggerInterface    $logger,
    ) {
    }

    /**
     * How much of the answer reached the browser before it stopped listening.
     *
     * Only read by the abandonment log, and it is the second half of that
     * diagnosis: nothing written means the connection died during the silence
     * before the first token — which is where a proxy's read timeout falls —
     * while a partial answer means it died mid-stream, which is a reader
     * navigating away or a total-duration limit.
     */
    private int $written = 0;

    /**
     * `{id}` is constrained to digits so a non-numeric id fails to MATCH the
     * route and becomes a 404 — MailController::thread() gives the reason:
     * without it the id reaches the entity resolver, which asks Postgres for a
     * thread whose bigint id is 'abc' and gets a driver-level type error, i.e.
     * a 500 for what is only a bad link.
     *
     * THE ORDER OF WHAT FOLLOWS IS THE DESIGN. Everything that could refuse the
     * request, and everything that reads the Request or the session, happens
     * before the response object exists. Inside the stream the kernel has
     * finished with the Request, and a denied check there is an exception
     * thrown at a response already sent.
     */
    #[Route('/mail/thread/{id}/summary', name: 'app_mail_thread_summary', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function __invoke(Request $request, MessageThread $thread): Response
    {
        // A per-action token id and not the shared `ajax` one: this spends real
        // seconds of somebody else's GPU and returns a paragraph about their
        // mail. ChecksCsrf's rule — "one token good for every action makes any
        // one XSS worth all of them" — and AiPreferencesController's precedent.
        $this->assertCsrf($request, 'thread_summary');

        $user = $this->getUser();

        if (false === $user instanceof User || false === $this->summariser->isAvailableFor($user)) {
            // 409 rather than 403, matching ComposeAssistController and
            // AiPreferencesController: nothing is forbidden, the feature is
            // simply not switched on — for the installation or for this person
            // — and the browser should stop offering it rather than report an
            // error they can do nothing about.
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_CONFLICT);
        }

        // The same guard MailController::thread() uses, on the same subject.
        // One check covers the whole conversation: every message in it hangs
        // off this thread and therefore off this account.
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $thread->account);

        if (false === ThreadSummariser::hasEnoughToSummarise($thread)) {
            // 409 again rather than 400. The request is well formed and the
            // caller did nothing wrong; there is simply nothing here to
            // summarise, which is the same shape of answer as "switched off"
            // and gets the same treatment in the card.
            return new JsonResponse(['error' => 'too_short'], Response::HTTP_CONFLICT);
        }

        // Built HERE, while there is still a controller around the database
        // read, and captured into the closure. It is needed twice — as the
        // prompt and as the thing hashed — and building it twice would be two
        // versions of one fact that disagree the first time either is tuned.
        // WHAT THE READER ASKED FOR, and the only thing this endpoint takes
        // off the request. The thread comes from the URL and the person from
        // the session — see the body comment in the controller that calls this
        // — so a post that chose WHICH mail to summarise would be a way to aim
        // the model at somebody else's. Choosing how much of their own thread
        // to send is not that, and it is the one decision the card offers.
        $full = $request->request->getBoolean('full');

        $transcript = $this->transcript->forThread($thread, $full);
        $sourceHash = ThreadTranscript::hash($transcript);
        $model      = $this->summariser->model();

        // The other half of the store key, resolved HERE for $model's two
        // reasons at once. It reads AiSettings, so asking for it inside the
        // callback would be a query against a kernel that has finished with the
        // request — and it has to be the fingerprint of the prompt this call
        // actually uses, or the row is filed under a prompt that did not write
        // it and survives the edit that should have invalidated it.
        $promptHash = $this->summariser->promptFingerprint();

        // The session lock goes back now, before anything slow.
        //
        // PHP holds it exclusively for the whole request and everything from
        // here on is reads plus one DBAL insert. SearchController records what
        // happens without this, and not from its own file:
        //
        //     MaxExecutionTimeError: Maximum execution time of 30 seconds
        //     exceeded at StrictSessionHandler.php line 50
        //
        // "I searched and the whole site stopped responding." At a cold minute
        // this is worse: every other request from the same tab — the counts
        // poll, the next thread the reader opens, the mark-as-read — would
        // queue behind a paragraph being written and then die at PHP's limit.
        //
        // After the last thing that could write to the session (the CSRF check,
        // getUser() and the ownership check) and before the StreamedResponse
        // exists, because a write after this point is lost.
        $session = $request->getSession();

        if (true === $session->isStarted()) {
            $session->save();
        }

        // $user and $thread are captured already hydrated, for the reason
        // ComposeAssistController captures its user: reading a property inside
        // the callback is free, while a repository lookup there would be a
        // query against a kernel that has finished with the request.
        $response = new StreamedResponse(function () use ($user, $thread, $transcript, $sourceHash, $model, $promptHash, $full): void {
            $this->generate($user, $thread, $transcript, $sourceHash, $model, $promptHash, $full);
        });

        // Not application/json: this is a sequence of JSON documents and never
        // parses as one. Naming it honestly also keeps anything that sniffs a
        // body from trying.
        $response->headers->set('Content-Type', 'application/x-ndjson');

        // A stream of assertions about somebody's mail. Nothing may hold a copy.
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');

        // nginx's opt-out, honoured by nginx and several hosted proxies and
        // ignored by Caddy, which is what plMail ships. Sent anyway: the
        // deployment this breaks in is the one behind somebody else's reverse
        // proxy, and there it is the only thing that helps.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * The body of the stream.
     *
     * A method rather than the closure's own body, so the generator lives in a
     * scope that ENDS. ComposeAssistController's reason, unchanged: a
     * `use`-captured generator is held by the Closure, which the response
     * holds, which the kernel holds until the request is torn down — so an
     * abandoned stream would keep the model generating long after the reader
     * had gone. A local in a method is freed when the method returns, and
     * freeing it is what cancels the call.
     */
    /**
     * PHP's ceiling for this run, always above the client's own.
     *
     * The margin is what the request does AFTER the last token — tidying,
     * one insert, the closing frames — plus room for the client's timeout to
     * fire and unwind normally, which is the outcome this ordering exists to
     * guarantee. An ordinary run keeps exactly the 300 s it has always had.
     */
    private static function streamTimeLimitFor(string $transcript, bool $full): int
    {
        if (false === $full) {
            return self::STREAM_TIME_LIMIT_SECONDS;
        }

        return max(
            self::STREAM_TIME_LIMIT_SECONDS,
            (int) ceil(ThreadSummariser::timeoutFor($transcript)) + self::STREAM_TIME_LIMIT_MARGIN_SECONDS,
        );
    }

    private function generate(User $user, MessageThread $thread, string $transcript, string $sourceHash, string $model, string $promptHash, bool $full = false): void
    {
        // PHP's default is to kill the script the moment a write finds the
        // browser gone, which sounds like what we want and is the opposite of
        // it: the kill lands mid-echo, part-way through the unwinding that
        // cancels the model call and records the row. Taking the abort into our
        // own hands means noticing it at a line we chose.
        ignore_user_abort(true);

        // php.ini sets max_execution_time = 30 for the web SAPI, and this
        // request legitimately takes twice that cold. The limit fires INSIDE
        // CurlResponse's read, which skips the unwinding that cancels the call
        // and records the row — so Ollama carries on generating for a reader
        // who has already been shown an error.
        //
        // Unbounded here is not unbounded overall, which is the only reason it
        // is safe: the upstream is held to OllamaClient::GENERATE_TIMEOUT, a
        // dead host trips that and unwinds normally, and a reader who leaves is
        // noticed by connection_aborted() on the next write.
        // A POSITIVE ceiling, not 0.
        //
        // 0 means "cancel the timer", and cancelling is the part that does not
        // reliably happen: a summary died mid-stream with
        //
        //     MaxExecutionTimeError: Maximum execution time of 0 seconds
        //     exceeded at CurlResponse.php line 370
        //
        // and that message is its own diagnosis. Line 370 is curl_multi_select,
        // the blocking wait for the model's next token, and PHP names the limit
        // it is enforcing — 0. The value was set; the already-armed timer was
        // not taken down with it, and it fired while blocked on the socket.
        //
        // A positive value RE-ARMS instead, which is the operation that works.
        // It is also the more honest ceiling: unbounded means a request that
        // holds a PHP worker for ever if the reader and the model both vanish,
        // and there is no bound left anywhere to catch it.
        //
        // Above OllamaClient::GENERATE_TIMEOUT (120s, an IDLE timeout) plus a
        // cold load, so this only ever fires when that has failed to.
        // SCALED WITH THE RUN, because a fixed 300 s is below what a full run
        // legitimately needs. The whole argument above — this must sit above
        // the upstream's own bound so the upstream gives up first — stops being
        // true the moment the upstream's bound moves, and for a full run it
        // does: ThreadSummariser::timeoutFor() can reach several minutes on a
        // conversation at the ceiling. Left at 300 the two would be the wrong
        // way round, and PHP would kill the request while the client was still
        // waiting patiently — which is the failure this line exists to prevent,
        // reintroduced by the feature that needed it most.
        set_time_limit(self::streamTimeLimitFor($transcript, $full));

        $tokens = $this->summariser->stream($user, $thread, $transcript, $full);

        if (null === $tokens) {
            // Switched off between the check above and here, or a thread whose
            // messages carry no text at all.
            $this->frame(['type' => 'error', 'kind' => 'no_answer']);

            return;
        }

        // Asked BEFORE the generator is iterated, which is the only moment the
        // answer is true: the request below is what loads the model, so a probe
        // after it would report warm on precisely the cold call that needed
        // explaining. Safe to ask at all only because /api/ps is passive — it
        // reports, it never loads.
        // A THIRD STATE, and it exists because the other two would both be
        // lies here. A full run's silence is not the cold load the "waiting"
        // line describes — it is the model reading a conversation twelve times
        // longer than the ordinary budget, which on the reference host is
        // minutes rather than the "about a minute" that line promises. Warm or
        // cold barely moves it, so residency is not even the question any more.
        $this->frame([
            'type'  => 'state',
            'value' => match (true) {
                true === $full                             => 'waiting_full',
                true === $this->summariser->isModelWarm()  => 'generating',
                default                                    => 'waiting',
            },
        ]);

        // When the reading started, so each heartbeat can say how long it has
        // been going. See the ping frame below.
        $readingSince = microtime(true);
        $this->written = 0;

        foreach ($tokens as $token) {
            // An EMPTY token is the client's heartbeat, not something the model
            // said — OllamaClient yields one every few seconds while the host
            // is silent, and a long prompt is one long silence. It must not
            // reach the card as a token: the first token is what stops the
            // "still reading" line, and a heartbeat would stop it while nothing
            // had been written yet.
            //
            // It still gets a FRAME, which is the whole point. Something on the
            // wire every few seconds is what keeps a reverse proxy from closing
            // an idle connection part-way through a summary that is going
            // perfectly well — and it is what makes the abort check below
            // reachable during the silence instead of only once tokens start,
            // so a reader who leaves now frees the model call within seconds
            // rather than at the end of it.
            // ELAPSED, AND NOTHING ELSE. Ollama sends nothing at all while it
            // evaluates a prompt and reports prompt_eval_count only in the
            // final frame, so there is no "message 40 of 120" to be had and any
            // bar drawn here would be an animation of an assumption. What IS
            // true and worth saying is that time is passing and this end is
            // still connected — which is the actual question somebody has at
            // four minutes into a silent card, and the one a spinner cannot
            // answer.
            $this->frame(
                '' === $token
                    ? ['type' => 'ping', 'elapsed' => (int) round(microtime(true) - $readingSince)]
                    : ['type' => 'token', 'text' => $token],
            );

            // Checked AFTER a write, because a write is how a PHP process finds
            // out at all: a browser closing the connection is not an event, it
            // is a send that fails and is noticed on the next one.
            $this->written += mb_strlen($token);

            if (0 !== connection_aborted()) {
                break;
            }
        }

        // True only if the loop was broken out of — a finished generator is not
        // valid. Nobody is left to send a frame to, and NOTHING IS STORED: what
        // exists is half a summary, and half a summary on the thread the next
        // time it opens reads as a finished one. Falling off the end here is
        // the whole of the cancellation — it frees $tokens, which runs the
        // recording in AiAssistant::recorded() and drops the upstream response
        // so the host stops generating.
        if (true === $tokens->valid()) {
            // SAID OUT LOUD, and this was the last silent path in the feature.
            //
            // It is reached when the reader's connection has gone — which is
            // a normal thing for somebody navigating away, and is also what a
            // proxy closing the stream looks like from in here. The two are
            // indistinguishable to this process and only one of them is fine,
            // so leaving it silent meant a card reading "the summary could not
            // be written", nothing in the log, and a `cancelled` row in the
            // metrics panel that reads as a reader who changed their mind.
            //
            // The elapsed time is what tells them apart. Somebody who gave up
            // did it at a human moment; a proxy does it at its configured one,
            // and the same round number every time is the whole diagnosis. See
            // docs/install/reverse-proxy.md, which configures streaming for the
            // Mercure endpoint and — until this was found — said nothing about
            // this one, which is the other long-lived stream plMail serves.
            $this->logger->warning('Thread summary abandoned before it finished', [
                'thread'           => $thread->id,
                'model'            => $model,
                'full'             => $full,
                'transcript_chars' => mb_strlen($transcript),
                'elapsed_seconds'  => (int) round(microtime(true) - $readingSince),
                'chars_so_far'     => $this->written,
            ]);

            return;
        }

        $result = $tokens->getReturn();

        if (false === $result->succeeded || null === $result->content) {
            $kind = $result->errorKind ?? OllamaClient::ERROR_UNEXPECTED;

            // SAID SERVER-SIDE AS WELL, and not only sent to the browser.
            //
            // Two of the kinds — http_status and unexpected — render as "the
            // summary could not be written", which is the honest sentence for
            // them and a dead end for anybody trying to find out why. There was
            // no other trace: the frame went to a reader who cannot act on it,
            // the request finished 200 because the stream had already started,
            // and the log had nothing at all. A thread that failed every time
            // while every other thread worked was undiagnosable from the
            // outside, and it took a live report to notice.
            //
            // The thread id is here because this is reported per thread — "this
            // one never summarises" — and it is the only handle on which mail
            // was involved. The mail itself is not logged.
            // ERROR AND NOT WARNING, which is not a judgement about severity so
            // much as about findability. This line exists for exactly one
            // situation — somebody reporting a card that says the summary could
            // not be written — and at warning it is filterable by the capture
            // level on the admin log page, which is a setting an operator
            // raises to quieten a noisy install. The one line that answers the
            // question would then be the one the setting removed. It is also a
            // user-visible operation that failed, which is what error means.
            $this->logger->error('Thread summary failed', [
                'thread' => $thread->id,
                'kind'   => $kind,
                'model'  => $model,
                // WHICH RUN THIS WAS, which the category never said. "Timeout"
                // on an ordinary run and on a full one are different faults
                // with different fixes — the first means the host is slower
                // than the shipped budget, the second that the full budget is
                // still short — and three reports of `kind: timeout` could not
                // be told apart because neither the mode nor the size was in
                // the record.
                'full'             => $full,
                'transcript_chars' => mb_strlen($transcript),
            ]);

            $this->frame([
                'type' => 'error',
                // A category from the closed set, never a message: an HTTP
                // client's exception text quotes the request body back, and the
                // request body here is somebody's mail.
                'kind' => $kind,
            ]);

            return;
        }

        // A DBAL write, and safe after the session close because it touches
        // nothing the session holds. Its failure is logged and swallowed inside
        // the store: the reader already has their summary on screen, and a
        // caching miss must not become a 500 on a response that is half sent.
        $this->store->save((int) $thread->id, $result->content, $sourceHash, $model, $promptHash, $full);

        $this->frame([
            'type' => 'done',
            // Whether the model was shown the whole conversation. Sent with the
            // answer because it is a property of THIS run: the reader is about
            // to be given a paragraph of assertions about their mail, and
            // "written from part of it" is the one caveat they cannot get from
            // reading the paragraph.
            'partial' => ThreadTranscript::isPartial($transcript),
            // Whether this run sent the whole conversation, so the card knows
            // the offer has been taken up and stops making it. Distinct from
            // `partial`: a thread past FULL_TRANSCRIPT_CEILING is both — sent
            // in full mode and still trimmed — and the reader needs to be told
            // that pressing it again will not help.
            'full'    => $full,
            // The whole answer again, tidied. The browser has the raw tokens
            // and could join them itself, but tidy() strips a code fence that
            // can only be recognised from both ends — so what is SHOWN from
            // here on is the same string that was just stored, rather than a
            // browser-side imitation of the same rule that would drift from it.
            'text'         => $result->content,
            'promptTokens' => $result->timing->promptTokens,
            'evalTokens'   => $result->timing->evalTokens,
            // Milliseconds, converted here. Ollama counts in nanoseconds
            // because that is what Go's time package hands it; nothing a person
            // reads wants eleven digits.
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
     * — the two files this image ships — both set `output_buffering = 4096`, so
     * `echo` lands in a userland buffer that `flush()` does not touch: flush()
     * pushes the SAPI's buffer out, and the SAPI has not been given anything
     * yet. ob_flush() is what hands it over.
     *
     * With only flush(), a summary shorter than four kilobytes — which is
     * almost all of them — would arrive in one lump at the end, be
     * indistinguishable from an unstreamed endpoint, and pass every test that
     * did not measure the ARRIVAL TIME of individual frames.
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
}
