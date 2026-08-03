<?php

declare(strict_types=1);

namespace App\Tests\Repository\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Connection lookups, where every guarantee is about scope and order.
 *
 * Each of these reads is reachable from a request carrying an id, so "scoped to
 * the owner" is not tidiness — dropping the user from the criteria turns a
 * settings page into a way to read someone else's connection. And a user may
 * hold two connections to one provider, so "the provider's connection" needs a
 * rule for which one; oldest-first is at least stable, and an unordered query
 * would hand back whichever row the planner happened to reach first.
 */
final class IntegrationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private IntegrationRepository $repository;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(IntegrationRepository::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheListIsOrderedByProviderThenName(): void
    {
        $this->integration(Provider::Nextcloud, 'Work');
        $this->integration(Provider::Dropbox, 'Personal');
        $this->integration(Provider::Nextcloud, 'Archive');

        $listed = $this->repository->findForUserOrdered($this->user);

        self::assertSame(
            [
                [Provider::Dropbox->value, 'Personal'],
                [Provider::Nextcloud->value, 'Archive'],
                [Provider::Nextcloud->value, 'Work'],
            ],
            array_map(
                static fn (Integration $i): array => [$i->provider->value, (string) $i->name],
                $listed,
            ),
        );
    }

    public function testOnlyActiveConnectionsAreOfferedToTheMenus(): void
    {
        $live = $this->integration(Provider::Dropbox, 'Live');
        $this->integration(Provider::Nextcloud, 'Disconnected', isActive: false);

        self::assertSame([$live], $this->repository->findActiveForUser($this->user));
    }

    public function testAnotherUsersConnectionCannotBeResolvedById(): void
    {
        $stranger = $this->seedUser();
        $theirs   = $this->integration(Provider::Dropbox, 'Theirs', owner: $stranger);

        self::assertNull($this->repository->findOneForUser($this->user, (int) $theirs->id));
        self::assertSame($theirs, $this->repository->findOneForUser($stranger, (int) $theirs->id));
    }

    /** Two connections to one provider: the oldest is the stable answer. */
    public function testTheProviderLookupPicksTheOldestConnection(): void
    {
        $first = $this->integration(Provider::Nextcloud, 'First');
        $this->integration(Provider::Nextcloud, 'Second');

        self::assertSame(
            $first,
            $this->repository->findOneByProviderForUser($this->user, Provider::Nextcloud),
        );
    }

    public function testTheProviderLookupIsScopedToTheOwner(): void
    {
        $stranger = $this->seedUser();
        $this->integration(Provider::Nextcloud, 'Theirs', owner: $stranger);

        self::assertNull(
            $this->repository->findOneByProviderForUser($this->user, Provider::Nextcloud),
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function integration(
        Provider $provider,
        string   $name,
        bool     $isActive = true,
        ?User    $owner = null,
    ): Integration {
        $integration           = new Integration($owner ?? $this->user, $provider, $name);
        $integration->isActive = $isActive;

        $this->em->persist($integration);
        $this->em->flush();

        return $integration;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'integrations-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Integrations';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
