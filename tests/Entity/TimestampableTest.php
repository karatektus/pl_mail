<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Label\Label;
use App\Entity\Calendar\EventSuppression;
use App\Entity\Mail\Account;
use App\Entity\Mail\UploadedBlob;
use App\Entity\Monitoring\LogEntry;
use App\Entity\User\PushSubscription;
use App\Jmap\State\ChangeLog;
use App\Entity\Mail\Contact;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That the lifecycle callbacks actually run.
 *
 * Six entities stopped writing createdAt and updatedAt by hand and took the
 * trait instead, which only works if each of them also carries
 * #[ORM\HasLifecycleCallbacks] — Doctrine ignores PrePersist and PreUpdate
 * without it, silently. Nothing throws, nothing fails, the timestamps simply
 * stop moving, and the twelve manual writes that used to cover for it are gone.
 * So this is the one thing about that change worth a test.
 *
 * PreUpdate only fires when Doctrine sees a mapped field actually change, which
 * is a real behavioural difference from a manual write: a save that changes
 * nothing no longer bumps updatedAt. That is more honest than what it replaced
 * and it is pinned below rather than left to be discovered.
 */
final class TimestampableTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    public function testPersistingStampsBothTimestamps(): void
    {
        $user = $this->user();

        // Not assertNotNull: the properties are non-nullable, so that would
        // pass without the callback ever running. An uninitialised typed
        // property throws on read, and a stamped one is within seconds of now.
        self::assertEqualsWithDelta(time(), $user->createdAt->getTimestamp(), 10, 'PrePersist did not run — is HasLifecycleCallbacks missing?');
        self::assertEqualsWithDelta(time(), $user->updatedAt->getTimestamp(), 10);
    }

    public function testChangingAFieldMovesUpdatedAtButNotCreatedAt(): void
    {
        $user    = $this->user();
        $created = $user->createdAt;
        $before  = $user->updatedAt;

        // PreUpdate reads the clock, so without this the two stamps can land in
        // the same second and the assertion passes on a tie rather than a bump.
        sleep(1);

        $user->nameFirst = 'Renamed';
        $this->em->flush();

        self::assertGreaterThan($before, $user->updatedAt, 'PreUpdate did not run');
        self::assertEquals($created, $user->createdAt, 'createdAt moved on an update');
    }

    /**
     * The behavioural change worth knowing about: a flush that changes no
     * mapped field is not an update as far as Doctrine is concerned, so
     * updatedAt stays put. The manual writes this replaced bumped it anyway,
     * which made the column say a row had changed when it had not.
     */
    public function testAFlushThatChangesNothingLeavesUpdatedAtAlone(): void
    {
        $user   = $this->user();
        $before = $user->updatedAt;

        sleep(1);

        $this->em->flush();

        self::assertEquals($before, $user->updatedAt);
    }

    /**
     * The silent failure this whole test file exists for, checked on every
     * entity rather than the one above: the trait's callbacks are inert
     * without HasLifecycleCallbacks, and Doctrine says nothing about it.
     */
    #[DataProvider('timestampedEntities')]
    public function testEveryAdoptingEntityDeclaresItsLifecycleCallbacks(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertContains(
            TimestampableTrait::class,
            $reflection->getTraitNames(),
            $class.' does not use the trait',
        );

        self::assertNotEmpty(
            $reflection->getAttributes(ORM\HasLifecycleCallbacks::class),
            $class.' uses the trait but never runs it — add #[ORM\HasLifecycleCallbacks]',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function timestampedEntities(): iterable
    {
        yield 'Account'          => [Account::class];
        yield 'ChangeLog'        => [ChangeLog::class];
        yield 'EventSuppression' => [EventSuppression::class];
        yield 'LogEntry'         => [LogEntry::class];
        yield 'PushSubscription' => [PushSubscription::class];
        yield 'UploadedBlob'     => [UploadedBlob::class];
        yield 'Contact' => [Contact::class];
        yield 'Label'   => [Label::class];
        yield 'Mailbox' => [Mailbox::class];
        yield 'Message' => [Message::class];
        yield 'User'    => [User::class];
    }

    private function user(): User
    {
        $user = new User();
        $user->email    = 'stamp-'.bin2hex(random_bytes(6)).'@example.test';
        $user->password  = 'x';
        $user->nameFirst = 'Stamp';
        $user->nameLast  = 'Test';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
