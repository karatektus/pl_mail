<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\SenderPicture;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Enum\Mail\SenderPictureStatus;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Mail\Account;
use App\Entity\Mail\SenderPicture;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ResolveSenderPictureMessage;
use App\Repository\Mail\SenderPicture\SenderPictureRepository;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use App\Service\Mail\SenderPicture\SenderPictureQueue;
use App\Service\Mail\SenderPicture\SenderPictures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Who wears which picture, and who causes which request (#639).
 *
 * Two halves of one rule — the reader's own switches and the administrator's
 * decide — checked where each is applied: SenderPictures when a row is drawn,
 * SenderPictureQueue and the repository's claim when a lookup is queued.
 *
 * The bugs worth guarding are the quiet ones. A Gravatar fetched for one user
 * appearing on the rows of a user who never asked for Gravatar; a BIMI logo on
 * a forged message; a burst of mail from one sender queueing one lookup per
 * message. None of them would fail anything, they would just be wrong.
 *
 * Against a real container and database, rolled back after each test: the
 * claim is a pair of SQL statements whose whole point is how Postgres runs
 * them, which a double could only assert into existence.
 */
final class SenderPicturesTest extends KernelTestCase
{
    private const string STRANGER = 'news@brand.example';

    private EntityManagerInterface $em;
    private Connection $connection;
    private User $reader;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $this->reader = $this->user('reader');
        $this->signIn($this->reader);
        $this->lookups(true);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- showing

    public function testAColleaguesOwnPictureOutranksAnythingLookedUp(): void
    {
        $colleague         = $this->user('colleague');
        $colleague->avatar = 'cafe.png';
        $this->account($colleague, 'colleague@team.example');
        $this->stored(SenderPictureKind::Hashed, 'colleague@team.example');
        $this->reader->allowSenderPicture(SenderPictureKind::Hashed, true);
        $this->em->flush();

        self::assertSame(SenderPictureKind::LocalUser, $this->pictures()->for('Colleague <Colleague@Team.example>')?->kind);
    }

    public function testAContactsPhotoOutranksAnythingLookedUp(): void
    {
        $this->contact($this->reader, self::STRANGER, 'contacts/1/photo.jpg');
        $this->stored(SenderPictureKind::Website, 'brand.example');

        self::assertSame(SenderPictureKind::Contact, $this->pictures()->for(self::STRANGER)?->kind);
    }

    public function testSomebodyElsesContactPhotoIsNotShown(): void
    {
        $this->contact($this->user('other'), self::STRANGER, 'contacts/2/photo.jpg');

        self::assertNull($this->pictures()->for(self::STRANGER));
    }

    public function testALogoIsShownOnlyOnMailThatPassedTheSenderCheck(): void
    {
        $this->stored(SenderPictureKind::Bimi, 'brand.example');

        self::assertNull($this->pictures()->for(self::STRANGER, false), 'a forged From line must not wear the logo');
        self::assertSame(SenderPictureKind::Bimi, $this->pictures()->for(self::STRANGER, true)?->kind);
    }

    public function testAGravatarSomebodyElseAskedForStaysOffThisReadersRows(): void
    {
        $this->stored(SenderPictureKind::Hashed, self::STRANGER);

        $before = $this->pictures()->for(self::STRANGER);

        $this->reader->allowSenderPicture(SenderPictureKind::Hashed, true);

        $after = $this->pictures()->for(self::STRANGER);

        self::assertNull($before, 'Gravatar is off until the reader asks');
        self::assertSame(SenderPictureKind::Hashed, $after?->kind);
    }

    public function testTurningLookupsOffTakesFoundPicturesOffButKeepsContacts(): void
    {
        $this->stored(SenderPictureKind::Website, 'brand.example');
        $this->contact($this->reader, 'friend@home.example', 'contacts/1/friend.jpg');
        $this->lookups(false);

        self::assertNull($this->pictures()->for(self::STRANGER));
        self::assertSame(SenderPictureKind::Contact, $this->pictures()->for('friend@home.example')?->kind);
    }

    // ---------------------------------------------------------------- queueing

    public function testABurstOfMailFromOneSenderClaimsOneLookup(): void
    {
        $repository = self::getContainer()->get(SenderPictureRepository::class);

        self::assertSame(['a@x.example', 'b@x.example'], $repository->claim(SenderPictureKind::Hashed, ['a@x.example', 'a@x.example', 'b@x.example']));
        self::assertSame([], $repository->claim(SenderPictureKind::Hashed, ['a@x.example', 'b@x.example']), 'a second batch must not queue the same lookups again');
    }

    public function testAStaleAnswerIsClaimedAgainAndAFreshOneIsNot(): void
    {
        $stale            = $this->stored(SenderPictureKind::Hashed, 'stale@x.example');
        $stale->checkedAt = new \DateTimeImmutable('-40 days');
        $fresh            = new SenderPicture(SenderPictureKind::Hashed, 'fresh@x.example');
        $fresh->missing();
        $this->em->persist($fresh);
        $this->em->flush();

        $claimed = self::getContainer()->get(SenderPictureRepository::class)
            ->claim(SenderPictureKind::Hashed, ['stale@x.example', 'fresh@x.example']);

        self::assertSame(['stale@x.example'], $claimed);
    }

    public function testNothingIsQueuedWhileTheAdministratorHasLookupsOff(): void
    {
        $this->lookups(false);

        self::assertSame(0, $this->queue()->queue([$this->reader], [self::STRANGER]));
        self::assertSame([], $this->transport()->getSent());
    }

    public function testOnlyTheKindsTheReaderAllowsAreQueued(): void
    {
        $this->queue()->queue([$this->reader], [self::STRANGER, 'person@gmail.com']);

        $queued = array_map(
            static fn ($envelope): string => sprintf('%s:%s', $envelope->getMessage()->kind, $envelope->getMessage()->subject),
            array_filter($this->transport()->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof ResolveSenderPictureMessage),
        );

        sort($queued);

        // BIMI for both domains; a website icon for the brand but not for
        // Gmail; no Gravatar at all, because this reader never asked for it.
        self::assertSame(['bimi:brand.example', 'bimi:gmail.com', 'website:brand.example'], $queued);
    }

    // ---------------------------------------------------------------- helpers

    private function pictures(): SenderPictures
    {
        $pictures = self::getContainer()->get(SenderPictures::class);
        $pictures->reset();

        return $pictures;
    }

    private function queue(): SenderPictureQueue
    {
        return self::getContainer()->get(SenderPictureQueue::class);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.maintenance');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function lookups(bool $on): void
    {
        $settings                 = self::getContainer()->get(SenderPictureSettingsRepository::class)->currentOrNew();
        $settings->lookupsEnabled = $on;
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function stored(SenderPictureKind $kind, string $subject): SenderPicture
    {
        $picture = new SenderPicture($kind, $subject);
        $picture->found(str_repeat('a', 64) . '.png', 'test');
        $this->em->persist($picture);
        $this->em->flush();

        self::assertSame(SenderPictureStatus::Found, $picture->status);

        return $picture;
    }

    private function user(string $name): User
    {
        $user            = new User();
        $user->email     = sprintf('%s-%s@plmail.test', $name, bin2hex(random_bytes(4)));
        $user->nameFirst = ucfirst($name);
        $user->nameLast  = 'Test';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function account(User $user, string $email): void
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = $email;
        $account->username       = $email;
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->mailEnabled    = true;
        $this->em->persist($account);
        $this->em->flush();
    }

    private function contact(User $owner, string $email, string $photoPath): void
    {
        $contact            = Contact::create($owner, 'Somebody');
        $contact->photoPath = $photoPath;
        $this->em->persist($contact);
        $this->em->persist(ContactEmail::create($contact, $email));
        $this->em->flush();
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
