<?php

declare(strict_types=1);

namespace App\Tests\Service\MessageList;

use App\Domain\Enum\MessageList\AttachmentDisplay;
use App\Domain\Enum\MessageList\LabelDisplay;
use App\Domain\Enum\MessageList\RowLayout;
use App\Domain\Enum\MessageList\RowPreview;
use App\Domain\Enum\MessageList\RowReveal;
use App\Domain\Enum\Theme\UnreadEmphasis;
use App\Entity\Embeddable\MessageList;
use App\Service\MessageList\MessageListRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The message list settings, from the stored value to what <html> carries
 * (#608).
 *
 * The claim worth testing is that a FRESH USER'S LIST LOOKS AS IT DID before
 * the settings moved off Appearance and grew: one preview line, rows going to
 * one line from 48rem, a paperclip, labels where they were, the date as it
 * was. Those are literal attribute values app.css is keyed on, so they are
 * asserted as literals. And that the five settings that moved keep reading the
 * way they did — a backup or an app written before the move still means what
 * it meant.
 */
final class MessageListRendererTest extends TestCase
{
    /** @return array<string, string> */
    private function variables(MessageList $list): array
    {
        $variables = [];

        foreach (explode(';', new MessageListRenderer()->cssVariables($list)) as $part) {
            [$name, $value] = explode(':', $part, 2);
            $variables[$name] = $value;
        }

        return $variables;
    }

    public function testTheDefaultsAreTheListAsItWas(): void
    {
        $list = new MessageList();

        self::assertSame([
            'data-list-layout'      => 'fit',
            'data-list-preview'     => 'one',
            'data-list-attachments' => 'icon',
            'data-list-labels'      => 'line',
            'data-list-sender'      => 'everyone',
            'data-list-date'        => 'smart',
            'data-list-count'       => 'on',
            'data-list-replied'     => 'off',
            'data-list-star'        => 'hover',
        ], new MessageListRenderer()->attributes($list));

        self::assertSame([
            '--list-corner-display' => 'block',
            '--list-avatar-hide'    => '0',
            '--tab-unread-display'  => 'inline',
            '--unread-emphasis'     => '1',
            '--unread-bar-w'        => '0px',
        ], $this->variables($list));
    }

    public function testSwitchesReachTheirProperties(): void
    {
        $list = new MessageList();
        $list->accountCorner  = false;
        $list->avatars        = false;
        $list->tabUnreadCount = false;
        $list->unreadEmphasis = UnreadEmphasis::Strong;

        $variables = $this->variables($list);

        self::assertSame('none', $variables['--list-corner-display']);
        self::assertSame('1', $variables['--list-avatar-hide']);
        self::assertSame('none', $variables['--tab-unread-display']);
        self::assertSame('1.6', $variables['--unread-emphasis']);
        self::assertSame('3px', $variables['--unread-bar-w']);
    }

    /**
     * The settings page posts strings; "0" is truthy to a plain cast, which is
     * how a switch comes to be impossible to turn off.
     */
    public function testAStringZeroSwitchesOff(): void
    {
        $list = new MessageList()->applyArray(['messageCount' => '0', 'replied' => '1', 'avatars' => false]);

        self::assertFalse($list->messageCount);
        self::assertTrue($list->replied);
        self::assertFalse($list->avatars);
    }

    /** A value this build does not know keeps what is stored, as a newer backup's might. */
    public function testAnUnknownValueKeepsTheStoredOne(): void
    {
        $list = new MessageList();
        $list->labels = LabelDisplay::Dots;

        $list->applyArray(['labels' => 'sideways', 'layout' => 7, 'attachments' => 'chips']);

        self::assertSame(LabelDisplay::Dots, $list->labels);
        self::assertSame(RowLayout::Fit, $list->layout);
        self::assertSame(AttachmentDisplay::Chips, $list->attachments);
    }

    public function testEverySettingSurvivesTheRoundTrip(): void
    {
        $source = new MessageList();
        $source->layout      = RowLayout::Stacked;
        $source->preview     = RowPreview::Inline;
        $source->attachments = AttachmentDisplay::Chips;
        $source->labels      = LabelDisplay::Inline;
        $source->replied     = true;
        $source->star        = RowReveal::Always;
        $source->avatars     = false;

        self::assertSame($source->toArray(), new MessageList()->applyArray($source->toArray())->toArray());
    }

    /**
     * Preview lines split in two on the way in, as the migration split them:
     * 1 also meant "one line from 48rem", 0 and 2 meant 75rem.
     */
    public function testOldPreviewLinesBecomeAPreviewAndALayout(): void
    {
        self::assertSame(['preview' => 'none', 'layout' => 'wide'], MessageList::fromLegacyAppearance(['previewLines' => 0]));
        self::assertSame(['preview' => 'one', 'layout' => 'fit'], MessageList::fromLegacyAppearance(['previewLines' => 1]));
        self::assertSame(['preview' => 'two', 'layout' => 'wide'], MessageList::fromLegacyAppearance(['previewLines' => 2]));
    }

    public function testTheOtherFourMoveUnderTheirNewNames(): void
    {
        $list = new MessageList()->applyArray(MessageList::fromLegacyAppearance([
            'accountCorner'  => false,
            'listAvatars'    => '0',
            'tabUnreadCount' => false,
            'unreadEmphasis' => 'subtle',
            'theme'          => 'nord',
        ]));

        self::assertFalse($list->accountCorner);
        self::assertFalse($list->avatars);
        self::assertFalse($list->tabUnreadCount);
        self::assertSame(UnreadEmphasis::Subtle, $list->unreadEmphasis);
    }

    /** A document from after the move carries none of them, and nothing is touched. */
    public function testANewerAppearanceMapsToNothing(): void
    {
        self::assertSame([], MessageList::fromLegacyAppearance(['theme' => 'nord', 'accent' => '#123456']));
    }

    /**
     * The count the apps read and write. Inline is one line of preview to an
     * app that only knows counts; the way back clamps as the old setter did.
     */
    public function testThePreviewAnswersToTheAppsCount(): void
    {
        self::assertSame(0, RowPreview::None->lines());
        self::assertSame(1, RowPreview::Inline->lines());
        self::assertSame(1, RowPreview::One->lines());
        self::assertSame(2, RowPreview::Two->lines());

        self::assertSame(RowPreview::None, RowPreview::fromLines(-3));
        self::assertSame(RowPreview::One, RowPreview::fromLines(1));
        self::assertSame(RowPreview::Two, RowPreview::fromLines(9));
    }
}
