<?php

declare(strict_types=1);

namespace App\Service\Insight;

use App\Entity\User\User;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every extractor the build knows, and which of them a given user has left
 * on.
 *
 * The settings page renders from all() and the harvester runs enabledFor(),
 * which is what makes a new extractor appear in settings the moment its class
 * exists — the page lists the registry, not a hand-kept catalogue.
 *
 * Everything is ON until switched off, and the preference stores the
 * DISABLED keys (User::SETTING_INSIGHTS_DISABLED): an extractor added next
 * release starts working for everyone instead of waiting for each user to
 * discover a new toggle — the same rule the sidebar's collapse state follows,
 * for the same reason.
 */
final readonly class InsightExtractorRegistry
{
    /** @param iterable<InsightExtractorInterface> $extractors */
    public function __construct(
        #[AutowireIterator('app.insight_extractor')]
        private iterable $extractors,
    ) {
    }

    /** @return list<InsightExtractorInterface> highest priority first */
    public function all(): array
    {
        $all = [...$this->extractors];

        usort($all, static fn (InsightExtractorInterface $a, InsightExtractorInterface $b): int => $b->priority() <=> $a->priority());

        return $all;
    }

    /** @return list<InsightExtractorInterface> */
    public function enabledFor(User $user): array
    {
        $disabled = $this->disabledKeys($user);

        return array_values(array_filter(
            $this->all(),
            static fn (InsightExtractorInterface $extractor): bool => false === in_array($extractor::key(), $disabled, true),
        ));
    }

    public function isEnabledFor(User $user, string $key): bool
    {
        return false === in_array($key, $this->disabledKeys($user), true);
    }

    /** @return list<string> */
    private function disabledKeys(User $user): array
    {
        $stored = $user->getSetting(User::SETTING_INSIGHTS_DISABLED);

        return is_array($stored) ? array_values(array_filter($stored, is_string(...))) : [];
    }
}
