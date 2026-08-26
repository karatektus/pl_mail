<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Logging;

use App\Service\Monitoring\LogLevelResolver;
use Doctrine\DBAL\Connection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Persists Monolog records to the log_entry table so every container's
 * logs aggregate in one queryable place for the admin dashboard.
 *
 * Uses DBAL directly (not the ORM): log writes frequently happen in error
 * paths where the EntityManager is closed or mid-rollback, and must never
 * depend on its state. Insert failures are swallowed — a broken DB must
 * not cascade into a logging loop, and stderr logging still exists.
 *
 * Minimum level comes from APP_DB_LOG_LEVEL (default: warning); the source
 * container name from APP_CONTAINER_NAME (set per service in compose).
 */
final class DoctrineLogHandler extends AbstractProcessingHandler
{
    private const int MAX_MESSAGE_LENGTH = 4000;

    /**
     * Stack frames kept per exception.
     *
     * Deep enough to cross the framework and reach application code, which is
     * the part anyone reading this actually wants; short of keeping a hundred
     * frames of kernel plumbing on every row of a table that is written to on
     * every warning.
     */
    private const int MAX_TRACE_FRAMES = 30;

    /** How far down a `previous` chain to follow before giving up. */
    private const int MAX_PREVIOUS_DEPTH = 5;

    /** How deep to walk a context array looking for throwables. */
    private const int MAX_CONTEXT_DEPTH = 4;

    public function __construct(
        private readonly Connection        $connection,
        private readonly ?RequestStack     $requests = null,
        private readonly ?LogLevelResolver $levels = null,
        #[Autowire(env: 'APP_CONTAINER_NAME')]
        private readonly string            $source = 'app',
        #[Autowire(env: 'APP_DB_LOG_LEVEL')]
        string                             $minimumLevel = 'warning',
    ) {
        // Debug, not the configured level, and isHandling() below is why. The
        // parent compares against whatever is passed here and there would be no
        // way back up from it — a floor set at construction cannot be lowered
        // by an admin at half past two in the morning, which is the entire
        // point of the setting. The real decision is made per record.
        //
        // The configured value is still parsed and kept, because it is the
        // answer whenever no resolver was given: the handler is constructed
        // directly in tests, and there it should behave exactly as it always
        // did.
        parent::__construct(
            null === $levels ? Level::fromName(ucfirst(strtolower($minimumLevel))) : Level::Debug,
            true,
        );
    }

    /**
     * Whether this record is worth a row, asked fresh every time.
     *
     * Monolog decides this once, from the level handed to the constructor. That
     * is right for a handler configured in a file and wrong for one an
     * administrator can turn down from the page they are reading — so the
     * question is re-asked, and the resolver behind it does the caching.
     *
     * Without a resolver the parent's own comparison stands, which keeps a
     * directly-constructed handler behaving the way it always has.
     */
    public function isHandling(LogRecord $record): bool
    {
        if (null === $this->levels) {
            return parent::isHandling($record);
        }

        return $record->level->value >= $this->levels->level()->value;
    }

    /**
     * Is this a client asking for something that is not there?
     *
     * Symfony logs an unmatched route at error level, so these land in the
     * dashboard looking like faults in the application. They are not: a browser
     * requesting /favicon.ico on a page that declares no icon, a bot probing
     * /wp-login.php, a stale bookmark. The dashboard is what an operator checks
     * when something is wrong, and it stops being read the moment it is mostly
     * this.
     *
     * The other handlers already drop them — monolog.yaml sets
     * `excluded_http_codes: [404, 405]` on the fingers_crossed handler feeding
     * stderr — but that option belongs to fingers_crossed and this handler is a
     * plain service, so it never applied here. Hence doing it by hand.
     *
     * Only the two codes that mean "no such thing". A 403 stays: someone
     * reaching for what they may not have is worth an operator's attention.
     */
    private static function isNotFound(LogRecord $record): bool
    {
        $exception = $record->context['exception'] ?? null;

        return $exception instanceof NotFoundHttpException
            || $exception instanceof MethodNotAllowedHttpException;
    }

