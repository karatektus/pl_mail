<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both kinds of sync announcement must reach the message list.
 *
 * plMail publishes two, and which one you get depends on the account:
 * mailbox.synced from IMAP, which can name the folder that changed, and
 * account.synced from Gmail, Graph and demo deliveries, which cannot — those
 * paths have no per-mailbox sync to report.
 *
 * The layout bound only the first. Nothing failed: the sidebar badges listen
 * for both and moved, so a Gmail user saw "Inbox 4" appear beside a list that
 * did not contain the fourth message, and it stayed that way until they
 * navigated. Reported on the demo, where the Receive button is the only thing
 * that ever publishes and the list therefore never moved at all.
 *
 * Nothing about that is visible in review or catchable by a unit test: the
 * binding is a string in a Twig attribute, and a missing one is silence. A
 * string comparison is a crude test; it is also the only kind that would have
 * caught it — the same argument AccountFormControllersTest makes for the
 * account form's controllers, and for the same reason.
 */
final class SyncEventsReachTheListTest extends TestCase
{
    private const string LAYOUT = 'templates/_layout/app.html.twig';

    /** @return iterable<string, array{string, string}> */
    public static function announcements(): iterable
    {
        yield 'IMAP names the mailbox' => [
            'core--mercure:mailbox-synced',
            'mail--mail-pane#onMailboxSynced',
        ];

        yield 'Gmail, Graph and the demo cannot' => [
            'core--mercure:account-synced',
            'mail--mail-pane#onAccountSynced',
        ];
    }

    #[DataProvider('announcements')]
    public function testTheMailPaneListensForIt(string $event, string $handler): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 3).'/'.self::LAYOUT);

        self::assertStringContainsString(
            $event.'->'.$handler,
            preg_replace('/\s+/', ' ', $layout) ?? '',
            sprintf(
                '%s does not route %s to %s. New mail will move the sidebar badge and never '
                . 'appear in the list.',
                self::LAYOUT,
                $event,
                $handler,
            ),
        );
    }
}
