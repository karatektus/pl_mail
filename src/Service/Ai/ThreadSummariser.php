<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiChatResult;
use App\Entity\Ai\AiFeature;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;

/**
 * Turns an open conversation into a prompt, and the answer into a summary.
 *
 * The WritingAssistant role for the reading pane, and deliberately its own
 * class rather than a fifth WritingTask. That enum's own docblock is the
 * argument: "the value arrives in a request body", so a `Summarise` case would
 * be instantly postable to /compose/assist against an arbitrary draft — a fifth
 * composer behaviour nobody designed, reachable by anyone who can POST. It
 * would also need a fifth arm in each of shippedPrompt(), instruction(),
 * draftLabel(), temperature() and hasEnoughToWorkFrom(), the last of which is
 * `'' !== $draft` and a summary has no draft at all.
 *
 * THE PROMPT ITSELF NO LONGER LIVES HERE. It was a private const, for
 * WritingAssistant's stated reason — "it is the part that will actually be
 * tuned, and hunting for it inside an HTTP action is how it ends up being
 * changed in one of three places" — and that reason now points the other way:
 * the part that is actually tuned is tuned by an administrator, in Admin → AI,
 * without a deploy. So the shipped wording is {@see \App\Domain\Ai\PromptRules::SUMMARY}
 * and what gets SENT comes from {@see PromptLibrary}, which is the only thing
 * that can resolve an override against it.
 *
 * NO PERSONA, AND THAT IS A DECISION RATHER THAN AN OMISSION
 * ─────────────────────────────────────────────────────────
 * WritingAssistant::persona() appends the writer's own notes to the app's
 * instructions, and its docblock says why that is safe there and nowhere else:
 * "the only party a writer can talk out of the rules is themselves, on their
 * own draft, which they read before they send it. It is the second reason
 * categorisation gets no persona at all."
 *
 * A summary is a statement about SOMEBODY ELSE'S mail, presented as fact, and
 * the person reading it does not read the mail underneath — that is the entire
 * point of the feature. Letting "how the writer has asked to be written for"
 * shape it is how you get a summary that is wrong in the direction the reader
 * asked for, with nothing on the page to say so. A summary is closer to
 * categorisation than to drafting, so it is treated like categorisation.
 *
 * There is a budget reason too, and it is the same one AiPreferences gives: a
 * 1200-character `aboutMe` is 1200 characters of thread that stop being
 * summarised, silently, because there is no num_ctx on the wire.
 */
