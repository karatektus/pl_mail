<?php

declare(strict_types=1);

namespace App\Entity\Job;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Entity\User\User;
use App\Repository\Job\BackgroundJobRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A piece of work too big for a request, and what it has got through.
 *
 * WHY THIS EXISTS
 *
 * Selecting a whole view and marking it read did the entire thing inside the
 * web request: every thread hydrated, every message loaded, an ownership check
 * per thread, then the write. On a mailbox with five thousand unread it hit
 * `Maximum execution time of 30 seconds exceeded` and the user got a broken
 * page and no idea whether any of it had happened.
 *
 * WHY IT IS A ROW AND NOT A MERCURE MESSAGE
 *
 * The same reason MailRule keeps its run state on the row: a job over a large
 * mailbox takes minutes and the person who started it will close the tab. State
 * on a row means "is it done?" is answered by whatever the page renders, on any
 * device, at any later time. Mercure only tells an already-open page to look
 * again — it is the nudge, never the record.
 *
 * WHY IT IS PER USER AND NOT PER ACCOUNT
 *
 * A view selection crosses accounts by design — the unified inbox is the whole
 * point of it — so the owner is the only thing every job in it has in common.
 */
#[ORM\Entity(repositoryClass: BackgroundJobRepository::class)]
#[ORM\Table(name: 'background_job')]
#[ORM\Index(name: 'idx_background_job_owner_state', columns: ['usr_id', 'state'])]
class BackgroundJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    #[ORM\Column(length: 32, enumType: JobKind::class)]
    public JobKind $kind;

    #[ORM\Column(length: 16, enumType: JobState::class, options: ['default' => 'queued'])]
    public JobState $state = JobState::Queued;

    /**
     * How many conversations this job expects to touch.
     *
     * Written when the work is planned rather than estimated up front, so the
     * progress a user sees is against a real number. Zero until then, which is
     * what "queued" looks like.
     */
    #[ORM\Column(options: ['default' => 0])]
    public int $total = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $processed = 0;

    #[ORM\Column]
    public DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $finishedAt = null;

    /**
     * The view this job acts on: scope, value, and the unread filter.
     *
     * Stored rather than carried in the envelope, because the selection is what
     * the job IS and a queue row should not be the size of the work it
     * describes. See App\Service\Mail\ListViewResolver for why a view is a
     * named scope rather than the URL somebody was looking at.
     *
     * @var array{scope: string, value: string, unreadOnly: bool}
     */
    #[ORM\Column(type: 'json')]
    public array $view = ['scope' => '', 'value' => '', 'unreadOnly' => false];

    /**
     * Why it failed, for the person it failed for.
     *
     * Truncated like every other stored provider message in this app: a stack
     * trace is not something to put in front of somebody.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $failureReason = null;

    public function __construct(User $usr, JobKind $kind)
    {
        $this->usr       = $usr;
        $this->kind      = $kind;
        $this->createdAt = new DateTimeImmutable();
    }

    /** Whole percent, floored, and never a lie: 0 until there is a total. */
    public function percent(): int
    {
        if (0 >= $this->total) {
            return 0;
        }

        return (int) floor(min(100, ($this->processed / $this->total) * 100));
    }

    public function isActive(): bool
    {
        return $this->state->isActive();
    }

    public function finish(JobState $state, ?string $reason = null): void
    {
        $this->state         = $state;
        $this->finishedAt    = new DateTimeImmutable();
        $this->failureReason = null !== $reason ? mb_substr($reason, 0, 500) : null;
    }
}
