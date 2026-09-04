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

    /** Where it actually was when somebody objected. */
    #[ORM\Column(length: 16, enumType: MessageCategory::class)]
    public MessageCategory $filed;

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

    /** The bulk headers the message carries, names only. Never their values. */
    #[ORM\Column(length: 255)]
    public string $bulkHeaders = '';

    #[ORM\Column(length: 320)]
    public string $fromAddress = '';

    #[ORM\Column(length: 255)]
    public string $fromName = '';

    #[ORM\Column(type: 'text')]
    public string $subject = '';

    #[ORM\Column]
    public DateTimeImmutable $createdAt;

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
        return sprintf(
            "%s | filed:%s should:%s | gmail:%s rules:%s%s model:%s | by:%s | headers:%s | from:%s <%s> | %s",
            $this->createdAt->format('Y-m-d'),
            $this->filed->value,
            $this->shouldBe->value,
            null === $this->gmail ? '-' : $this->gmail->value,
            null === $this->rules ? '-' : $this->rules->value,
            null !== $this->rulesSignal ? '(' . $this->rulesSignal . ')' : '',
            null === $this->model ? '-' : $this->model->value,
            $this->source,
            '' === $this->bulkHeaders ? 'none' : $this->bulkHeaders,
            $this->fromName,
            $this->fromAddress,
            $this->subject,
        );
    }
}
