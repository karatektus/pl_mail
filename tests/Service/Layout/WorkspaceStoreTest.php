<?php

declare(strict_types=1);

namespace App\Tests\Service\Layout;

use App\Domain\DTO\Layout\LayoutSplit;
use App\Domain\Enum\Layout\PaneKind;
use App\Entity\User\User;
use App\Service\Layout\WorkspaceStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The first workspace is the layout the user had before #755.
 *
 * Nobody's screen should change on the day workspaces arrive: the panes that
 * were docked are the panes in the first workspace, in the order the old row
 * drew them, at proportions taken from the widths they were dragged to. These
 * read the old settings by their stored keys, because the constants and
 * properties that named them are gone — so a test that set them through the
 * entity could not exist, and one that did not set them would prove nothing.
 *
 * Against the real container for the translator: the first workspace's name is
 * stored as text in the user's language.
 */
final class WorkspaceStoreTest extends KernelTestCase
{
    private WorkspaceStore $store;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->store = self::getContainer()->get(WorkspaceStore::class);
    }

    public function testANewAccountStartsWithTheMailAndTheCalendarBesideIt(): void
    {
        $workspace = $this->store->load(new User())->active();

        self::assertSame([PaneKind::List, PaneKind::Calendar], $workspace->root->kinds());
        self::assertSame('w1', $workspace->id);
    }

    public function testACalendarSomebodyShutStaysShut(): void
    {
        foreach ([['calendar.pane_mode', 'mail'], ['calendar.pane_open', false]] as [$key, $value]) {
            $user = new User();
            $user->setSetting($key, $value);

            self::assertSame([PaneKind::List], $this->store->load($user)->active()->root->kinds(), $key);
        }
    }

    public function testTheReadingPaneBesideTheListIsAMessagePaneAtItsShare(): void
    {
        $user = new User();
        $user->setSetting('calendar.pane_mode', 'mail');
        $user->setSetting('reading.pane_mode', 'right');
        $user->setSetting('reading.pane_width_pct', 40);

        $root = $this->store->load($user)->active()->root;

        self::assertInstanceOf(LayoutSplit::class, $root);
        self::assertSame([PaneKind::List, PaneKind::Message], $root->kinds());
        self::assertEqualsWithDelta([0.6, 0.4], $root->sizes, 0.0001);
    }

    public function testEveryDockedPaneFollowsTheMailInTheOldRowsOrder(): void
    {
        $user = new User();
        $user->setSetting('calendar.pane_mode', 'calendar');
        $user->setSetting('contacts.pane_mode', 'split');
        $user->setSetting('tasks.pane_open', true);

        self::assertSame(
            [PaneKind::List, PaneKind::Calendar, PaneKind::Contacts, PaneKind::Tasks],
            $this->store->load($user)->active()->root->kinds(),
        );
    }

    public function testDocksTooWideToShareARowLeaveTheMailAThird(): void
    {
        $user = new User();
        $user->setSetting('calendar.pane_width', 900);
        $user->setSetting('contacts.pane_open', true);
        $user->setSetting('contacts.pane_width', 900);
        $user->setSetting('tasks.pane_open', true);
        $user->setSetting('tasks.pane_width', 900);

        $root = $this->store->load($user)->active()->root;

        self::assertInstanceOf(LayoutSplit::class, $root);
        self::assertEqualsWithDelta(0.34, $root->sizes[0], 0.0001);
    }

    public function testAStoredDocumentWinsOverTheOldSettings(): void
    {
        $user = new User();
        $user->setSetting('tasks.pane_open', true);
        $user->setSetting(User::SETTING_WORKSPACES, [
            'workspaces' => [['id' => 'mine', 'name' => 'Mine', 'layout' => ['t' => 'pane', 'kind' => 'contacts']]],
            'active'     => 0,
        ]);

        self::assertSame([PaneKind::Contacts], $this->store->load($user)->active()->root->kinds());
    }

    public function testAStoredDocumentThatNoLongerParsesFallsBackToTheOldLayout(): void
    {
        $user = new User();
        $user->setSetting('calendar.pane_mode', 'mail');
        $user->setSetting(User::SETTING_WORKSPACES, ['workspaces' => 'garbage']);

        self::assertSame([PaneKind::List], $this->store->load($user)->active()->root->kinds());
    }
}
