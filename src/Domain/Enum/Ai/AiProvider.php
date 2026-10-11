<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

/**
 * Which dialect a model host speaks.
 *
 * Two, because there are two that matter to somebody running their own mail
 * client. Ollama is the one plMail was built against and the one it knows the
 * most about: what is in memory, how long a model stays there, how to load one
 * ahead of time. The other is the request shape OpenAI published, which nearly
 * everything else has since copied — llama.cpp's server, vLLM, LM Studio,
 * LiteLLM, the hosted providers, and Ollama itself under `/v1`.
 *
 * WHY "COMPATIBLE" AND NOT A LIST OF VENDORS
 * ──────────────────────────────────────────
 * A list goes stale between releases and refuses a server that works. What is
 * actually being chosen is a wire format, and an administrator who knows their
 * endpoint speaks it does not need plMail to have heard of it.
 *
 * WHAT THE SECOND ONE CANNOT DO
 * ─────────────────────────────
 * The format has no way to ask what is loaded, to hold a model in memory, or
 * to load one before it is needed, and no way to size a context window per
 * request. Everything on Admin → AI that is about those — the keep-alive
 * fields, Warm up now, the "in memory" half of the panel — is Ollama's alone
 * and is put away when this is the other case. {@see managesMemory()}.
 */
enum AiProvider: string
{
    case Ollama = 'ollama';

    /** Anything that answers `/chat/completions` and `/embeddings`. */
    case OpenAi = 'openai';

    /**
     * Whether plMail can see and steer what this host keeps in memory.
     *
     * One question asked in a dozen places, and asked this way rather than as
     * `Ollama === $provider` so that the places read as what they depend on. A
     * third dialect would answer it for itself.
     */
    public function managesMemory(): bool
    {
        return self::Ollama === $this;
    }

    /** The translation key for the name shown in the provider field. */
    public function label(): string
    {
        return 'admin.ai.provider.' . $this->value;
    }
}
