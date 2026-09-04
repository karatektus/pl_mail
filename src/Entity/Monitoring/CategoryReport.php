<?php

declare(strict_types=1);

namespace App\Entity\Monitoring;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\User\User;
use App\Repository\Monitoring\CategoryReportRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * "This message is in the wrong tab", said by the person reading it.
 *
 * WHY A ROW AND NOT A LOG LINE
 * ────────────────────────────
 * Because the point is to be READ BACK, in a batch, by somebody deciding
 * whether a rule or a prompt should change. A log line is written to be found
 * once while chasing a fault; these are written to be sorted through later,
 * counted, and quoted. They also outlive log retention on purpose — a pattern
 * across twenty reports over two months is exactly the thing a thirty-day
 * pruning window would destroy.
 *
 * WHAT IS KEPT, AND WHAT IS DELIBERATELY NOT
 * ──────────────────────────────────────────
 * The four answers and the evidence they were reached from: who sent it, the
 * subject, which bulk headers the message carries, and what each of Gmail, the
 * rules and the model said. That is the whole input to the decision — which is
 * not a coincidence, it is the same set ClassifyMailHandler::describe() sends
 * the model, plus the verdicts.
 *
 * NOT THE BODY. It is the most sensitive part of a message and the least useful
 * here: measured, most marketing mail has no plain-text part at all and the
 * classifier sees `(no plain text part)`, so a body column would be empty for
 * exactly the mail most often reported. A report is about a decision, and the
 * body was rarely part of it.
 *
 * Sender and subject ARE kept, and on a multi-user installation an
 * administrator reading these sees them for other people's mail. That is a real
 * cost, taken deliberately: a report saying only "something was wrong" is not
 * worth the button. It is why nothing is reported without somebody pressing it.
 */
