<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\OAuth\OAuthAccountLinker;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A connected Gmail or Microsoft account gets its own colour, like any other.
 *
 * The report: accounts did not get different accent colours when created.
 * AccountCreator hands out `colorIndex` from the lowest free palette slot, but
 * OAuthAccountLinker builds its Account without going through it, so every
 * OAuth account kept the column default of 0 — the same dot in the sidebar and
 * the same corner mark on every message row, which is the one job that mark has.
 *
 * Expected values are the palette slots, derived by hand: the user below holds
 * a password account in slot 0, so the first OAuth account takes 1 and the
 * second takes 2. "Every OAuth account stays at 0" and "the slot is never
 * assigned at all" both fail that, and each is a different way for it to break.
 */
final class OAuthAccountLinkerColourTest extends KernelTestCase
{
    private OAuthAccountLinker $linker;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->linker     = self::getContainer()->get(OAuthAccountLinker::class);
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEachConnectedAccountTakesTheLowestFreeColour(): void
    {
        $user     = MailFixtures::user($this->em, 'oauth-colour');
        $existing = MailFixtures::account($this->em, $user);

        $existing->colorIndex = 0;
        $this->em->flush();

        $google    = $this->upsert($user, MailProvider::Google, $this->address('google'), $this->token());
        $microsoft = $this->upsert($user, MailProvider::Microsoft, $this->address('microsoft'), $this->token());

        self::assertSame(0, $existing->colorIndex, 'the account that was there keeps its colour');
        self::assertSame(1, $google->colorIndex, 'slot 0 is taken, so the first OAuth account gets slot 1');
        self::assertSame(2, $microsoft->colorIndex, 'slots 0 and 1 are taken, so the next gets slot 2');
    }

    public function testReconnectingAnAccountDoesNotRepaintIt(): void
    {
        $user = MailFixtures::user($this->em, 'oauth-colour');
        $address = $this->address('google');

        $first = $this->upsert($user, MailProvider::Google, $address, $this->token());
        $other = $this->upsert($user, MailProvider::Microsoft, $this->address('microsoft'), $this->token());

        self::assertSame(0, $first->colorIndex);
        self::assertSame(1, $other->colorIndex);

        // The same grant coming back is found again, not created again.
        $again = $this->upsert($user, MailProvider::Google, $address, $this->token());

        self::assertSame($first->id, $again->id);
        self::assertSame(0, $again->colorIndex, 'a colour is the account\'s own and never moves');
    }

    /**
     * link() is upsert() plus push registration and alias seeding, and both of
     * those call the provider for real. Nothing about the colour depends on them.
     */
    private function upsert(
        User $user,
        MailProvider $provider,
        string $email,
        AccessTokenInterface $token,
    ): Account {
        return new ReflectionMethod(OAuthAccountLinker::class, 'upsert')
            ->invoke($this->linker, $user, $provider, $email, $token);
    }

    private function address(string $prefix): string
    {
        return sprintf('%s-%s@example.test', $prefix, uniqid('', true));
    }

    private function token(): AccessTokenInterface
    {
        $token = $this->createStub(AccessTokenInterface::class);
        $token->method('getToken')->willReturn('access-token');
        $token->method('getRefreshToken')->willReturn(null);
        $token->method('getExpires')->willReturn(null);
        $token->method('getValues')->willReturn([]);

        return $token;
    }
}
