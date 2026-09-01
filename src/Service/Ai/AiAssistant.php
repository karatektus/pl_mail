<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiCallTiming;
use App\Domain\DTO\Ai\AiChatResult;
use App\Domain\DTO\Ai\AiEmbedResult;
use App\Domain\DTO\Ai\AiProbe;
use App\Domain\DTO\Ai\AiWarmUp;
use App\Domain\Enum\Ai\AiCallFeature;
use App\Entity\Ai\AiFeature;
use App\Entity\Ai\AiSettings;
use App\Repository\Ai\AiSettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * The one door between plMail and a language model.
 *
 * Every feature goes through here, and it exists so that the question "are we
 * allowed to do this, and with which model" is answered in exactly one place.
 * Spread across three features it would be answered three times, and the third
 * one would be answered wrongly on the installation that had switched the
 * feature off.
 *
 * WHAT IT GUARANTEES
 * ──────────────────
 *  · A feature that is off gets null. Not an exception, not a degraded answer,
 *    not a "please configure AI" — null, which every caller already has to
 *    handle because the host can also simply be down.
 *  · A feature never chooses its own model. Asking for a completion while the
 *    embedding model is configured and the chat model is not is a mistake this
 *    refuses rather than performs, and it refuses before spending a request.
 *  · Nothing here throws. The host is a box on somebody's LAN which may be
 *    switched off, mid-update, or holding a different set of models than it did
 *    last week, and none of that is an application error.
 *
 * WHY IT IS NOT CACHED
 * ────────────────────
 * The settings are read per call, which is one indexed lookup Doctrine answers
 * from its identity map for the rest of the request. Every operation behind it
 * costs an HTTP round trip to another machine and, on a cold model, several
 * seconds — so a cache here would optimise the free part of a slow thing while
 * adding a way for a switched-off feature to keep running for a while.
 */
