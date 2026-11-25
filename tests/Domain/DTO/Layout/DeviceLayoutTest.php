<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Layout;

use App\Domain\DTO\Layout\DeviceLayout;
use App\Domain\DTO\Layout\LayoutNode;
use App\Domain\DTO\Layout\LayoutSplit;
use App\Domain\Enum\Layout\PaneKind;
use App\Domain\Enum\Layout\SplitDirection;
use PHPUnit\Framework\TestCase;

/**
 * What a device remembers about a workspace, read back from its cookie (#755).
 *
 * The cookie is written by the page and read by the server so the first paint
 * is drawn at this screen's divider positions. It is input like any other, and
 * it goes stale without anybody touching it: a split that gains a pane after
 * the device stored two shares for it must fall back to the workspace's own
 * three, not draw two of them and lose the third pane's cell.
 */
final class DeviceLayoutTest extends TestCase
{
    public function testTheDevicesSharesAreUsedWhenTheyFitTheSplit(): void
    {
        $device = DeviceLayout::fromCookie('{"w1":{"s":{"s1":[1,3]}}}');

        self::assertEqualsWithDelta([0.25, 0.75], $device->sizes('w1', $this->split()), 0.0001);
    }

    public function testSharesForADifferentNumberOfChildrenAreIgnored(): void
    {
        $device = DeviceLayout::fromCookie('{"w1":{"s":{"s1":[0.2,0.3,0.5]}}}');

        self::assertSame([0.5, 0.5], $device->sizes('w1', $this->split()));
    }

    public function testAnotherWorkspacesSharesAreNotThisOnes(): void
    {
        $device = DeviceLayout::fromCookie('{"w2":{"s":{"s1":[1,3]}}}');

        self::assertSame([0.5, 0.5], $device->sizes('w1', $this->split()));
    }

    public function testNonsenseIsADeviceThatRemembersNothing(): void
    {
        foreach ([null, '', 'not json', '[1,2]', '{"w1":"x"}', '{"w1":{"s":{"s1":["a","b"]}}}', '{"w1":{"s":{"s1":[-1,1]}}}'] as $raw) {
            self::assertSame([0.5, 0.5], DeviceLayout::fromCookie($raw)->sizes('w1', $this->split()), var_export($raw, true));
        }
    }

    public function testAnOversizedCookieIsNotRead(): void
    {
        $raw = '{"w1":{"s":{"s1":[1,3]}},"pad":"' . str_repeat('x', 4000) . '"}';

        self::assertSame([0.5, 0.5], DeviceLayout::fromCookie($raw)->sizes('w1', $this->split()));
    }

    public function testTheFullWidthPaneIsReadByKind(): void
    {
        $device = DeviceLayout::fromCookie('{"w1":{"m":"calendar"},"w2":{"m":"browser"}}');

        self::assertSame(PaneKind::Calendar, $device->maximized('w1'));
        self::assertNull($device->maximized('w2'));
        self::assertNull($device->maximized('w3'));
    }

    private function split(): LayoutSplit
    {
        return LayoutNode::split('s1', SplitDirection::Row, [LayoutNode::pane(PaneKind::List), LayoutNode::pane(PaneKind::Message)]);
    }
}
