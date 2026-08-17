<?php

declare(strict_types=1);

namespace App\Tests\Support\Insight;

use App\Entity\Mail\Message;
use App\Service\Insight\InsightExtractorInterface;

/**
 * A registry entry the insight settings tests can rely on.
 *
 * The settings page and its toggle endpoint are defined over "whatever the
 * registry holds", and the registry tolerates being empty — so a build where
 * no real extractor has shipped yet would leave InsightSettingsTest with no
 * key to toggle and nothing to assert. This guarantees one, whatever the
 * production catalogue currently is, the same reason ScriptedCalendarSyncDriver
 * exists one entry up in services_test.yaml.
 *
 * Inert on purpose: supports() never says yes, so the harvester never calls
 * extract() and no test mail ever grows a scripted insight card. The only
 * place this class is visible is the registry — one extra settings row in the
 * test environment, whose name renders as its key because no catalogue entry
 * exists for it, which is fine for a row no user ever sees.
 */
final class ScriptedInsightExtractor implements InsightExtractorInterface
{
    public static function key(): string
    {
        return 'scripted';
    }

    public function icon(): string
    {
        return 'fa-solid fa-flask';
    }

    /** Last, so it never shadows a real extractor's claim on a message. */
    public function priority(): int
    {
        return -100;
    }

    public function supports(Message $message): bool
    {
        return false;
    }

    public function extract(Message $message): array
    {
        return [];
    }
}
