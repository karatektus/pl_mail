<?php

declare(strict_types=1);

namespace App\Domain\DTO\System;

use App\Domain\Enum\System\UpdateChannel;
use App\Domain\Enum\System\UpdateVerdict;

/**
 * The answer of one update check: what the channel's newest build is, and what
 * it is to the build that asked.
 *
 * Kept as a document on the check's row rather than as columns, because it is
 * read whole and never queried: the admin page renders it and the header asks
 * one question of it.
 */
final readonly class UpdateStatus
{
    /** How many "what's new" lines are kept; the rest is one link away. */
    public const int CHANGES_KEPT = 12;

    /**
     * @param list<array{sha: string, subject: string}> $changes newest first
     */
    public function __construct(
        public UpdateVerdict   $verdict,
        public UpdateChannel   $channel,
        /** The full commit of the build that asked. Null for an unbuilt checkout. */
        public ?string         $running,
        public PublishedBuild  $latest,
        /** How many commits the channel is ahead, when the history could be asked. */
        public ?int            $aheadBy = null,
        public array           $changes = [],
        public ?string         $compareUrl = null,
    ) {
    }

    /**
     * Whether this answer is about the build that is running now.
     *
     * After an upgrade the stored answer describes the build that was replaced,
     * and "an update is available" would be announcing the very build now
     * running. Until the next check it says nothing rather than that.
     */
    public function isAbout(?string $running): bool
    {
        return $this->running === $running;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict'    => $this->verdict->value,
            'channel'    => $this->channel->value,
            'running'    => $this->running,
            'latest'     => $this->latest->toArray(),
            'aheadBy'    => $this->aheadBy,
            'changes'    => $this->changes,
            'compareUrl' => $this->compareUrl,
        ];
    }

    /**
     * The stored document, read charitably: a row written by another version
     * of this class, or by hand, is no answer rather than an error.
     *
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): ?self
    {
        if (null === $data) {
            return null;
        }

        $verdict = UpdateVerdict::tryFrom((string) ($data['verdict'] ?? ''));
        $channel = UpdateChannel::tryFrom((string) ($data['channel'] ?? ''));
        $latest  = is_array($data['latest'] ?? null) ? PublishedBuild::fromArray($data['latest']) : null;

        if (null === $verdict || null === $channel || null === $latest) {
            return null;
        }

        $changes = [];

        foreach (is_array($data['changes'] ?? null) ? $data['changes'] : [] as $change) {
            if (is_array($change) && is_string($change['sha'] ?? null) && is_string($change['subject'] ?? null)) {
                $changes[] = ['sha' => $change['sha'], 'subject' => $change['subject']];
            }
        }

        return new self(
            $verdict,
            $channel,
            is_string($data['running'] ?? null) ? $data['running'] : null,
            $latest,
            is_int($data['aheadBy'] ?? null) ? $data['aheadBy'] : null,
            $changes,
            is_string($data['compareUrl'] ?? null) ? $data['compareUrl'] : null,
        );
    }
}
