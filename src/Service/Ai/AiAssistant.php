<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiProbe;
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
    public function __construct(
        private AiSettingsRepository $settings,
        private OllamaClient         $client,
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
     * all of them is the same: carry on without it. Anything that wants to
     * explain the difference to a person asks probe() instead.
     *
     * @return list<float>|null
     */
    public function embed(string $text): ?array
    {
        $settings = $this->settings();

        if (false === $settings->enabledFor(AiFeature::Search)) {
            return null;
        }

        if ('' === trim($text)) {
            return null;
        }

        return $this->client->embed(
            (string) $settings->baseUrl,
            (string) $settings->embeddingModel,
            $text,
        );
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

        return $this->client->chat(
            (string) $settings->baseUrl,
            (string) $settings->chatModel,
            $messages,
            $temperature,
        );
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
}
