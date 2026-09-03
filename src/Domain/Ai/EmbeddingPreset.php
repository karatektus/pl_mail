<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Embedding models this release has actually been measured against.
 *
 * WHY A LIST AT ALL, ON A FIELD THAT STAYS FREE TEXT
 * ──────────────────────────────────────────────────
 * Naming an embedding model is not like naming a chat model. A chat model that
 * is a poor fit answers badly and everybody can see it. An embedding model that
 * is a poor fit answers with a full page of confident, ordered, entirely
 * irrelevant results, and nothing anywhere says so — the search looks like it
 * works and is simply wrong, which is the failure this whole area keeps
 * producing.
 *
 * Two settings decide whether it works, neither of them guessable:
 *
 *  1. THE QUERY INSTRUCTION. Modern embedding models are asymmetric: they are
 *     trained to be told which side of a search they are looking at. Send a
 *     bare query to one that expects an instruction and it lands in generic
 *     document space, where the nearest neighbours are whatever is most
 *     boilerplate — in a mailbox, that is login links and newsletters.
 *
 *  2. THE THRESHOLD. Cosine similarity is not comparable between models, or
 *     even between the instructed and uninstructed forms of ONE model. There is
 *     no number that is right twice.
 *
 * So the field stays free text — anybody may run anything, and a list that
 * refused unknown names would be a list that goes stale between releases — and
 * choosing a name from here fills in the two values that go with it.
 *
 * WHERE THESE NUMBERS COME FROM
 * ─────────────────────────────
 * Measured, not inherited from model cards. Twelve messages in German and
 * English — five about food, seven the ordinary transactional traffic any
 * mailbox is mostly made of — against three queries, scored for every threshold
 * between 0.20 and 0.89 and kept at the best F1:
 *
 *   qwen3-embedding:0.6b  no instruction      0.20   F1 0.59   precision 0.42
 *   qwen3-embedding:0.6b  with instruction    0.42   F1 0.85   precision 1.00
 *   nomic-embed-text      no instruction      0.45   F1 0.74   precision 0.65
 *   nomic-embed-text      with instruction    0.46   F1 0.74   precision 0.65
 *   all-minilm            no instruction      0.20   F1 0.71   precision 0.77
 *
 * The first two rows are the argument for this class existing. Same model, same
 * corpus, same queries: the instruction moves the useful threshold from 0.20 to
 * 0.42 and precision from 0.42 to 1.00. A single shipped constant could not
 * have been right for both, and plMail shipped one — 0.55, which is above every
 * number in that table and was letting the wrong mail through anyway.
 */
enum EmbeddingPreset: string
{
    /**
     * The default, and multilingual is why.
     *
     * Trained across a hundred-odd languages, which matters more here than any
     * benchmark score: a mailbox is not in one language, and a model that only
     * really speaks English answers German mail by accident. It is also the
     * only combination in the table above that reached precision 1.00.
     *
     * Instruction-conditioned, and unusually well behaved about it — the
     * instruction goes on the QUERY ONLY and documents are embedded raw, which
     * is what plMail already stores. Switching to it costs a re-index because
     * the model changed; changing nothing else about it ever will.
     */
    case Qwen3Embedding06b = 'qwen3-embedding:0.6b';

    /**
     * What plMail recommended before this, and it is kept for the installations
     * that are already on it rather than because it wins anything.
     *
     * HALF-SUPPORTED, HONESTLY. Nomic wants `search_query:` on queries and
     * `search_document:` on documents, and plMail applies query instructions
     * only. Measured, the query prefix alone moves nothing at all — F1 0.74
     * either way — because the asymmetry it signals is not there unless both
     * halves are labelled. Supporting it properly means a document instruction,
     * which means re-embedding every message in every mailbox on the
     * installation. That is an operator's decision and a separate piece of
     * work, so it is not smuggled in here.
     */
    case NomicEmbedText = 'nomic-embed-text';

    /**
     * The small one. 384 dimensions against Qwen's 1024, 45 MB against 639.
     *
     * Here for the machine that cannot spare the memory, and listed with its
     * threshold because that is the part nobody could have guessed: 0.20, less
     * than half of what the same field wants for Qwen. Somebody moving to this
     * to save memory and leaving the threshold alone would get a search that
     * matches nothing at all and no explanation of why.
     *
     * English-first. On German mail expect it to be the weakest thing here.
     */
    case AllMiniLm = 'all-minilm';

    /**
     * What goes in front of the search text before it is embedded.
     *
     * Empty for a model that was not trained to be instructed — and empty is a
     * real answer, not a missing one. Prefixing a model that does not expect it
     * adds tokens that mean nothing to it and shifts every score by an amount
     * nobody has measured.
     */
    public function queryInstruction(): string
    {
        return match ($this) {
            // Qwen's own documented shape, kept verbatim including the newline:
            // the model was trained on `Instruct: {task}\nQuery: {query}` and
            // the line break is part of what it learned, not formatting.
            self::Qwen3Embedding06b => "Instruct: Given a search query, retrieve email messages that are relevant to it\nQuery: ",
            self::NomicEmbedText    => 'search_query: ',
            self::AllMiniLm         => '',
        };
    }

    /** How close a match has to be, on the scale THIS model answers on. */
    public function minSimilarity(): float
    {
        return match ($this) {
            self::Qwen3Embedding06b => 0.42,
            self::NomicEmbedText    => 0.45,
            self::AllMiniLm         => 0.20,
        };
    }

    /** Translation key for the one line describing what it is good and bad at. */
    public function summaryKey(): string
    {
        return 'admin.ai.embedding_preset.' . str_replace([':', '.'], ['_', '_'], $this->value);
    }

    /**
     * The presets in the order the admin panel offers them, best first.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Qwen3Embedding06b, self::NomicEmbedText, self::AllMiniLm];
    }

    /**
     * The preset for a configured model name, or null for anything else.
     *
     * Null is the ordinary case on an installation running something not listed
     * here, and it means "the operator's own two values stand". It must never
     * mean "fall back to Qwen's numbers": Qwen's threshold on somebody else's
     * model is a number from the wrong scale, which is the entire failure this
     * class exists to end.
     */
    public static function forModel(?string $model): ?self
    {
        return null === $model ? null : self::tryFrom(trim($model));
    }
}
