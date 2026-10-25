<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\SidebarRow;
use PHPUnit\Framework\TestCase;

/**
 * The nine places the sidebar's system block can show, and which of them wait
 * behind "More" for somebody who has never chosen.
 *
 * The default is the thing to hold still: it is what every existing user is
 * looking at today, so a change to it moves their sidebar without their having
 * touched anything.
 */
final class SidebarRowTest extends TestCase
{
    /**
     * The order the rows are drawn in, top group and More group alike — the
     * case order IS the display order, and nothing else says it.
     */
    public function testTheCasesAreInTheOrderTheSidebarDrawsThem(): void
    {
        self::assertSame(
            ['inbox', 'unread', 'starred', 'sent', 'drafts', 'snoozed', 'archive', 'spam', 'trash'],
            array_map(static fn (SidebarRow $row): string => $row->value, SidebarRow::cases()),
        );
    }

    /** What the sidebar hard-coded before this was a choice. */
    public function testTheDefaultIsTheBlockThatWasAlwaysBehindMore(): void
    {
        self::assertSame(
            [SidebarRow::Snoozed, SidebarRow::Archive, SidebarRow::Spam, SidebarRow::Trash],
            SidebarRow::defaultBehindMore(),
        );
    }

    public function testOnlyTheInboxIsPinnedToTheTop(): void
    {
        foreach (SidebarRow::cases() as $row) {
            self::assertSame(
                SidebarRow::Inbox === $row,
                $row->isPinned(),
                $row->value . ($row->isPinned() ? ' is pinned' : ' is not pinned'),
            );
        }

        // The presence companion: pinned is a real answer for exactly one row,
        // not a method that says "no" about all of them.
        self::assertCount(1, array_filter(SidebarRow::cases(), static fn (SidebarRow $row): bool => $row->isPinned()));
    }

    /**
     * The row's name is the key its label, its translation and its badge are
     * all looked up under — `sidebar.nav.<value>` — so a row whose value
     * differs from its role's would translate to nothing. Starred is a flag on
     * a conversation, not a label, and has no role.
     */
    public function testARowIsNamedAfterItsLabelRole(): void
    {
        self::assertNull(SidebarRow::Starred->role());

        foreach (SidebarRow::cases() as $row) {
            if (SidebarRow::Starred === $row) {
                continue;
            }

            self::assertInstanceOf(LabelRole::class, $row->role(), $row->value . ' has no role');
            self::assertSame($row->value, $row->role()->value);
        }
    }

    /** Each row opens the list it is named for. */
    public function testEachRowKnowsItsRoute(): void
    {
        self::assertSame(
            [
                'inbox'   => 'app_mail_inbox',
                'unread'  => 'app_mail_unread',
                'starred' => 'app_mail_starred',
                'sent'    => 'app_mail_sent',
                'drafts'  => 'app_mail_drafts',
                'snoozed' => 'app_mail_snoozed',
                'archive' => 'app_mail_archive',
                'spam'    => 'app_mail_spam',
                'trash'   => 'app_mail_trash',
            ],
            array_combine(
                array_map(static fn (SidebarRow $row): string => $row->value, SidebarRow::cases()),
                array_map(static fn (SidebarRow $row): string => $row->route(), SidebarRow::cases()),
            ),
        );
    }
}
