<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiChatResult;
use App\Domain\DTO\Ai\TranslationSource;
use App\Domain\Enum\Ai\TranslationLanguage;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Domain\Helper\ReadableBody;
use App\Entity\Ai\AiFeature;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Crypto\DecryptedMailPolicy;

/**
 * Translating one message into the reader's language, on request (#713).
 *
 * ThreadSummariser's shape, and its docblocks give the reasons this repeats:
 * the gate, the door and the prompt library in the constructor; model() and
 * promptFingerprint() as the store key, read from the same place the call
 * reads them; the source a parameter of stream(), already hashed by the caller
 * that checked whose mail it is; tokens raw on the wire and tidy() applied only
 * to what is stored.
 *
 * WHAT DIFFERS IS THE LENGTH OF THE ANSWER. A summary is three lines; a
 * translation is as long as the mail. That is why this always asks for a
 * context window and why the mail it sends is capped lower than a summary's
 * transcript; see CONTEXT_TOKENS and TEXT_BUDGET.
 */
final readonly class MessageTranslator
{
    /**
     * The most of a mail that is sent, in characters.
     *
     * Lower than ThreadSummariser::TRANSCRIPT_BUDGET because the answer is as
     * long as the question: 6,000 characters of Hebrew are roughly 3,000
     * tokens in and as many out, which together fit CONTEXT_TOKENS with the
     * prompt beside them. A longer mail is cut at a line break and the page
     * says that only its beginning was translated.
     *
     * Changing this changes the text sent, so it invalidates the stored
     * translations of long mails by itself, through their hash.
     */
    public const int TEXT_BUDGET = 6000;

    /**
     * The context window every translation asks for.
     *
     * ALWAYS SENT, unlike the summary's, because without it the answer is what
     * runs out: Ollama's default window holds a long mail and only part of its
     * translation, and the model stops mid-sentence with nothing to say so.
     *
     * ONE NUMBER FOR EVERY TRANSLATION, rather than sized per mail the way a
     * full summary's is, because Ollama reloads a model whenever the window it
     * is asked for differs from the one it has loaded. Sized per mail, two
     * translations in a row would mean two loads, which on a host without a GPU
     * is most of the wait.
     */
    public const int CONTEXT_TOKENS = 8192;

    /**
     * How long a translation may hold the request, in seconds.
     *
     * The answer is generated token by token on the request, and a whole mail
     * on a host without a GPU is minutes of it: PHP's ordinary limit would end
     * the request halfway through a translation it was still receiving. Fifteen
     * minutes is ThreadSummariser's ceiling for a full run, for the reason it
     * gives — one streamed answer holds one PHP worker for as long as it lasts.
     */
    public const int TIME_LIMIT_SECONDS = 900;

    /**
     * Zero, Proofread's and the summary's value: a translation is one of the
     * jobs that must not invent.
     */
    private const float TEMPERATURE = 0.0;

    public function __construct(
        private AiAssistant         $ai,
        private AiPermissions       $permissions,
        private PromptLibrary       $prompts,
        private DecryptedMailPolicy $decrypted,
    ) {
    }

    /** Whether THIS person may be offered translations at all. Both switches; see AiPermissions. */
    public function isAvailableFor(?User $user): bool
    {
        return $this->permissions->allows($user, AiFeature::Translation);
    }

    /**
     * Whether this message may be sent for translation: the person may, the
     * server holds its text, and it is not a message whose text only a browser
     * could read and whose account keeps it from the model. See
     * DecryptedMailPolicy.
     */
    public function isAvailableForMessage(?User $user, Message $message): bool
    {
        return $this->isAvailableFor($user) && $this->allowsMessage($message);
    }

    /**
     * The half of isAvailableForMessage() that is about the message, for a
     * caller that has asked about the person once already: the conversation
     * view asks for every message it draws, and the person's half reads the
     * settings from the database each time.
     */
    public function allowsMessage(Message $message): bool
    {
        return self::hasReadableBody($message) && $this->decrypted->allowsAi($message, AiFeature::Translation);
    }

    /**
     * Whether the server holds this message's text at all. Not for the three
     * cases mail/_message_body.html.twig draws without a body of its own: an
     * encrypted message the browser decrypts, a draft sealed in the browser,
     * and an encrypted message that is still being sent.
     */
    public static function hasReadableBody(Message $message): bool
    {
        if (true === $message->sealedDraft || 'encrypted' === ($message->outgoingSeal['kind'] ?? null)) {
            return false;
        }

        if (PlaintextSource::Client === $message->plaintextSource) {
            return true;
        }

        return true !== $message->crypto?->isEncrypted();
    }

    /** The language this person reads translations in: their choice, or their interface language. */
    public static function targetFor(User $user, ?string $interfaceLocale): TranslationLanguage
    {
        return TranslationLanguage::tryFrom((string) $user->aiPreferences->translateInto)
            ?? TranslationLanguage::forInterface($interfaceLocale);
    }

    /**
     * The words that are sent.
     *
     * What the sender wrote in THIS message, without the quoted history of a
     * reply: that history is earlier mail, shown and translated on its own in
     * the conversation, and sending it again would double the wait for words
     * the reader has already seen. A forward or a reply that says nothing of
     * its own falls back to the whole mail, because then the history is the
     * message.
     */
    public static function sourceOf(Message $message): TranslationSource
    {
        $whole = ReadableBody::of($message);
        $own   = ReadableBody::ownWordsOf($message);
        $text  = '' === $own ? $whole : $own;

        $leavesOutQuote = '' !== $own && mb_strlen($own) < mb_strlen($whole);
        $isPartial      = mb_strlen($text) > self::TEXT_BUDGET;

        return new TranslationSource(
            true === $isPartial ? self::clipped($text) : $text,
            $isPartial,
            $leavesOutQuote,
        );
    }

    /**
     * The model a translation written right now would be written by; the store
     * key, read where the call reads it. See ThreadSummariser::model().
     */
    public function model(): string
    {
        return (string) $this->ai->settings()->chatModel;
    }

    /** The second half of the store key. See ThreadSummariser::promptFingerprint(). */
    public function promptFingerprint(): string
    {
        return hash('sha256', $this->prompts->forTranslation());
    }

    /** Whether the writing model is already loaded; a cold one is a silence first. */
    public function isModelWarm(): bool
    {
        return $this->ai->isModelResident(AiFeature::Translation);
    }

    /**
     * The translation, arriving as it is written. Yields each token and returns
     * the finished AiChatResult, its content tidied; null when it was refused
     * before anybody was asked.
     *
     * Not a generator function itself, for ThreadSummariser::stream()'s reason:
     * the refusals have to happen when this is called.
     *
     * @return \Generator<int, string, void, AiChatResult>|null
     */
    public function stream(User $user, Message $message, TranslationLanguage $into, TranslationSource $source): ?\Generator
    {
        if (false === $this->isAvailableForMessage($user, $message) || '' === trim($source->text)) {
            // An empty mail is refused before anybody is asked: a model handed
            // nothing to translate writes something.
            return null;
        }

        $tokens = $this->ai->chatStream(
            AiFeature::Translation,
            [
                ['role' => 'system', 'content' => $this->prompts->forTranslation()],
                ['role' => 'user', 'content' => sprintf("The email:\n%s\n\nTranslate it into %s.", $source->text, $into->englishName())],
            ],
            self::TEMPERATURE,
            self::CONTEXT_TOKENS,
            // The silence before the first token grows with the mail, as for a
            // full summary, and the same rule sizes the patience for it.
            ThreadSummariser::timeoutFor($source->text),
        );

        return null === $tokens ? null : $this->tidied($tokens);
    }

    /**
     * Tokens raw on the wire, tidy() applied to the return only. See
     * ThreadSummariser::tidied(): what the return carries is what is stored.
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

        if (false === $result->succeeded || null === $result->content) {
            return $result;
        }

        $tidied = trim($result->content);

        if (1 === preg_match('/^```[a-z]*\n(.*)\n```$/s', $tidied, $matches)) {
            $tidied = trim($matches[1]);
        }

        if ('' === $tidied) {
            return AiChatResult::failed(OllamaClient::ERROR_BAD_RESPONSE, $result->timing);
        }

        return AiChatResult::ok($tidied, $result->timing);
    }

    /**
     * The first TEXT_BUDGET characters, ended at the last line break in their
     * final fifth so the model is not handed half a sentence; at the budget
     * itself where there is none.
     */
    private static function clipped(string $text): string
    {
        $cut   = mb_substr($text, 0, self::TEXT_BUDGET);
        $break = mb_strrpos($cut, "\n");

        if (false !== $break && $break > (int) (self::TEXT_BUDGET * 0.8)) {
            $cut = mb_substr($cut, 0, $break);
        }

        return rtrim($cut);
    }
}
