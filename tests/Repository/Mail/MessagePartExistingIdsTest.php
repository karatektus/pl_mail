<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Repository\Mail\MessagePartRepository;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which part ids still exist: the thumbnail sweep's keep-list (#232).
 *
 * Against the real database, because the answer decides which files the
 * weekly prune deletes: an id it wrongly calls gone costs a thumbnail, and a
 * query that comes back empty would cost all of them.
 */
final class MessagePartExistingIdsTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testOnlyTheIdsOfPartsThatExistComeBack(): void
    {
        $first  = $this->part();
        $second = $this->part();
        $gone   = $this->part();

        $goneId = (int) $gone->id;
        $this->em->remove($gone);
        $this->em->flush();

        $existing = static::getContainer()->get(MessagePartRepository::class)
            ->findExistingIds([(int) $first->id, (int) $second->id, $goneId, 2_147_483_647]);

        self::assertSame([(int) $first->id => true, (int) $second->id => true], $this->sorted($existing));
    }

    public function testNoIdsAskNothing(): void
    {
        self::assertSame([], static::getContainer()->get(MessagePartRepository::class)->findExistingIds([]));
    }

    private function part(): MessagePart
    {
        /** @var Message $message */
        $message = $this->thread('Teile')->messages->first();

        $part              = new MessagePart();
        $part->contentType = 'image/png';
        $part->filename    = 'p.png';
        $part->disposition = 'attachment';
        $part->size        = 1;
        $part->storagePath = 'var/attachments/1/1/1/p.png';

        $message->addMessagePart($part);
        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }

    /**
     * @param array<int, true> $set
     *
     * @return array<int, true>
     */
    private function sorted(array $set): array
    {
        ksort($set);

        return $set;
    }
}
