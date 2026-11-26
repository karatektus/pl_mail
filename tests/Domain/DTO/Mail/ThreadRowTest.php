<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Mail;

use App\Domain\DTO\Mail\ThreadRow;
use App\Domain\DTO\Mail\ThreadRowMessage;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The date the Drafts list shows for a row: when its draft was written, not
 * when the conversation last had mail. A reply written just now to last week's
 * mail read "last week".
 */
final class ThreadRowTest extends TestCase
{
    public function testTheDraftDateIsTheNewestDraftsLastSave(): void
    {
        $row = new ThreadRow([
            $this->message(false, receivedAt: '2026-01-01 09:00'),
            $this->message(true, updatedAt: '2026-03-01 12:00'),
            $this->message(true, updatedAt: '2026-02-01 12:00'),
        ], '');

        self::assertEquals(new DateTimeImmutable('2026-03-01 12:00'), $row->draftAt);
    }

    /** A draft synced from another client is dated by the provider, not by its sync here. */
    public function testASyncedDraftKeepsItsProviderDate(): void
    {
        $row = new ThreadRow([
            $this->message(true, receivedAt: '2026-02-01 09:00', updatedAt: '2026-03-01 12:00'),
        ], '');

        self::assertEquals(new DateTimeImmutable('2026-02-01 09:00'), $row->draftAt);
    }

    public function testARowWithoutADraftHasNoDraftDate(): void
    {
        $row = new ThreadRow([$this->message(false, receivedAt: '2026-01-01 09:00')], '');

        self::assertNull($row->draftAt);
    }

    private function message(bool $isDraft, ?string $receivedAt = null, ?string $updatedAt = null): ThreadRowMessage
    {
        return new ThreadRowMessage(
            id: 1,
            fromAddress: 'me@example.test',
            fromName: null,
            toAddresses: null,
            isDraft: $isDraft,
            receivedAt: null !== $receivedAt ? new DateTimeImmutable($receivedAt) : null,
            sentAt: null,
            createdAt: new DateTimeImmutable('2025-12-01 09:00'),
            submissionSendAt: null,
            updatedAt: null !== $updatedAt ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
