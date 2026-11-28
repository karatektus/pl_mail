<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Entity\User\User;
use App\Repository\Mail\TrustedImageSender\TrustedImageSenderRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which entry trusts a sender, and what undoing it removes (#807).
 *
 * The narrowest entry is the one named under the message: the address, then
 * a pattern, then the domain. "Stop showing" removes all of them, because
 * removing only the named one would leave the images loading.
 */
final class TrustedImageSenderRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private TrustedImageSenderRepository $senders;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->senders    = $container->get(TrustedImageSenderRepository::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser('images-owner');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheNarrowestEntryIsTheOneThatTrusts(): void
    {
        $this->senders->add($this->user, '*@stripe.com');
        $this->senders->add($this->user, 'invoice+*@stripe.com');
        $this->senders->add($this->user, 'invoice+one@stripe.com');

        self::assertSame(
            [
                'invoice+one@stripe.com' => 'invoice+one@stripe.com',
                'invoice+two@stripe.com' => 'invoice+*@stripe.com',
                'support@stripe.com'     => '*@stripe.com',
            ],
            $this->senders->findTrustedAmong($this->user, [
                'invoice+one@stripe.com',
                'invoice+two@stripe.com',
                'support@stripe.com',
                'someone@elsewhere.test',
            ]),
        );
    }

    public function testAPatternTrustsOnlyWhatItMatches(): void
    {
        $this->senders->add($this->user, 'invoice+*@stripe.com');

        self::assertTrue($this->senders->isTrusted($this->user, 'invoice+statements+acct_134@stripe.com'));
        self::assertFalse($this->senders->isTrusted($this->user, 'marketing@stripe.com'));
        self::assertFalse($this->senders->isTrusted($this->user, 'invoice+x@mail.stripe.com'));
    }

    public function testTheDomainButtonTrustsTheExactDomain(): void
    {
        $this->senders->trustDomain($this->user, 'Receipts <billing@Example.com>');

        self::assertSame('*@example.com', $this->senders->entryTrusting($this->user, 'anyone@example.com'));
        self::assertNull($this->senders->entryTrusting($this->user, 'anyone@sub.example.com'));
    }

    public function testStopShowingRemovesEveryEntryThatTrustsTheSender(): void
    {
        $this->senders->add($this->user, 'invoice+one@stripe.com');
        $this->senders->add($this->user, 'invoice+*@stripe.com');
        $this->senders->add($this->user, '*@stripe.com');
        $this->senders->add($this->user, 'news@heise.de');

        $this->senders->distrust($this->user, 'invoice+one@stripe.com');

        self::assertFalse($this->senders->isTrusted($this->user, 'invoice+one@stripe.com'));
        self::assertSame(['news@heise.de'], $this->addresses());
    }

    public function testTheListPutsDomainsFirstThenPatternsThenAddresses(): void
    {
        foreach (['b@example.com', 'a*@example.com', '*@zeta.test', 'a@example.com', '*@alpha.test'] as $entry) {
            $this->senders->add($this->user, $entry);
        }

        self::assertSame(
            ['*@alpha.test', '*@zeta.test', 'a*@example.com', 'a@example.com', 'b@example.com'],
            $this->addresses(),
        );
    }

    public function testAddingTwiceIsOneEntryAndNonsenseIsNone(): void
    {
        self::assertTrue($this->senders->add($this->user, 'stripe.com'));
        self::assertTrue($this->senders->add($this->user, '*@Stripe.com'));
        self::assertFalse($this->senders->add($this->user, '*.stripe.com'));

        self::assertSame(['*@stripe.com'], $this->addresses());
    }

    public function testSomebodyElsesEntryCannotBeRemovedOrSeen(): void
    {
        $other = $this->seedUser('images-other');
        $this->senders->add($other, '*@stripe.com');
        $theirs = $this->senders->findForUser($other)[0];

        $this->senders->removeEntry($this->user, (int) $theirs->id);

        self::assertCount(1, $this->senders->findForUser($other));
        self::assertSame([], $this->senders->findForUser($this->user));
        self::assertFalse($this->senders->isTrusted($this->user, 'anyone@stripe.com'));
    }

    /** @return list<string> */
    private function addresses(): array
    {
        return array_map(static fn ($entry): string => $entry->address, $this->senders->findForUser($this->user));
    }

    private function seedUser(string $name): User
    {
        $user            = new User();
        $user->email     = $name . '-' . uniqid('', true) . '@plmail.test';
        $user->nameFirst = 'Images';
        $user->nameLast  = 'Owner';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
