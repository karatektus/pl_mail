<?php

declare(strict_types=1);

namespace App\Command\Ai;

use App\Entity\Ai\AiFeature;
use App\Repository\User\UserRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\EmbeddingCatchUp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The nightly backstop for mail that arrived and was never indexed.
 *
 * WHY THIS EXISTS AT ALL
 * ──────────────────────
 * Arriving mail used to be embedded within seconds of landing, by a post-ingest
 * step. That spent a round trip to the model host on every message this
 * installation has ever received, to answer a question almost nobody asks —
 * searching for mail you read ten minutes ago is the rarest thing anyone does
 * with a mail client. So indexing moved to the two moments that are worth
 * paying for: the warm window straight after somebody searches, and this.
 *
 * This is the boring half, and it is the half that has to be reliable. Somebody
 * who has not searched for a fortnight still expects the fortnight of mail to
 * be findable when they finally do.
 *
 * BOUNDED, AND THAT IS THE WHOLE DESIGN
 * ─────────────────────────────────────
 * A per-mailbox ceiling, and the newest mail first. Without one this is
 * EmbeddingBackfill with no state row, no pause button and no panel — it would
 * pick up a hundred thousand unindexed messages on the first night it ran on an
 * install that had never done a pass, queue every one of them onto the ingest
 * transport, and put new mail behind hours of catalogue work.
 *
 * The ceiling is what keeps the division of labour honest: whole-mailbox work
 * belongs to `app:ai:embed-mailbox`, which claims a state row, walks forwards
 * for hours, resumes after a restart and can be paused from the admin panel.
 * This one tops up the last day or so and stops.
 *
 * SAFE TO RUN TWICE, AND SAFE TO RUN BESIDE A BACKFILL
 * ────────────────────────────────────────────────────
 * It queues ids; EmbedMessagesHandler skips whatever is already stored under
 * the current model. Two runs that overlap cost one query per batch and no
 * model call at all, which is the same bargain `app:ai:embed-mailbox` makes.
 */
#[AsCommand(
    name: 'app:ai:index-new-mail',
    description: 'Queue a bounded catch-up pass over mail that arrived and has not been indexed for semantic search',
)]
final class IndexNewMailCommand extends Command
{
    /**
     * Messages per mailbox per run.
     *
     * Several times an ordinary day's mail, so a normal inbox is always fully
     * caught up by morning and the ceiling is invisible. It is a ceiling rather
     * than a target: the query stops as soon as it runs out of outstanding
     * mail, and on a healthy install it finds nothing at all, because the
     * searches during the day already did the work.
     *
     * A mailbox that is genuinely a long way behind — the feature was switched
     * on last week, a model was changed — is NOT what this is for, and it will
     * take five hundred a night to get there. That case wants
     * `app:ai:embed-mailbox`, which says so in the admin panel.
     */
    private const string DEFAULT_LIMIT = '500';

    /** Users hydrated per page, so a large install does not load them all. */
    private const int USER_PAGE = 50;

    public function __construct(
        private readonly UserRepository   $users,
        private readonly AiAssistant      $ai,
        private readonly EmbeddingCatchUp $catchUp,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Messages per mailbox', self::DEFAULT_LIMIT)
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'One mailbox instead of every mailbox');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Success, not failure. This runs nightly on every installation, and
        // almost none of them have switched the AI on — a non-zero exit would
        // put a red line in the scheduler log every night for a feature nobody
        // asked for. `app:ai:embed-mailbox` refuses loudly instead, and should:
        // somebody typed that one.
        if (false === $this->ai->isEnabledFor(AiFeature::Search)) {
            $io->note('Search by meaning is off, or no embedding model is configured — nothing to index.');

            return Command::SUCCESS;
        }

        // Clamped rather than validated, the way app:ai:prune-metrics clamps
        // its retention: a typo in a compose file or a crontab must not be able
        // to turn a bounded sweep into an unbounded one, and `--limit=0` on a
        // schedule would be a job that runs every night and does nothing.
        $limit = max(1, (int) $input->getOption('limit'));
        $email = $input->getOption('email');

        if (null !== $email) {
            $user = $this->users->findOneBy(['email' => (string) $email]);

            if (null === $user || null === $user->id) {
                $io->error('No such user.');

                return Command::FAILURE;
            }

            $queued = $this->catchUp->sweep((int) $user->id, $limit);

            $io->success(sprintf('Queued %d message(s) from %s.', $queued, (string) $user->email));

            return Command::SUCCESS;
        }

        $lastId    = 0;
        $mailboxes = 0;
        $queued    = 0;

        // Keyset by id, the shape CalendarBackfillTask uses: an OFFSET walk
        // skips rows when the set shifts underneath it, and on an install
        // running a demo it shifts every ten minutes.
        while (true) {
            $page = $this->users->findBatchAfterId($lastId, self::USER_PAGE);

            if ([] === $page) {
                break;
            }

            foreach ($page as $user) {
                $lastId = (int) $user->id;

                $queued += $this->catchUp->sweep($lastId, $limit);
                $mailboxes++;
            }
        }

        $io->success(sprintf('Queued %d message(s) for indexing, across %d mailbox(es).', $queued, $mailboxes));

        return Command::SUCCESS;
    }
}
