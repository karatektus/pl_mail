<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The summary card's Stop, told apart from a connection that went.
 *
 * A streamed summary whose reader has gone is finished and stored anyway:
 * connections drop for reasons plMail cannot see, and "reload and it is there"
 * beats paying for the run twice. From inside the stream, Stop looks exactly
 * like that — the card aborts its fetch either way — so the card also SAYS it,
 * as a request naming the run, and ThreadSummaryController looks here before it
 * finishes anything. This is the one departure that means "do not".
 *
 * KEYED BY A RUN THE CARD NAMES, not by the thread alone. A Stop filed against
 * the thread would stop the next run too (Stop, then Summarise again), and one
 * cleared when a run starts would be lost whenever the Stop landed first — a
 * double click is enough, because Stop appears where Summarise was. The thread
 * is in the key as well, so the ownership check on each endpoint is what
 * decides whose run can be stopped.
 */
final readonly class ThreadSummaryStop
{
    public function __construct(
        #[Autowire(service: 'app.summary_stop')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    /** What the card sends: 32 lowercase hex characters, and nothing else becomes a key. */
    public static function isRunId(string $run): bool
    {
        return 1 === preg_match('/^[0-9a-f]{32}$/', $run);
    }

    public function request(int $threadId, string $run): void
    {
        $item = $this->pool->getItem(self::key($threadId, $run));
        $item->set(true);

        $this->pool->save($item);
    }

    /**
     * Always false for a run with no name. A page loaded before Stop worked
     * this way still gets its summary — finished, the way a dropped connection
     * is — rather than a refusal in the middle of a deploy.
     */
    public function isRequested(int $threadId, ?string $run): bool
    {
        if (null === $run) {
            return false;
        }

        return $this->pool->hasItem(self::key($threadId, $run));
    }

    private static function key(int $threadId, string $run): string
    {
        return sprintf('summary_stop.%d.%s', $threadId, $run);
    }
}
