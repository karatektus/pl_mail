<?php

declare(strict_types=1);

namespace App\Tests\Service\Monitoring;

use App\Entity\Monitoring\LogSettings;
use App\Repository\Monitoring\LogSettingsRepository;
use App\Service\Monitoring\LogLevelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which level actually applies, given a row, an environment variable, or
 * neither.
 *
 * The precedence is the whole feature: a stored level wins, no stored level
 * means follow `APP_DB_LOG_LEVEL`, and null in the row is how an install gets
 * back to following it. Storing the environment's current value instead of null
 * would look identical on the day it was set and diverge silently afterwards,
 * so "no choice" has to stay expressible.
 */
final class LogLevelResolverTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private LogSettingsRepository $settings;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->settings   = $container->get(LogSettingsRepository::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM log_settings');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testWithNoRowItFollowsTheEnvironment(): void
    {
        self::assertSame(Level::Error, $this->resolver('error')->level());
    }

    public function testAStoredLevelWinsOverTheEnvironment(): void
    {
        $this->store('info');

        self::assertSame(Level::Info, $this->resolver('error')->level());
    }

    /**
     * The way back. A row that exists but names no level is an install that has
     * been configured here and then handed back to its environment.
     */
    public function testARowWithNoLevelFollowsTheEnvironmentAgain(): void
    {
        $this->store(null);

        self::assertSame(Level::Critical, $this->resolver('critical')->level());
    }

    /**
     * Nonsense in either place resolves to warning rather than throwing. This
     * runs inside the logger: an exception here would be raised while deciding
     * whether to record an exception.
     */
    public function testRubbishFallsBackRatherThanThrowing(): void
    {
        $this->store('not-a-level');

        self::assertSame(Level::Warning, $this->resolver('also-not-a-level')->level());
    }

    /** Case and padding are what a person types, not an error. */
    public function testTheStoredNameIsReadLeniently(): void
    {
        $this->store('  WARNING ');

        self::assertSame(Level::Warning, $this->resolver('debug')->level());
    }

    /**
     * The cache is what keeps a database round-trip out of every log line, and
     * forget() is what stops the process that made the change from reporting
     * the old value back to the person who made it.
     */
    public function testAChangeIsSeenAfterForget(): void
    {
        $resolver = $this->resolver('error');

        self::assertSame(Level::Error, $resolver->level());

        $this->store('info');

        self::assertSame(Level::Error, $resolver->level(), 'still cached, which is the point of the cache');

        $resolver->forget();

        self::assertSame(Level::Info, $resolver->level());
    }

    private function store(?string $level): void
    {
        $settings = $this->settings->currentOrNew();
        $settings->minimumLevel = $level;

        $this->em->persist($settings);
        $this->em->flush();
    }

    private function resolver(string $fallback): LogLevelResolver
    {
        return new LogLevelResolver($this->connection, $fallback);
    }
}
