<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\RepeatedHeaderBackfillTask;
use App\Domain\Helper\RawMessageStorage;
use App\Entity\Mail\Message;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mail stored with its header block repeated at the top of the body is
 * repaired from its own .eml (#173).
 *
 * The rows are seeded as the sync used to leave them: the .eml holding the
 * headers twice, and — for a single-part message — body_html starting with
 * `Delivered-To:`. No mail server is involved, because none is needed: the
 * file holds everything the repair reads.
 *
 * The multipart case is the one that would go wrong quietly. Its bodies were
 * parsed correctly, so the repair must fix the file and leave them alone; a
 * test that only looked at the single-part message would let a repair that
 * re-parses everything through.
 */
final class RepeatedHeaderBackfillTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private RepeatedHeaderBackfillTask $task;
    private RawMessageStorage $rawStorage;

    /** @var list<string> .eml files written to disk, which no rollback removes */
    private array $storedRawPaths = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task       = $container->get(RepeatedHeaderBackfillTask::class);
        $this->rawStorage = $container->get(RawMessageStorage::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        foreach ($this->storedRawPaths as $path) {
            $this->rawStorage->delete($path);
        }

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASinglePartMessageGetsItsBodyBackRatherThanItsHeadersAgain(): void
    {
        $header = $this->header('text/html; charset=utf-8');
        $html   = "<!DOCTYPE html>\r\n<html><body><p>Your parcel is on its way.</p></body></html>\r\n";

        $id = $this->message($header . "\r\n\r\n" . $header . "\r\n\r\n" . $html, bodyHtml: $header . "\r\n\r\n" . $html);

        self::assertSame(Command::SUCCESS, $this->runTask());

        $message = $this->reload($id);

        self::assertStringStartsWith('<!DOCTYPE html>', (string) $message->bodyHtml);
        self::assertStringNotContainsString('Delivered-To', (string) $message->bodyHtmlSafe);
        self::assertStringContainsString('Your parcel is on its way.', (string) $message->bodyHtmlSafe);
        self::assertSame($header . "\r\n\r\n" . $html, $this->raw($message));
    }

    public function testAMultipartMessageHasItsFileRepairedAndItsBodiesLeftAlone(): void
    {
        $header = $this->header('multipart/alternative; boundary="B"');
        $parts  = "--B\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n\r\nYour parcel is on its way.\r\n"
            . "--B\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n\r\n<p>Your parcel is on its way.</p>\r\n"
            . "--B--\r\n";

        // Bodies the parse would not produce, standing for a repair the sync
        // made: the backfill must not replace them with its own reading.
        $id = $this->message(
            $header . "\r\n\r\n" . $header . "\r\n\r\n" . $parts,
            bodyHtml: '<p>As the sync stored it.</p>',
            bodyText: 'As the sync stored it.',
        );

        $this->runTask();

        $message = $this->reload($id);

        self::assertSame($header . "\r\n\r\n" . $parts, $this->raw($message));
        self::assertSame('<p>As the sync stored it.</p>', $message->bodyHtml);
        self::assertSame('As the sync stored it.', $message->bodyText);
    }

    public function testAMessageStoredOnceIsLeftAsItWas(): void
    {
        $header = $this->header('text/plain; charset=utf-8');
        $raw    = $header . "\r\n\r\nDelivered-To: is a line of this body.\r\n";

        $id = $this->message($raw, bodyText: "Delivered-To: is a line of this body.\r\n");

        $this->runTask();

        $message = $this->reload($id);

        self::assertSame($raw, $this->raw($message));
        self::assertSame("Delivered-To: is a line of this body.\r\n", $message->bodyText);
    }

    // ---------------------------------------------------------------- helpers

    private function runTask(): int
    {
        return $this->task->run(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
    }

    private function header(string $contentType): string
    {
        return "Delivered-To: {$this->account->email}\r\n"
            . "From: Parcels <parcels@example.test>\r\n"
            . "Subject: Your parcel\r\n"
            . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
            . sprintf("Message-ID: <%s@example.test>\r\n", uniqid('parcel-', true))
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: {$contentType}";
    }

    private function message(string $raw, ?string $bodyHtml = null, ?string $bodyText = null): int
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Your parcel';
        $message->fromAddress    = 'parcels@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->hasAttachments = false;
        $message->imapUid        = 1;
        $message->bodyHtml       = $bodyHtml;
        $message->bodyText       = $bodyText;

        $this->em->persist($message);
        $this->em->flush();

        $message->rawPath       = $this->rawStorage->store((int) $this->account->id, (int) $message->id, $raw);
        $this->storedRawPaths[] = $message->rawPath;

        $this->em->flush();

        return (int) $message->id;
    }

    private function reload(int $id): Message
    {
        $this->em->clear();

        $message = $this->em->getRepository(Message::class)->find($id);

        self::assertInstanceOf(Message::class, $message);

        return $message;
    }

    private function raw(Message $message): string
    {
        return (string) file_get_contents($this->rawStorage->getAbsolutePath((string) $message->rawPath));
    }
}