final readonly class AiAssistant
{
    /**
     * A streamed call the caller walked away from.
     *
     * Not one of OllamaClient's categories, and deliberately outside them: all
     * six of those describe something the HOST did, and this describes
     * something the user did. It sits in the same closed-set column because it
     * is the same kind of fact — a category, never a message — and because a
     * stopped draft still spent real seconds of a real GPU, which is exactly
     * what the metrics table exists to account for.
     */
    public const string ERROR_CANCELLED = 'cancelled';

    /**
     * The two ways an embedding is refused before it becomes a request.
     *
     * Spelled in the same vocabulary as OllamaClient's ERROR_* constants so
     * that a caller turning an error kind into a sentence somebody reads has
     * one list to map rather than two — and so neither of these can be mistaken
     * for a host that answered.
     */
    public const string ERROR_DISABLED = 'disabled';
    public const string ERROR_NO_TEXT  = 'no_text';

    /**
     * How long the composer will wait to be told whether the model is warm.
     *
     * A second and a half, against the five OllamaClient gives a probe by
     * default. This one is not a health check on a page about the host; it runs
     * in front of the writer's own request and buys one sentence of
     * explanation. On a LAN /api/ps answers in single-digit milliseconds, so
     * the only call this ever cuts short is one to a host that is already not
     * answering — where the honest thing is to stop asking and get on with
     * failing properly.
     */
    private const float RESIDENCY_TIMEOUT = 1.5;

    public function __construct(
        private AiSettingsRepository $settings,
        private OllamaClient         $client,
        private AiCallRecorder       $recorder,
        private LoggerInterface      $logger,
    ) {
    }

    public function settings(): AiSettings
    {
        return $this->settings->currentOrDefault();
    }

    public function isEnabledFor(AiFeature $feature): bool
    {
        return $this->settings()->enabledFor($feature);
    }

    /**
     * A vector for one piece of text, or null.
     *
     * Null covers every reason equally on purpose — switched off, no model, the
     * host is down, the model was deleted — because the caller's response to
     * all of them is the same: carry on without it. Anything that has to
     * explain the difference to a person — the search box does — asks
     * {@see embedResult()} instead.
     *
     * The workload is REQUIRED and has no default. Both kinds of embedding run
     * the same model through the same method — one short search box query
     * somebody is waiting on, and two thousand characters of mail body written
     * unattended a hundred thousand times — and averaged together the second
     * buries the first. A caller that did not have to say which it is would
     * record the backfill's throughput under the heading "search".
     *
     * @return list<float>|null
     */
    public function embed(AiCallFeature $workload, string $text): ?array
    {
        return $this->embedResult($workload, $text)->vector;
    }

    /**
     * The same call, with the reason it failed still attached.
     *
     * embed() answers null for every failure equally, which is right for the
     * backfill: a hundred thousand messages are embedded unattended, nobody is
     * waiting, and the answer to every kind of failure is to try that message
     * again later. It is wrong for the search box, where somebody IS waiting
     * and the difference between "the host is unplugged" and "that model is not
     * on it" is the difference between a sentence they can act on and a search
     * that quietly returns less than it should.
     *
     * Both refusals below happen before a request exists, so neither is
     * recorded — counting them would report calls that were never made — and
     * both carry an error kind of their own so a caller can tell them from a
     * host that answered badly.
     */
    public function embedResult(AiCallFeature $workload, string $text): AiEmbedResult
    {
        $settings = $this->settings();

        if (false === $settings->enabledFor(AiFeature::Search)) {
            return AiEmbedResult::failed(self::ERROR_DISABLED);
        }

        if ('' === trim($text)) {
            return AiEmbedResult::failed(self::ERROR_NO_TEXT);
        }

        $model = (string) $settings->embeddingModel;

        $result = $this->client->embed(
            (string) $settings->baseUrl,
            $model,
            $text,
            $settings->keepAliveFor(AiFeature::Search),
        );

        // Recorded here rather than in the client, because the client knows the
        // numbers and this knows what they were FOR.
        $this->recorder->record($workload, $model, $result->succeeded, $result->errorKind, $result->timing);

        return $result;
    }

    /**
     * A completion for a feature that is allowed to ask for one.
     *
     * The feature is a parameter rather than implied, so that switching off
     * categorisation cannot be worked around by a caller that happens to hold
     * this service. Only the two chat-shaped features are accepted; asking on
     * behalf of Search is a programming error and is refused rather than
     * silently answered with the wrong model.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    public function chat(AiFeature $feature, array $messages, ?float $temperature = null): ?string
    {
        if (AiFeature::Search === $feature) {
            $this->logger->error('AiAssistant: chat requested on behalf of the search feature', [
                'hint' => 'Search uses the embedding model. This is a bug in the caller.',
            ]);

            return null;
        }

        $settings = $this->settings();

        if (false === $settings->enabledFor($feature)) {
            return null;
        }

        if ([] === $messages) {
            return null;
        }

        $model = (string) $settings->chatModel;

        $result = $this->client->chat(
            (string) $settings->baseUrl,
            $model,
            $messages,
            $temperature,
            $settings->keepAliveFor($feature),
        );

        $this->recorder->record(
            AiCallFeature::forChat($feature),
            $model,
            $result->succeeded,
            $result->errorKind,
            $result->timing,
        );

        return $result->content;
    }

    /**
     * The same completion, token by token, still through this gate.
     *
     * WHY THIS EXISTS BESIDE chat() RATHER THAN REPLACING IT
     * ──────────────────────────────────────────────────────
     * Only one caller sits and watches. Categorisation runs in a worker and
     * wants the answer whole; the composer's writing help is a person waiting
     * on twenty gigabytes of model, and for them the tokens ARE the progress
     * bar. Everything else — which feature is allowed to ask, which model it
     * gets, that exactly one row is recorded — has to be identical, which is
     * why it is here and not a second door.
     *
     * NULL MEANS "NOTHING WAS ASKED OF ANYBODY"
     * ─────────────────────────────────────────
     * The same contract chat() has, with the same three refusals, and the same
     * consequence: no request was made, so no row is recorded. A caller that
     * gets a Generator back is holding a call that WILL be recorded exactly
     * once, whatever happens to it.
     *
     * This cannot be a generator function itself. A generator's body does not
     * run until it is first iterated, so the refusals below would happen at the
     * caller's foreach rather than here — and a caller that never iterated
     * would have been silently permitted. The gate runs eagerly; the streaming
     * lives in recorded().
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return \Generator<int, string, void, AiChatResult>|null
     */
    public function chatStream(AiFeature $feature, array $messages, ?float $temperature = null): ?\Generator
    {
        if (AiFeature::Search === $feature) {
            $this->logger->error('AiAssistant: a streamed chat was requested on behalf of the search feature', [
                'hint' => 'Search uses the embedding model. This is a bug in the caller.',
            ]);

            return null;
        }

        $settings = $this->settings();

        if (false === $settings->enabledFor($feature)) {
            return null;
        }

        if ([] === $messages) {
            return null;
        }

        $model = (string) $settings->chatModel;

        return $this->recorded(
            AiCallFeature::forChat($feature),
            $model,
            $this->client->chatStream(
                (string) $settings->baseUrl,
                $model,
                $messages,
                $temperature,
                $settings->keepAliveFor($feature),
            ),
        );
    }

    /**
     * Is the model this feature would use already in the host's memory?
     *
     * The one question the composer has to answer before it can say anything
     * honest about the wait. Resident means the next request starts generating;
     * not resident means it spends around thirteen seconds loading a 20 GiB
     * model off disk first, during which the host produces nothing at all and a
     * silent interface is indistinguishable from a broken one.
     *
     * Passive — /api/ps reports and never loads — so asking costs the host
     * nothing and does not touch the keep-alive it is reporting on.
     *
     * FALSE IS THE ANSWER FOR A HOST THAT DOES NOT ANSWER
     * ───────────────────────────────────────────────────
     * ps() collapses "nothing is loaded" and "nobody is home" into one empty
     * list, so a down host reads as a cold one. That is the right way round: it
     * makes the composer say "this may take a moment" and then report the real
     * failure a moment later, where the other way round would have it promise
     * tokens that are never coming.
     */
    public function isModelResident(AiFeature $feature): bool
    {
        $settings = $this->settings();

        if (false === $settings->enabledFor($feature)) {
            return false;
        }

        $wanted = self::tagged((string) $settings->chatModel);

        if (':latest' === $wanted) {
            return false;
        }

        foreach ($this->client->ps((string) $settings->baseUrl, self::RESIDENCY_TIMEOUT) as $loaded) {
            if ($wanted === self::tagged($loaded->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A model name with its tag spelled out.
     *
     * `qwen3` and `qwen3:latest` are the same model, and which of the two you
     * get depends on where the string came from: an administrator types the
     * short form into the settings field, and /api/ps always reports the long
     * one. Compared raw, a correctly configured host reads as cold on every
     * single request — so the composer would promise a fifteen-second load
     * before every reply it ever drafted.
     */
    private static function tagged(string $model): string
    {
        return str_contains($model, ':') ? $model : $model . ':latest';
    }

    /**
     * The streaming half of chatStream(), and the one place a streamed call is
     * recorded.
     *
     * EXACTLY ONE ROW, INCLUDING WHEN NOBODY WAITS FOR THE END
     * ────────────────────────────────────────────────────────
     * The recording is in a `finally` rather than after the loop, because the
     * common ending for this generator is not the loop ending — it is the
     * caller breaking out of it. Stop pressed, composer closed, browser gone:
     * every one of those destroys this generator part-way through, and PHP runs
     * a suspended generator's `finally` blocks when it destroys it. After the
     * loop only would have recorded nothing at all for precisely the calls that
     * cost the most and finished the least.
     *
     * Destruction is also what CANCELS the call. Freeing this frame frees the
     * OllamaClient generator inside it, which unwinds out of its own read loop
     * and drops the upstream response — and Ollama stops generating for a
     * client that has gone away. Without that, an abandoned draft keeps a
     * 20 GiB model busy on a machine with one GPU and every other feature
     * queues behind a reply nobody is going to read.
     *
     * $result is captured into a local rather than read back with getReturn(),
     * which throws on a generator that has not finished — and not finishing is
     * the case this exists for.
     *
     * @param \Generator<int, string, void, AiChatResult> $tokens
     *
     * @return \Generator<int, string, void, AiChatResult>
     */
    private function recorded(AiCallFeature $workload, string $model, \Generator $tokens): \Generator
    {
        $result = null;

        try {
            foreach ($tokens as $token) {
                yield $token;
            }

            $result = $tokens->getReturn();

            return $result;
        } finally {
            // Every argument tests $result explicitly rather than reaching for
            // `?->` and `??`, and the middle one is why.
            //
            // It was `$result?->errorKind ?? self::ERROR_CANCELLED`, which is
            // this line with a bug in it: a SUCCEEDED result has a null
            // errorKind, so the coalesce fired on every finished stream and
            // stamped "cancelled" on calls that had just returned a whole
            // draft. Nothing user-facing changes when that happens — the draft
            // arrives, the writer is happy — and only the metrics panel is
            // quietly wrong, which is why it reached the table and was found by
            // reading rows rather than by using the feature.
            //
            // A call nobody waited for is not a call that worked, and it is not
            // "unreachable" either: the host was there and generating. Its own
            // category, so an operator can tell a host that is failing from
            // users who keep changing their mind — opposite problems with
            // opposite fixes.
            $this->recorder->record(
                $workload,
                $model,
                null !== $result && $result->succeeded,
                null === $result ? self::ERROR_CANCELLED : $result->errorKind,
                null === $result ? AiCallTiming::none() : $result->timing,
            );
        }
    }

    /**
     * Is the configured host there, and is it holding what we named?
     *
     * Takes an optional override so the admin form can test an address BEFORE
     * it is saved — which is the only moment the answer is actually useful,
     * because saving a wrong one and then discovering it is the workflow this
     * button exists to remove.
     *
     * Deliberately ignores the master switch: an administrator setting this up
     * has not turned it on yet, and a test button that says "disabled" to
     * somebody who is mid-configuration is answering a question nobody asked.
     */
    public function probe(?string $baseUrl = null): AiProbe
    {
        $target = $baseUrl ?? $this->settings()->baseUrl;

        if (null === $target || '' === trim($target)) {
            return AiProbe::unreachable('no_host');
        }

        return $this->client->probe($target);
    }

    /**
     * Load the writing model now, so the next person to ask does not wait.
     *
     * WHY THE MASTER SWITCH IS NOT CONSULTED
     * ──────────────────────────────────────
     * probe()'s reasoning, and the same situation: an administrator setting
     * this up has not turned anything on yet, and the cost of a cold load is
     * precisely what they are trying to find out before they do. A button that
     * answered "disabled" would refuse at the only moment the number is worth
     * measuring.
     *
     * A HOST AND A MODEL ARE STILL REQUIRED, and they get separate answers
     * because they are separate mistakes with separate fixes — one is a field
     * above this button, the other is a different field above this button, and
     * "nothing happened" would send somebody to check the network for neither
     * of them.
     *
     * WHAT IT DELIBERATELY DOES NOT DO
     * ────────────────────────────────
     * It reads the SAVED settings, where the Test button reads what is on
     * screen. The two are different errands: Test answers "is this address any
     * good" before you commit to it, while this one asks a host to reserve
     * eighteen gigabytes on behalf of the configuration that is actually in
     * force. Warming a model nothing is going to use — because the form has not
     * been saved — would be the wrong machine doing real work for a setting
     * that does not exist yet.
     *
     * It also warms only the writing model. The search model is the one that is
     * cheap to pin and is pinned by default, and a second button for it would
     * be a control whose correct use is "never".
     */
    public function warmUp(): AiWarmUp
    {
        $settings = $this->settings();

        if (false === $settings->isConfigured()) {
            return AiWarmUp::failed('no_host');
        }

        $model = $settings->chatModel;

        if (null === $model || '' === trim($model)) {
            return AiWarmUp::failed('no_model');
        }

        return $this->client->preload(
            (string) $settings->baseUrl,
            trim($model),
            $settings->keepAliveFor(AiFeature::WritingHelp),
        );
    }
}