#[ORM\Entity(repositoryClass: CategoryReportRepository::class)]
#[ORM\Table(name: 'category_report')]
#[ORM\Index(columns: ['created_at'], name: 'idx_category_report_created')]
class CategoryReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /**
     * The message, by id and nothing else.
     *
     * Not a relation: a report outlives the mail it is about — somebody deletes
     * the message, the pattern it is evidence of does not go away — and a
     * cascade would quietly delete the record of a problem along with an
     * example of it.
     */
    #[ORM\Column]
    public int $messageId;

    /**
     * Where it actually was when somebody objected — the THREAD's category.
     *
     * The mailbox lists threads and the tabs are a filter over `thread.category`,
     * so this is the only column that answers "which tab was it in". This read
     * `message.category` first, and the very first report this feature received
     * said `filed:updates` about a conversation its owner was looking at in
     * Primary. True, and about a column nobody can see.
     */
    #[ORM\Column(length: 16, enumType: MessageCategory::class)]
    public MessageCategory $filed;

    /**
     * The reported message's own category, when it disagrees with its thread.
     *
     * Null when they agree, which is the ordinary case. When they do not, the
     * conversation is being placed by a sibling message or by a pin rather than
     * by anything on the message in front of the reader — and that is a
     * different problem from a misclassification, with a different fix.
     */
    #[ORM\Column(length: 16, nullable: true, enumType: MessageCategory::class)]
    public ?MessageCategory $filedMessage = null;

    /** Somebody put this conversation where it is by hand. Nothing else applies. */
    #[ORM\Column]
    public bool $pinned = false;

    /** Where the reader says it belongs. The whole point of the button. */
    #[ORM\Column(length: 16, enumType: MessageCategory::class)]
    public MessageCategory $shouldBe;

    /** Gmail's own answer, or null on an account that is not one. */
    #[ORM\Column(length: 16, nullable: true, enumType: MessageCategory::class)]
    public ?MessageCategory $gmail = null;

    #[ORM\Column(length: 16, nullable: true, enumType: MessageCategory::class)]
    public ?MessageCategory $rules = null;

    /** What the header cascade matched on — `list-unsubscribe`, and so on. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $rulesSignal = null;

    #[ORM\Column(length: 16, nullable: true, enumType: MessageCategory::class)]
    public ?MessageCategory $model = null;

    /** Which of the three was deciding, from the reader's own settings. */
    #[ORM\Column(length: 16)]
    public string $source;

    /**
     * Whether that reader had asked for the provider's labels to be overruled.
     *
     * `source` alone does not say it, and without it a line reading
     * `gmail:updates ... by:assistant` is unreadable: it does not say whether
     * Gmail was consulted and lost, or never consulted at all.
     */
    #[ORM\Column]
    public bool $overrideProvider = false;

    /**
     * Whether the model was ever actually asked.
     *
     * `model:-` covers two completely different failures — never asked, and
     * asked and returned nothing — and only one of them is a prompt problem.
     */
    #[ORM\Column]
    public bool $aiAsked = false;

    /**
     * Whether the message had a plain-text part for the classifier to read.
     *
     * The single most decisive input that is not visible anywhere else. Most
     * marketing mail is HTML-only, the classifier is handed `(no plain text
     * part)`, and it is then deciding on headers and a subject alone. A report
     * about a mail the model never really saw is not evidence about the prompt.
     */
    #[ORM\Column]
    public bool $hasPlainText = false;

    /** The bulk headers the message carries, names only. Never their values. */
    #[ORM\Column(length: 255)]
    public string $bulkHeaders = '';

    /**
     * List-Id's VALUE, which is the exception to the names-only rule above.
     *
     * The others are kept as names because their values are tracking links —
     * per-recipient, useless twice, and the most identifying thing in the
     * message. List-Id is the opposite: a stable name for the mailing itself
     * (`<offers.example.com>`), identical for every recipient, and the exact
     * string a rule would be written against. Reporting that a shop's offers
     * are misfiled without it leaves whoever reads this guessing at the
     * identifier they would need.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $listId = null;

    #[ORM\Column(length: 320)]
    public string $fromAddress = '';

    #[ORM\Column(length: 255)]
    public string $fromName = '';

    #[ORM\Column(type: 'text')]
    public string $subject = '';

    #[ORM\Column]
    public DateTimeImmutable $createdAt;

    /**
     * When somebody decided this one had been dealt with.
     *
     * Arrived with the unified panel, and it is the column that makes these
     * rows belong in the same list as the missed-insight reports: a triage list
     * is only a worklist if a row can leave it. Before this, the only way to
     * stop looking at a category report was to delete every one of them, which
     * meant the evidence for a rule went at the same moment the rule changed —
     * exactly when somebody might want to check it again.
     *
     * A stamp and not a flag, for InsightReport::$handledAt's reason: "when"
     * answers "has this been looked at since the last release" and "yes" does
     * not.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $handledAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * One report, as a line somebody can paste somewhere useful.
     *
     * Built here rather than in the template because it is the PRODUCT — the
     * button exists so this text can be handed to whoever is going to change a
     * rule or a prompt — and a format assembled in Twig would be a format that
     * changes whenever the table's layout does.
     */
    public function asLine(): string
    {
        // Where it sat, and — only when they disagree — what the reported
        // message itself said and whether a pin is holding it there.
        $notes = array_filter([
            null !== $this->filedMessage ? 'msg:' . $this->filedMessage->value : null,
            $this->pinned ? 'pinned' : null,
        ]);

        return sprintf(
            "%s | filed:%s%s should:%s | gmail:%s rules:%s%s model:%s | by:%s%s | headers:%s%s | body:%s | from:%s <%s> | %s",
            $this->createdAt->format('Y-m-d'),
            $this->filed->value,
            [] === $notes ? '' : '(' . implode(',', $notes) . ')',
            $this->shouldBe->value,
            null === $this->gmail ? '-' : $this->gmail->value,
            null === $this->rules ? '-' : $this->rules->value,
            null !== $this->rulesSignal ? '(' . $this->rulesSignal . ')' : '',
            // Three states, not two: never asked is not the same failure as
            // asked and got nothing, and only the second is about the prompt.
            null !== $this->model ? $this->model->value : ($this->aiAsked ? 'noanswer' : 'notasked'),
            $this->source,
            $this->overrideProvider ? ' override:yes' : '',
            '' === $this->bulkHeaders ? 'none' : $this->bulkHeaders,
            null !== $this->listId ? ' list-id:' . $this->listId : '',
            $this->hasPlainText ? 'text' : 'none',
            $this->fromName,
            $this->fromAddress,
            $this->subject,
        );
    }
}
