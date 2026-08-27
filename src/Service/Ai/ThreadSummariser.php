<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\Ai\PromptRules;
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
 * would also need a fifth arm in each of taskPrompt(), instruction(),
 * draftLabel(), temperature() and hasEnoughToWorkFrom(), the last of which is
 * `'' !== $draft` and a summary has no draft at all.
 *
 * The prompt lives here rather than in the controller for WritingAssistant's
 * stated reason: it is the part that will actually be tuned, and hunting for it
 * inside an HTTP action is how it ends up being changed in one of three places.
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
     * Bumped whenever SYSTEM_PROMPT below changes.
     *
     * The analogue of `dimensions` in EmbeddingStore, and the argument
     * transfers: "two models can share a name across an upgrade and answer at a
     * different width." Two summaries can share a model name across a prompt
     * edit and mean different things — one written before "never infer an
     * outcome that is not written down" was added, one after — and the second
     * is not a better version of the first, it is a different answer. Every
     * read filters on this, so bumping it makes every older summary invisible
     * and nothing has to be deleted.
     *
     * The transcript's own shape is NOT versioned here, deliberately: it is
     * already covered by the hash, and two mechanisms for one fact is the thing
     * Version20260828000000 refuses.
     */
    public const int PROMPT_VERSION = 1;

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
     * What a summary IS, in the register WritingTask's four arms use.
     *
     * Three of these sentences are load-bearing and the reasons differ.
     *
     * "Plain text, no markdown" because the card renders with textContent and
     * never innerHTML — this is model output derived from somebody's mail — so
     * asterisks and hashes would arrive as literal asterisks and hashes on the
     * page rather than as emphasis.
     *
     * "State only what the messages actually say" because the failure mode of a
     * summary is not being unhelpful, it is being confidently wrong about an
     * outcome — "they agreed to Thursday" for a thread where Thursday was
     * proposed and never answered. The reader does not read the mail
     * underneath; that is the whole point of the feature and the whole of the
     * risk. The explicit instruction to SAY when something was left unanswered
     * gives the model somewhere to put an open question other than into an
     * invented conclusion.
     *
     * "Name people the way the messages name them" because a summary that
     * paraphrases senders into roles ("the supplier", "the client") is not
     * checkable against the thread it sits above.
     */
    private const string SYSTEM_PROMPT = 'You summarise email conversations. Write only the summary,'
        . ' as plain text: no markdown, no headings, no bullet characters, no preamble about what you'
        . ' are doing. Begin with a short paragraph saying what the conversation is about and where it'
        . ' has got to. If there are open questions, decisions, or things somebody has been asked to'
        . ' do, list them after that paragraph as short lines, one per line. State only what the'
        . ' messages actually say — never infer an outcome that is not written down, and say so when'
        . ' something was asked and left unanswered. Name people the way the messages name them.';

    /**
     * Zero, which is Proofread's value and for temperature()'s stated reason:
     * it is one of "the three that must not invent". A summary given room to be
     * creative writes a better-reading account of a conversation that did not
     * happen.
     */
    private const float TEMPERATURE = 0.0;

    /**
     * The gate and the door, both — the pairing WritingAssistant explains: a
     * service holding only the gate could not make a call, and one holding only
     * the door could not ask whose mail this is.
     */
    public function __construct(
        private AiAssistant   $ai,
        private AiPermissions $permissions,
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
    public function stream(User $user, MessageThread $thread, string $transcript): ?\Generator
    {
        $messages = $this->messagesFor($user, $thread, $transcript);

        if (null === $messages) {
            return null;
        }

        $tokens = $this->ai->chatStream(AiFeature::Summary, $messages, self::TEMPERATURE);

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
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT . PromptRules::LANGUAGE],
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