    protected function write(LogRecord $record): void
    {
        if (self::isNotFound($record)) {
            return;
        }

        try {
            $context = json_encode(
                $this->describeContext($record),
                JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            );

            $this->connection->insert('log_entry', [
                'channel'    => mb_substr($record->channel, 0, 64),
                'level'      => $record->level->value,
                'level_name' => mb_substr($record->level->getName(), 0, 32),
                'message'    => mb_substr($record->message, 0, self::MAX_MESSAGE_LENGTH),
                'context'    => false !== $context ? $context : null,
                'source'     => mb_substr($this->source, 0, 64),
                'created_at' => $record->datetime->format('Y-m-d H:i:s'),
                // Written by hand because this insert deliberately bypasses the
                // ORM — a log handler cannot flush an EntityManager that may be
                // mid-transaction, or in the failed state that produced the log
                // line in the first place. So TimestampableTrait's PrePersist
                // never runs here and the NOT NULL column has to be filled from
                // this side. A log line is written once, so it equals created_at.
                'updated_at' => $record->datetime->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Never let logging failures cascade.
        }
    }

    /**
     * The record's context, with the parts json_encode() throws away put back.
     *
     * WHY THIS EXISTS
     * ───────────────
     * `json_encode($record->context)` was written straight to the column, and
     * for the one context key that matters it produced `{}`. Monolog puts the
     * Throwable itself under `exception`, and a Throwable has no public
     * properties — class, file, line, trace and the previous chain are all
     * behind accessors, so json_encode silently encodes an empty object.
     *
     * Every uncaught exception this application has ever recorded therefore
     * landed in the dashboard as `"exception": []`: the level said CRITICAL,
     * the message named the error, and the one thing that says WHERE was
     * dropped on the way in. Diagnosing anything meant guessing from the
     * message alone.
     *
     * @return array<string,mixed>
     */
    private function describeContext(LogRecord $record): array
    {
        $context = self::describeValue($record->context, 0);

        if (false === is_array($context)) {
            $context = [];
        }

        if ([] !== $record->extra) {
            $extra = self::describeValue($record->extra, 0);

            if ([] !== $extra) {
                $context['extra'] = $extra;
            }
        }

        $request = $this->describeRequest();

        if (null !== $request) {
            $context['request'] = $request;
        }

        return $context;
    }

    /**
     * Which request produced this, when there was one.
     *
     * The route is the point. A log line saying a statement timed out is not
     * actionable; the same line saying it timed out on `app_mail_search` names
     * the code path in one word. Absent on CLI and in workers, where there is
     * no request and this is simply omitted.
     *
     * DELIBERATELY NOT THE QUERY STRING'S VALUES, nor headers, nor the body.
     * A search term is somebody's private business and an admin reading the
     * dashboard is not always the person who typed it; headers carry session
     * cookies and Authorization. The parameter NAMES are kept because "there
     * was a `q`" distinguishes a search from a bare page load, which is the
     * diagnostic question, and a name is not a secret.
     *
     * @return array<string,mixed>|null
     */
    private function describeRequest(): ?array
    {
        $request = $this->requests?->getMainRequest();

        if (null === $request) {
            return null;
        }

        $described = [
            'method' => $request->getMethod(),
            'path'   => mb_substr($request->getPathInfo(), 0, 512),
            'route'  => $request->attributes->get('_route'),
        ];

        $queryKeys = array_keys($request->query->all());

        if ([] !== $queryKeys) {
            $described['queryKeys'] = array_slice($queryKeys, 0, 20);
        }

        return $described;
    }

    /**
     * One context value, with throwables expanded and everything else left be.
     *
     * Walks arrays because a throwable is not always at the top level — a
     * handler that logs `['job' => $x, 'error' => $e]` puts one a level down,
     * and that one is worth just as much.
     */
    private static function describeValue(mixed $value, int $depth): mixed
    {
        if ($value instanceof Throwable) {
            return self::describeThrowable($value, 0);
        }

        if (true === is_array($value) && $depth < self::MAX_CONTEXT_DEPTH) {
            $described = [];

            foreach ($value as $key => $item) {
                $described[$key] = self::describeValue($item, $depth + 1);
            }

            return $described;
        }

        return $value;
    }

    /**
     * A throwable as the fields a person actually reads.
     *
     * @return array<string,mixed>
     */
    private static function describeThrowable(Throwable $throwable, int $depth): array
    {
        $described = [
            'class'   => $throwable::class,
            'message' => mb_substr($throwable->getMessage(), 0, self::MAX_MESSAGE_LENGTH),
            'code'    => $throwable->getCode(),
            'file'    => $throwable->getFile(),
            'line'    => $throwable->getLine(),
            'trace'   => self::describeTrace($throwable),
        ];

        $previous = $throwable->getPrevious();

        // The previous chain is frequently where the real cause is: a DBAL
        // DriverException wrapping the PDOException that names the constraint,
        // a HandlerFailedException wrapping whatever the handler actually hit.
        if (null !== $previous && $depth < self::MAX_PREVIOUS_DEPTH) {
            $described['previous'] = self::describeThrowable($previous, $depth + 1);
        }

        return $described;
    }

    /**
     * The stack, as `file:line function` strings.
     *
     * ARGUMENTS ARE DROPPED, and not to save space. `getTrace()` hands back
     * every argument of every frame, which on a login path is the plaintext
     * password and on a mail path is the message body. A log table an admin
     * can read is not the place for either, and a trace without arguments
     * still says exactly which line ran.
     *
     * @return list<string>
     */
    private static function describeTrace(Throwable $throwable): array
    {
        $frames = [];

        foreach ($throwable->getTrace() as $frame) {
            if (count($frames) >= self::MAX_TRACE_FRAMES) {
                $frames[] = '… truncated';

                break;
            }

            $call = (string) ($frame['class'] ?? '')
                . (string) ($frame['type'] ?? '')
                . (string) ($frame['function'] ?? '');

            $where = true === isset($frame['file'])
                ? $frame['file'] . ':' . (string) ($frame['line'] ?? 0)
                : '[internal]';

            $frames[] = mb_substr(trim($where . ' ' . $call), 0, 512);
        }

        return $frames;
    }
}
