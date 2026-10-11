<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Mailbox;
use App\Service\Mail\ImportProgress;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * An import says how far it has got, and stops saying anything when it is over.
 *
 * It used to say nothing at all: mail appeared in bursts, and nothing told a
 * working import from a stalled one (#42). The line in the topbar's indicator
 * is read off the folders' own import markers, so the figures here are the
 * ones the import itself moves.
 */
final class ImportProgressTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private ImportProgress $progress;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->progress   = static::getContainer()->get(ImportProgress::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnImportingAccountShowsWhatIsInAgainstWhatItSetOutToFetch(): void
    {
        // The Inbox is part-way; Sent is finished and still counts towards
        // both figures; the bin has not been looked at and adds nothing yet.
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 812, total: 1000, remaining: 750);
        $this->folder('Sent', MailboxSpecialUse::SENT, floor: 0, total: 200, remaining: 0);
        $this->folder('Trash', MailboxSpecialUse::TRASH, floor: null, total: null, remaining: null);

        $this->account->importBeatAt = new DateTimeImmutable();
        $this->em->flush();

        $lines = $this->progress->forUser($this->user);

        self::assertCount(1, $lines);
        self::assertSame($this->account->id, $lines[0]->account->id);
        self::assertSame(450, $lines[0]->done);
        self::assertSame(1200, $lines[0]->total);
        self::assertSame(37, $lines[0]->percent(), 'floored, never rounded up');
        self::assertSame('INBOX', $lines[0]->folder?->fullPath, 'the folder being read is the first by rank');
        self::assertFalse($lines[0]->waiting);
    }

    /**
     * Microsoft keeps its folders as labels, so the line names a label where
     * an IMAP line names a mailbox — and measures what is stored against what
     * the folder list said the mailbox holds.
     */
    public function testAMicrosoftImportNamesTheFolderItIsOnByItsLabel(): void
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = 'Projects';
        $label->isVisible = true;

        $binding                = new LabelBinding();
        $binding->label         = $label;
        $binding->account       = $this->account;
        $binding->graphFolderId = 'AAMkAD-projects';

        $label->addBinding($binding);

        $this->em->persist($label);
        $this->em->persist($binding);

        $this->account->authType      = AuthType::OAuth2->value;
        $this->account->oauthProvider = 'microsoft';
        $this->account->importTotal   = 4000;
        $this->account->importBeatAt  = new DateTimeImmutable();
        $this->account->graphImport   = [
            ['folder' => 'AAMkAD-projects', 'since' => 1_800_000_000, 'next' => null],
            ['folder' => 'AAMkAD-trash', 'since' => 1_800_000_000, 'next' => null],
        ];
        $this->em->flush();

        $lines = $this->progress->forUser($this->user);

        self::assertCount(1, $lines);
        self::assertSame(0, $lines[0]->done);
        self::assertSame(4000, $lines[0]->total);
        self::assertSame('Projects', $lines[0]->label?->name);
        self::assertNull($lines[0]->folder);

        $this->account->graphImport = [];
        $this->em->flush();

        self::assertSame([], $this->progress->forUser($this->user), 'and nothing once every folder is in');
    }

    public function testAnAccountWhoseHistoryIsInShowsNothing(): void
    {
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 0, total: 1000, remaining: 0);

        self::assertSame([], $this->progress->forUser($this->user));
    }

    /**
     * A bar that has stopped says nothing by itself. One that nothing has
     * touched for a while says "waiting", which at least is known to be true.
     */
    public function testAnImportNothingHasTouchedForAWhileSaysItIsWaiting(): void
    {
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 812, total: 1000, remaining: 750);

        $this->account->importBeatAt = new DateTimeImmutable('-1 hour');
        $this->em->flush();

        self::assertTrue($this->progress->forUser($this->user)[0]->waiting);
    }

    /** Somebody else's import is not this person's to watch. */
    public function testALineIsShownOnlyToTheOwnerOfTheAccount(): void
    {
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 812, total: 1000, remaining: 750);

        self::assertSame([], $this->progress->forUser($this->seedUser()));
    }

    /**
     * And it reaches the screen: the indicator renders the line, the account
     * and the figures, with the folder it is on.
     */
    public function testTheIndicatorRendersTheLine(): void
    {
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 812, total: 21000, remaining: 19760);

        $this->account->importBeatAt = new DateTimeImmutable();
        $this->em->flush();

        $html = static::getContainer()->get(Environment::class)->render('mail/_jobs_indicator.html.twig', [
            'jobs'    => [],
            'imports' => $this->progress->forUser($this->user),
        ]);

        self::assertStringContainsString('data-import-line', $html);
        self::assertStringContainsString($this->account->displayAddress, $html);
        self::assertStringContainsString('1,240 / 21,000', $html);
        self::assertStringContainsString('INBOX', $html);
    }

    /** Nothing running is no button at all, as it was before imports were shown. */
    public function testWithNothingRunningTheIndicatorIsEmpty(): void
    {
        $html = static::getContainer()->get(Environment::class)->render('mail/_jobs_indicator.html.twig', [
            'jobs'    => [],
            'imports' => [],
        ]);

        self::assertStringNotContainsString('<button', $html);
    }

    private function folder(string $path, ?MailboxSpecialUse $use, ?int $floor, ?int $total, ?int $remaining): Mailbox
    {
        $mailbox                  = new Mailbox();
        $mailbox->account         = $this->account;
        $mailbox->name            = $path;
        $mailbox->fullPath        = $path;
        $mailbox->specialUse      = $use;
        $mailbox->isSyncEnabled   = true;
        $mailbox->importFloorUid  = $floor;
        $mailbox->importTotal     = $total;
        $mailbox->importRemaining = $remaining;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
