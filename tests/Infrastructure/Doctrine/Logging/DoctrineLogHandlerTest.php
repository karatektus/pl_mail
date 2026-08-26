<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine\Logging;

use App\Infrastructure\Doctrine\Logging\DoctrineLogHandler;
use Doctrine\DBAL\Connection;
use App\Service\Monitoring\LogLevelResolver;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What an uncaught exception looks like by the time an operator reads it.
 *
 * The dashboard rendered `"exception": []` for every fault this application
 * ever had, because the handler wrote `json_encode($record->context)` and a
 * Throwable has no public properties — so class, file, line, trace and the
 * previous chain were all encoded as `{}`. The level said CRITICAL, the
 * message named the error, and the single field that says WHERE was thrown
 * away on the way into the table.
 *
 * These tests are about the fields being there at all. Two of them are about
 * fields deliberately NOT being there, which matters just as much: a log an
 * admin can read must not become a place where passwords and session cookies
 * accumulate.
 */
final class DoctrineLogHandlerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM log_entry');

        // A stored level would outrank the fallback the tests below hand their
        // resolver, which is the whole precedence — and would make them lie.
        $this->connection->executeStatement('DELETE FROM log_settings');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The regression, stated as the thing that was missing: an exception has
     * to arrive with the place it came from attached.
     */
    public function testAnExceptionKeepsItsClassFileLineAndTrace(): void
    {
        $context = $this->writeAndRead(new \RuntimeException('the wheels came off'));

        self::assertArrayHasKey('exception', $context);

        $exception = $context['exception'];

        self::assertNotSame([], $exception, 'this is exactly what the dashboard used to show');
        self::assertSame(\RuntimeException::class, $exception['class']);
        self::assertSame('the wheels came off', $exception['message']);
        self::assertStringEndsWith('DoctrineLogHandlerTest.php', $exception['file']);
        self::assertGreaterThan(0, $exception['line']);
        self::assertNotEmpty($exception['trace'], 'the trace is the half that says which line ran');
    }

    /**
     * The previous chain is frequently where the real cause is — a DBAL
     * wrapper around the PDOException that names the constraint, a Messenger
     * HandlerFailedException around whatever the handler actually hit.
     */
    public function testThePreviousChainIsFollowed(): void
    {
        $root    = new \LogicException('the actual cause');
        $wrapper = new \RuntimeException('handling failed', 0, $root);

        $context = $this->writeAndRead($wrapper);

        self::assertSame('handling failed', $context['exception']['message']);
        self::assertArrayHasKey('previous', $context['exception']);
        self::assertSame(\LogicException::class, $context['exception']['previous']['class']);
        self::assertSame('the actual cause', $context['exception']['previous']['message']);
    }

    /**
     * A throwable one level down is worth the same as one at the top — plenty
     * of handlers log `['jobId' => 1, 'error' => $e]`.
     */
    public function testAThrowableNestedInsideTheContextIsExpandedToo(): void
    {
        $context = $this->writeAndRead(null, ['jobId' => 7, 'error' => new \DomainException('nested')]);

        self::assertSame(7, $context['jobId']);
        self::assertSame(\DomainException::class, $context['error']['class']);
        self::assertSame('nested', $context['error']['message']);
    }

    /**
     * ARGUMENTS ARE NOT LOGGED.
     *
     * `getTrace()` carries every argument of every frame, which on a sign-in
     * path is the plaintext password. The frame that took the secret must
     * still appear — dropping the frame would cost the diagnosis — but the
     * value must not.
     */
    public function testStackFramesCarryNoArgumentValues(): void
    {
        $context = $this->writeAndRead($this->throwFrom('hunter2-correct-horse'));

        $trace = implode("\n", $context['exception']['trace']);

        self::assertStringNotContainsString('hunter2-correct-horse', $trace, 'a trace must not leak its arguments');
        self::assertStringContainsString('throwFrom', $trace, 'but the frame that took it still has to be named');
    }

    /**
     * The route, because "a statement timed out" is not actionable and "a
     * statement timed out on app_mail_search" is.
     */
    public function testTheRequestRouteIsRecordedWithoutItsQueryValues(): void
    {
        $request = Request::create('/mail/search?q=quarterly+invoice&page=2');
        $request->attributes->set('_route', 'app_mail_search');

        $stack = new RequestStack();
        $stack->push($request);

        $context = $this->writeAndRead(new \RuntimeException('slow'), [], $stack);

        self::assertSame('app_mail_search', $context['request']['route']);
        self::assertSame('/mail/search', $context['request']['path']);
        self::assertSame('GET', $context['request']['method']);

        // The NAMES say a search ran, which is the diagnostic question. The
        // values are somebody's private business and the admin reading this is
        // not always the person who typed them.
        self::assertSame(['q', 'page'], $context['request']['queryKeys']);

        $encoded = json_encode($context);

        self::assertStringNotContainsString('quarterly', (string) $encoded, 'search terms must not reach the log');
    }

    /** Unchanged behaviour: a 404 is a client mistake, not an application fault. */
    public function testANotFoundIsStillDropped(): void
    {
        $this->handler()->handle($this->record(new NotFoundHttpException('nope')));

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM log_entry'),
            'a missing favicon must not fill the dashboard',
        );
    }

    /**
     * The admin-set level decides what is written, per record.
     *
     * Monolog fixes a handler's level at construction, which is right for one
     * configured in a file and wrong for one an administrator can turn down
     * from the page they are reading. Before this, changing the level meant
     * editing `APP_DB_LOG_LEVEL` on the host and restarting — at exactly the
     * moment that is least convenient.
     */
    public function testTheStoredLevelDecidesWhatIsKept(): void
    {
        $resolver = $this->resolverFixedAt(Level::Error);

        $handler = new DoctrineLogHandler($this->connection, null, $resolver, 'test', 'debug');

        $handler->handle($this->recordAt(Level::Warning, 'below the line'));
        $handler->handle($this->recordAt(Level::Error, 'at the line'));

        $kept = $this->connection->fetchFirstColumn('SELECT message FROM log_entry ORDER BY id');

        self::assertSame(['at the line'], $kept, 'a warning must not be written when the level is error');
    }

    /**
     * Lowering it is the case the feature exists for: something is wrong and
     * the answer is one level further down.
     */
    public function testLoweringTheLevelLetsQuieterRecordsThrough(): void
    {
        $handler = new DoctrineLogHandler($this->connection, null, $this->resolverFixedAt(Level::Info), 'test', 'debug');

        $handler->handle($this->recordAt(Level::Info, 'now worth keeping'));

        self::assertSame(
            ['now worth keeping'],
            $this->connection->fetchFirstColumn('SELECT message FROM log_entry ORDER BY id'),
        );
    }

    /**
     * Without a resolver the handler behaves exactly as it always did, which is
     * what keeps every other test in this file — and any direct construction —
     * meaningful.
     */
    public function testWithoutAResolverTheConstructorLevelStillApplies(): void
    {
        $handler = new DoctrineLogHandler($this->connection, null, null, 'test', 'error');

        $handler->handle($this->recordAt(Level::Warning, 'below the constructor level'));

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM log_entry'),
            'the level passed in must still be honoured when nothing else can answer',
        );
    }

    /**
     * A real resolver with nothing stored, so it answers from the value passed
     * here and these tests stay about the handler. setUp() clears the settings
     * row for exactly this reason.
     */
    private function resolverFixedAt(Level $level): LogLevelResolver
    {
        return new LogLevelResolver($this->connection, $level->toPsrLogLevel());
    }

    private function recordAt(Level $level, string $message): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', $level, $message, []);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A frame that receives a secret, so the trace has one to leak. */
    private function throwFrom(string $password): \Throwable
    {
        return new \RuntimeException('sign-in failed');
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function writeAndRead(?\Throwable $throwable, array $extra = [], ?RequestStack $stack = null): array
    {
        $context = $extra;

        if (null !== $throwable) {
            $context['exception'] = $throwable;
        }

        $this->handler($stack)->handle($this->record(null, $context));

        $stored = $this->connection->fetchOne('SELECT context FROM log_entry ORDER BY id DESC LIMIT 1');

        self::assertIsString($stored, 'the row should have been written');

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function handler(?RequestStack $stack = null): DoctrineLogHandler
    {
        return new DoctrineLogHandler($this->connection, $stack, null, 'test', 'warning');
    }

    /** @param array<string,mixed> $context */
    private function record(?\Throwable $throwable = null, array $context = []): LogRecord
    {
        if (null !== $throwable) {
            $context['exception'] = $throwable;
        }

        return new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Critical,
            'something went wrong',
            $context,
        );
    }
}