final readonly class ThreadSummariser
{
    /**
     * How much of a conversation is sent, in characters. MEASURED, NOT CHOSEN.
     *
     * Two ceilings decide this, and the smaller one is the silent one.
     *
     * THE SILENT WINDOW. Nothing arrives from the host between the request and
     * the first token: the model loads, then the whole prompt is evaluated, and
     * only then does generation start. Measured on the reference host
     * (qwen3:30b-a3b-instruct-2507-q4_K_M, one GPU): prompt evaluation runs at
     * 95–107 tokens/second on a cold cache, real German business mail comes out
     * at 3.55 characters per token, and a cold load is 18.5 s. So 8000
     * characters is ~2250 tokens, ~23 s of prompt evaluation, and a silent
     * window of ~42 s cold. OllamaClient::GENERATE_TIMEOUT is 120 s and is
     * Symfony HttpClient's IDLE timeout, so that window is the only stretch
     * actually at risk — and 42 s leaves room for the embedding backfill to be
     * contending for the same GPU. At 12000 it is 53 s; at 16000, 63 s, which
     * is past half the ceiling on a machine that has other work.
     *
     * THE CONTEXT WINDOW, which is the one that fails without saying anything.
     * OllamaClient sends no `num_ctx` — AiPreferences' docblock names this as
     * the reason its own budgets are small — so the model's default decides
     * what survives, and Ollama's long-standing default is 4096 tokens. 8000
     * characters of transcript (~2250) plus the system prompt (~230) plus the
     * summary being generated (~350) is ~2830, which fits with room. At 12000
     * the same sum is ~3980: exactly at the line, where an installation on the
     * default would silently drop the HEAD of the conversation — the one part
     * ThreadTranscript is built forwards to protect. The reference host is
     * configured generously (verified to hold at least 16,652 tokens), but the
     * budget has to hold on the installation that is not.
     *
     * End to end at this value, measured: 63.6 s cold, 30.9 s warm.
     *
     * CHANGING THIS INVALIDATES EVERY STORED SUMMARY, and that is correct and
     * free. The staleness key is a hash of the transcript, so a different
     * budget produces a different transcript, produces a different hash, and
     * every existing row stops matching — which is what should happen, because
     * a summary built from different material is a different summary. It is
     * also why the budget needs no version field of its own.
     */
    public const int TRANSCRIPT_BUDGET = 8000;

    /**
     * The most a full-conversation run will send, in characters.
     *
     * "Full" is bounded, and the bound is the point rather than a caveat. The
     * whole reason this option exists is that the ordinary budget is sized for
     * a context window plMail cannot see and does not set — but the cure is
     * asking for a bigger window, and a window is MEMORY. Ollama allocates the
     * KV cache for the whole of num_ctx whether the prompt fills it or not, so
     * an unbounded "send everything" would let one absurd thread ask a modest
     * host for an allocation it cannot make, and the failure is the host
     * refusing or thrashing rather than anything plMail can catch.
     *
     * 96,000 characters is about 32,000 tokens at the 3.0 chars/token this
     * sizes with, which lands the request just inside FULL_MAX_CONTEXT_TOKENS
     * once the prompt and the answer are reserved. It is twelve times the
     * ordinary budget: a conversation that does not fit in it is not a thread
     * anybody is going to read either, and it still gets a summary — clipped,
     * and the card still says so.
     */
    public const int FULL_TRANSCRIPT_CEILING = 96000;

    /**
     * The largest context window a full run will ask a host for.
     *
     * Chosen as a number the models plMail is used with actually support and a
     * mid-range GPU can still allocate. Above this the honest move is to send
     * less rather than to ask for a window the host may not have — a request
     * that fails to allocate costs the reader the whole wait and produces
     * nothing, where a clipped transcript produces a summary and a sentence
     * saying it is partial.
     */
    private const int FULL_MAX_CONTEXT_TOKENS = 32768;

    /**
     * Characters per token, for sizing num_ctx only.
     *
     * DELIBERATELY BELOW the 3.55 measured on real German business mail, and
     * the direction matters: this divides characters to get tokens, so a
     * smaller number OVERESTIMATES how many tokens the transcript needs and
     * asks for a slightly larger window than strictly necessary. Erring the
     * other way would silently truncate the conversation inside a feature whose
     * entire purpose is not to, which is the failure this exists to end.
     */
    private const float CHARS_PER_TOKEN = 3.0;

    /** The system prompt plus room for the summary being written, in tokens. */
    private const int FULL_CONTEXT_RESERVE_TOKENS = 800;

    /**
     * Prompt-evaluation rate assumed when sizing a full run's patience, in
     * tokens per second.
     *
     * The reference host measured 95–107 on a cold cache AT THE ORDINARY
     * BUDGET, and that is the measurement's limit: prompt evaluation does not
     * hold its rate as the window grows, and this model is a mixture-of-experts
     * whose behaviour at 32k is not what it is at 2k. Sized at 70 on the
     * strength of the small-context figure, a full run still timed out.
     *
     * So: 25, which is a quarter of the measured rate and is chosen to be
     * WRONG IN THE SAFE DIRECTION rather than to be accurate. This number
     * divides tokens to get seconds, so a smaller one buys more time, and the
     * two outcomes are not symmetric — waiting too long costs a reader who
     * pressed a button marked "slower" some more of the same, while giving up
     * too early costs them the entire wait and produces nothing.
     *
     * It is a guess where a measurement belongs, and it is labelled as one.
     * OllamaClient now logs what it actually waited against what it was
     * allowed, so the next timeout carries the number this should have been.
     */
    private const int PROMPT_EVAL_TOKENS_PER_SECOND = 25;

    /**
     * Added on top, for the model being read off disk before any of it starts.
     *
     * A cold load of this class of model is around eighteen seconds on the
     * reference host and considerably worse off a spinning disk, and it lands
     * inside the same silence.
     */
    private const int COLD_LOAD_ALLOWANCE_SECONDS = 90;

    /**
     * How long to wait for a full run's first token.
     *
     * THIS IS THE CONSTANT THE FULL OPTION SHIPPED WITHOUT, and the omission
     * broke it outright: OllamaClient::GENERATE_TIMEOUT is 120 seconds and it
     * is an IDLE timeout, while a full run is by design one enormous silence.
     * Nothing arrives from the host between the request and the first token —
     * the model loads, the whole prompt is evaluated, and only then does
     * generation start — so the silent window scales with the transcript. At
     * the ordinary 8,000-character budget it is around 42 seconds cold, which
     * is what 120 was chosen to cover. At FULL_TRANSCRIPT_CEILING it is twelve
     * times that, and every full run on a large conversation was abandoned at
     * two minutes with `kind: timeout` while the host was still working.
     *
     * Sized from the same transcript as the context window rather than fixed,
     * for the same reason: a thread that is barely over the ordinary budget
     * should not wait as long as one at the ceiling.
     *
     * Never BELOW GENERATE_TIMEOUT, so a full run is never more impatient than
     * an ordinary one.
     */
    public static function timeoutFor(string $transcript): float
    {
        $tokens  = (int) ceil(mb_strlen($transcript) / self::CHARS_PER_TOKEN);
        $seconds = (int) ceil($tokens / self::PROMPT_EVAL_TOKENS_PER_SECOND) + self::COLD_LOAD_ALLOWANCE_SECONDS;

        return (float) min(
            self::FULL_MAX_WAIT_SECONDS,
            max((int) OllamaClient::GENERATE_TIMEOUT, $seconds),
        );
    }

    /**
     * The longest a full run may wait for its first token.
     *
     * Fifteen minutes, and the ceiling is here because the wait is not free to
     * anybody but the reader: a streamed response holds a PHP worker for the
     * whole of it. One deliberate press occupying one worker for a quarter of
     * an hour is a fair trade for a summary somebody asked for twice; an
     * unbounded one is a way to run out of workers.
     */
    private const int FULL_MAX_WAIT_SECONDS = 900;

    /**
     * The context window to ask for, for a transcript of this size.
     *
     * Rounded up to a whole number of 1024-token blocks, which is how these
     * windows are conventionally sized and keeps the request from looking like
     * a number somebody typed. Never below Ollama's own 4096 default: asking
     * for less than the host would have used anyway can only make a short
     * conversation worse.
     */
    public static function contextFor(string $transcript): int
    {
        $tokens = (int) ceil(mb_strlen($transcript) / self::CHARS_PER_TOKEN) + self::FULL_CONTEXT_RESERVE_TOKENS;
        $blocks = (int) ceil($tokens / 1024) * 1024;

        return max(4096, min(self::FULL_MAX_CONTEXT_TOKENS, $blocks));
    }

    /**
     * A conversation with one message in it is not summarised.
     *
     * Not a safety rule, a usefulness one: a "summary" of a single message
     * costs half a minute of GPU to tell somebody something reading the message
     * tells them faster, and the person is already looking at the message. The
     * pane makes the same check before it offers the button — the same number
     * it already uses two lines above to decide whether to show a message count
     * at all — so the control and the endpoint agree about what is on offer.
     */
    public const int MIN_MESSAGES = 2;

    /**
     * Zero, which is Proofread's value and for temperature()'s stated reason:
     * it is one of "the three that must not invent". A summary given room to be
     * creative writes a better-reading account of a conversation that did not
     * happen.
     */
    private const float TEMPERATURE = 0.0;

    /**
     * The gate, the door and the words — the pairing WritingAssistant explains:
     * a service holding only the gate could not make a call, and one holding
     * only the door could not ask whose mail this is.
     *
     * The library is the third because the prompt is no longer a constant here:
     * it is whatever an administrator has left in force, and a summariser that
     * could not read that would be summarising with instructions the settings
     * page says are not in use.
     */
    public function __construct(
        private AiAssistant   $ai,
        private AiPermissions $permissions,
        private PromptLibrary $prompts,
    ) {
    }

    /** Whether THIS person may be offered a summary. Both switches; see AiPermissions. */
    public function isAvailableFor(?User $user): bool
    {
        return $this->permissions->allows($user, AiFeature::Summary);
    }

    /**
     * Whether there is a conversation here at all.
     *
     * `messageCount` rather than a COUNT(*), because it is the number the pane
     * has already rendered beside the subject and the two must not disagree
     * about whether a button appears. It is recomputed on every deletion path
     * and incremented on ingest, so the worst a drift costs is one refused
     * summary — not a wrong one.
     */
    public static function hasEnoughToSummarise(MessageThread $thread): bool
    {
        return self::MIN_MESSAGES <= (int) $thread->messageCount;
    }

    /**
     * The model a summary written right now would be written by.
     *
     * The store key, and it has to come from here rather than from the
     * controller reading AiSettings itself: the tag that is STORED must be the
     * tag the call actually used, or a summary is filed under a model that did
     * not write it and survives the change that should have invalidated it.
     * One reader, one answer.
     *
     * The empty string when nothing is configured, which is unreachable through
     * the endpoint — isAvailableFor() is false without a chat model — and is
     * still spelled out rather than left to a null, because a store key is not
     * a place for a nullable.
     */
    public function model(): string
    {
        return (string) $this->ai->settings()->chatModel;
    }

    /**
     * A fingerprint of the prompt a summary written right now would be written
     * by — the second half of the store key, beside model().
     *
     * THIS REPLACED A HAND-BUMPED PROMPT_VERSION, AND HAD TO
     * ──────────────────────────────────────────────────────
     * There was an integer here whose docblock said "bumped whenever
     * SYSTEM_PROMPT below changes", and it worked for exactly as long as the
     * only way to change the prompt was to edit this file in a commit somebody
     * reviewed. An administrator editing the summary prompt in Admin → AI bumps
     * nothing, so every summary already on file would keep the old prompt's
     * output for ever while the pane called it fresh — a feature whose whole
     * risk is being confidently wrong about somebody else's mail, now also
     * wrong about which instructions produced it. A cache invalidated by a
     * human remembering is not invalidated.
     *
     * So the key stopped being a number somebody maintains and became a fact
     * about the request. It is the same move Version20260902100100 already made
     * for the transcript and gives its reasons for at length: `source_hash` is a
     * SHA-256 of the exact text sent to the model, so a conversation that
     * changed produces a different hash and every stale row stops matching
     * without anything having to notice. This is that, for the other half of
     * what was sent. Edit the summary prompt, edit the language rule, clear
     * either back to the shipped wording, or take an upgrade that improves the
     * shipped wording — each of those is a different string on the wire, so each
     * produces a different fingerprint, and every row written under the old one
     * stops being SHOWN. Nothing is deleted; the upsert replaces the row the
     * moment anybody re-summarises.
     *
     * SHA-256 and not crc32 or a substring of one: this sits in the same column
     * family as `source_hash`, is compared with hash_equals beside it, and a
     * second digest width in one table is a thing somebody would have to look
     * up. Hex, 64 characters, which is what the column was widened to hold.
     *
     * It reads the prompt from the same method messagesFor() sends, on purpose.
     * The fingerprint that is STORED must be of the prompt the call actually
     * used — model()'s own argument, unchanged: "one reader, one answer".
     */
    public function promptFingerprint(): string
    {
        return hash('sha256', $this->systemPrompt());
    }

    /**
     * The system message, resolved: what an administrator has typed if they
     * have, what we ship if they have not, with the language rule appended.
     *
     * Private and used by both messagesFor() and promptFingerprint(), which is
     * the whole point of it existing — two assemblies of one prompt is a stored
     * fingerprint that describes a string nobody was sent.
     */
    private function systemPrompt(): string
    {
        return $this->prompts->forSummary();
    }

    /**
     * Is the writing model already in the host's memory?
     *
     * The same question the composer asks, and here it is worth more: a cold
     * summary is around eighteen seconds of loading before a prompt that itself
     * takes another twenty-three to evaluate, and the whole of that produces
     * nothing on screen. A silent interface for forty seconds is
     * indistinguishable from a broken one.
     */
    public function isModelWarm(): bool
    {
        return $this->ai->isModelResident(AiFeature::Summary);
    }

    /**
     * The summary, arriving as it is written.
     *
     * Yields each token and returns the finished AiChatResult, whose content is
     * already tidied. Null means the request was refused before anybody was
     * asked anything — switched off, too short, or nothing to read.
     *
     * THE TRANSCRIPT IS A PARAMETER, NOT LOOKED UP
     * ────────────────────────────────────────────
     * Exactly as WritingAssistant takes `$context` from ComposeAssistController
     * rather than reading it: the caller has to resolve an id and refuse a
     * stranger's mail before this can be reached, and by the time a streamed
     * response callback is running there is no controller left to turn a denied
     * ownership check into a 403. It is also the same string the caller hashed,
     * which is what makes a stored summary describe what was actually sent.
     *
     * THE USER IS IN THE SIGNATURE FOR WritingAssistant'S REASON
     * ──────────────────────────────────────────────────────────
     * "It is not possible to assemble a prompt here without having named whose
     * mail it is, so the per-user switch cannot be forgotten by a caller added
     * later."
     *
     * @return \Generator<int, string, void, AiChatResult>|null
     */
    public function stream(User $user, MessageThread $thread, string $transcript, bool $full = false): ?\Generator
    {
        $messages = $this->messagesFor($user, $thread, $transcript);

        if (null === $messages) {
            return null;
        }

        // num_ctx ONLY for a full run, and sized from the transcript in hand.
        //
        // Not sent otherwise, so every ordinary summary keeps the shape it has
        // always had — the budget above is chosen to fit the 4096 default and
        // sending a window would change nothing except the memory the host
        // reserves. A full run is the case where the default is the problem,
        // and it is the reader's own deliberate press.
        $tokens = $this->ai->chatStream(
            AiFeature::Summary,
            $messages,
            self::TEMPERATURE,
            true === $full ? self::contextFor($transcript) : null,
            // The patience goes with the window, and both only on a full run.
            // A larger window is a longer silence before the first token, and
            // sending one without the other is what made the option fail every
            // time it was used on a conversation big enough to need it.
            true === $full ? self::timeoutFor($transcript) : null,
        );

        // Not a generator function, for the reason AiAssistant::chatStream()
        // gives: the refusals above have to happen when this is CALLED, not
        // when somebody gets round to iterating it. A caller that never
        // iterated would otherwise have been silently permitted.
        return null === $tokens ? null : $this->tidied($tokens);
    }

    /**
     * The prompt, or null when there is nothing worth sending.
     *
     * @return list<array{role: string, content: string}>|null
     */
    private function messagesFor(User $user, MessageThread $thread, string $transcript): ?array
    {
        if (false === $this->isAvailableFor($user)) {
            return null;
        }

        if (false === self::hasEnoughToSummarise($thread)) {
            return null;
        }

        if ('' === trim($transcript)) {
            // A thread whose messages are all body-less. Refused before
            // anybody is asked, the way WritingAssistant refuses an empty
            // composer under no message: a model handed nothing invents mail.
            return null;
        }

        return [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => "The conversation:\n" . $transcript . "\n\nSummarise it."],
        ];
    }

    /**
     * Tokens raw on the wire, tidy() applied only to the return.
     *
     * WritingAssistant::stream()'s argument, unchanged: a code fence is
     * recognisable only from both ends — the opening ``` is the first token out
     * of the model and the closing one the last — so tidying per token would
     * mean holding the first tokens back to see whether they turn out to be a
     * fence, which spends the one thing streaming buys.
     *
     * It matters more here than there, because what the return carries is what
     * gets STORED. A stored summary with a stray fence in it is on the page
     * every time the thread is opened until somebody regenerates it.
     *
     * @param \Generator<int, string, void, AiChatResult> $tokens
     *
     * @return \Generator<int, string, void, AiChatResult>
     */
    private function tidied(\Generator $tokens): \Generator
    {
        foreach ($tokens as $token) {
            yield $token;
        }

        $result = $tokens->getReturn();

        // A failure carries no content to tidy, and rebuilding it would lose
        // the category the caller needs to say what went wrong.
        if (false === $result->succeeded || null === $result->content) {
            return $result;
        }

        $tidied = self::tidy($result->content);

        // Everything the model said was a code fence and nothing else. ok()
        // promises content, and an empty summary is not an answer — it must not
        // reach the store, where it would render as a blank card that looks
        // like a working feature.
        if ('' === $tidied) {
            return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE, $result->timing);
        }

        return AiChatResult::ok($tidied, $result->timing);
    }

    /**
     * Strip what a model adds when it cannot help itself.
     *
     * The same two unambiguous things WritingAssistant::tidy() removes, and
     * nothing else — guessing at prose would eventually delete a line somebody
     * wanted. Duplicated rather than shared because the two live on opposite
     * sides of a boundary this codebase keeps deliberately: WritingAssistant is
     * the composer's, and merging them would be one class that had to know
     * about both a draft and a reading pane.
     */
    private static function tidy(string $answer): string
    {
        $text = trim($answer);

        if (1 === preg_match('/^```[a-z]*\n(.*)\n```$/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        return $text;
    }
}
