<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\UnifiedInboxIcon;
use App\Domain\Exception\InvalidUnifiedInboxException;
use App\Entity\Mail\Account;
use App\Service\Mail\UnifiedInboxes;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which accounts each of a reader's unified inboxes lists (#265), and the
 * rules for changing that: the starting rows, the first inbox as the home of
 * an unassigned account, Other as where a taken-out account goes, and the two
 * rows that cannot be deleted.
 */
final class UnifiedInboxesTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private UnifiedInboxes $inboxes;
    private Account $second;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->inboxes    = $container->get(UnifiedInboxes::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->second  = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAReaderStartsWithOneInboxOverEverythingAndAnEmptyOther(): void
    {
        $all = $this->inboxes->all($this->user);

        self::assertCount(2, $all);
        self::assertFalse($all[0]->isOther);
        self::assertNull($all[0]->name, 'unnamed, so it reads "Inbox" in the reader\'s language');
        self::assertSame(UnifiedInboxIcon::Inbox, $all[0]->iconCase());
        self::assertTrue($all[1]->isOther);

        self::assertEqualsCanonicalizing(
            [$this->account->id, $this->second->id],
            $this->inboxes->accountIdsOf($this->user, $all[0]),
        );
        self::assertSame([], $this->inboxes->accountIdsOf($this->user, $all[1]));

        // Every account in the first: its queries are not narrowed at all.
        self::assertNull($this->inboxes->scopeOf($this->user, $all[0]));

        // And the empty Other is not drawn.
        self::assertSame([$all[0]], $this->inboxes->shown($this->user));
    }

    public function testTheStartingRowsAreMadeOnce(): void
    {
        $this->inboxes->all($this->user);
        $this->inboxes->reset();

        self::assertCount(2, $this->inboxes->all($this->user));
    }

    public function testAnAccountTakenOutGoesToOtherAndOtherIsDrawnThen(): void
    {
        $first = $this->inboxes->first($this->user);
        $other = $this->inboxes->other($this->user);

        $this->inboxes->assign($this->user, $this->second, $other);

        self::assertSame([$this->account->id], $this->inboxes->accountIdsOf($this->user, $first));
        self::assertSame([$this->second->id], $this->inboxes->accountIdsOf($this->user, $other));
        self::assertSame([$this->account->id], $this->inboxes->scopeOf($this->user, $first));
        self::assertCount(2, $this->inboxes->shown($this->user));
    }

    public function testOtherHoldingOnlyASwitchedOffAccountIsNotDrawn(): void
    {
        $this->inboxes->assign($this->user, $this->second, $this->inboxes->other($this->user));

        $this->second->mailEnabled = false;
        $this->em->flush();
        $this->inboxes->reset();

        self::assertCount(1, $this->inboxes->shown($this->user));
    }

    public function testANewInboxGoesAboveOtherAndAnUnassignedAccountStaysInTheFirst(): void
    {
        $work = $this->inboxes->create($this->user, '  Work  ', UnifiedInboxIcon::Briefcase->value);

        $names = array_map(
            static fn ($inbox): string => $inbox->isOther ? 'other' : (string) ($inbox->name ?? 'first'),
            $this->inboxes->all($this->user),
        );

        self::assertSame(['first', 'Work', 'other'], $names);
        self::assertSame('Work', $work->name, 'trimmed');
        self::assertSame([], $this->inboxes->accountIdsOf($this->user, $work), 'a new inbox starts empty');

        $this->inboxes->assign($this->user, $this->second, $work);

        self::assertSame([$this->second->id], $this->inboxes->accountIdsOf($this->user, $work));
        self::assertSame([$this->account->id], $this->inboxes->accountIdsOf($this->user, $this->inboxes->first($this->user)));
    }

    public function testDeletingAnInboxMovesItsAccountsToOther(): void
    {
        $first = $this->inboxes->first($this->user);
        $work  = $this->inboxes->create($this->user, 'Work', 'briefcase');

        $this->inboxes->assign($this->user, $this->second, $work);

        // The first one, whose accounts were there by default and had no row.
        $this->inboxes->remove($this->user, $first);

        $other = $this->inboxes->other($this->user);

        self::assertSame($work->id, $this->inboxes->first($this->user)->id);
        self::assertSame([$this->account->id], $this->inboxes->accountIdsOf($this->user, $other), 'to Other, not to the next inbox');
        self::assertSame([$this->second->id], $this->inboxes->accountIdsOf($this->user, $work));
    }

    public function testOtherAndTheLastInboxOfTheReadersOwnCannotBeDeleted(): void
    {
        foreach ([$this->inboxes->other($this->user), $this->inboxes->first($this->user)] as $inbox) {
            try {
                $this->inboxes->remove($this->user, $inbox);
                self::fail('deleted an inbox that has to stay');
            } catch (InvalidUnifiedInboxException $e) {
                self::assertContains($e->translationKey, ['settings.unified_inboxes.other_stays', 'settings.unified_inboxes.last_stays']);
            }
        }

        self::assertCount(2, $this->inboxes->all($this->user));
    }

    public function testANameIsRequiredOnCreateAndOptionalOnUpdate(): void
    {
        try {
            $this->inboxes->create($this->user, '   ', 'inbox');
            self::fail('created an inbox with no name');
        } catch (InvalidUnifiedInboxException $e) {
            self::assertSame('settings.unified_inboxes.name_required', $e->translationKey);
        }

        $first = $this->inboxes->first($this->user);

        $this->inboxes->update($this->user, $first, 'Mail', 'not-an-icon');
        self::assertSame('Mail', $first->name);
        self::assertSame(UnifiedInboxIcon::Inbox, $first->iconCase(), 'an icon outside the set is the tray');

        $this->inboxes->update($this->user, $first, '', 'house');
        self::assertNull($first->name, 'cleared: back to plMail\'s own word');
        self::assertSame(UnifiedInboxIcon::House, $first->iconCase());
    }

    public function testArrangeKeepsToTheReadersOwnRowsAndPlacesTheRestAfter(): void
    {
        $work  = $this->inboxes->create($this->user, 'Work', 'briefcase');
        $first = $this->inboxes->first($this->user);
        $other = $this->inboxes->other($this->user);

        $this->inboxes->arrange($this->user, [(int) $other->id, (int) $work->id, 999_999, (int) $work->id]);

        $order = array_map(static fn ($inbox): ?int => $inbox->id, $this->inboxes->all($this->user));

        self::assertSame([$other->id, $work->id, $first->id], $order);

        // Other on top, and still not the inbox /mail/inbox opens.
        self::assertSame($work->id, $this->inboxes->first($this->user)->id);
    }

    public function testTheListValueNamesTheInboxAndReadsAnOldOneAsTheFirst(): void
    {
        $first = $this->inboxes->first($this->user);

        self::assertSame($first->id . ':c4', UnifiedInboxes::listValue($first, 'c4'));
        self::assertSame([(int) $first->id, 'c4'], UnifiedInboxes::parseListValue($first->id . ':c4'));
        self::assertSame([12, ''], UnifiedInboxes::parseListValue('12:'));
        self::assertSame([null, 'promotions'], UnifiedInboxes::parseListValue('promotions'));
        self::assertSame([null, ''], UnifiedInboxes::parseListValue(''));
    }
}
