<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Enum\Calendar\ShareDetail;
use App\Domain\Enum\Calendar\ShareWindow;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarShareLinkRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A URL the owner sends to somebody, which shows part of their calendar without
 * an account here.
 *
 * **The token is the credential, and it is not stored.** Only its SHA-256 is —
 * the same reasoning DevicePairingService gives for keying its cache by a
 * digest rather than by the code, and the reason its test is called
 * testTheCacheKeyIsADigestNotTheCodeItself. A pairing code lives two minutes; a
 * share link lives until it is revoked, which makes the stored form strictly
 * more dangerous rather than less: anything that can read one row of this table
 * — a database dump on a laptop, a backup on somebody else's storage, a read
 * gained through an unrelated injection — would otherwise walk away with a
 * working URL into the owner's diary, and would have it for as long as the link
 * exists rather than for as long as the incident lasts.
 *
 * The cost is stated plainly because it is the one thing people will try to
 * "fix": the URL can be shown exactly once, when it is minted. There is no
 * screen that can show it again, because nothing here can reconstruct it. A
 * link whose URL was lost is regenerated, which mints a new token and makes the
 * old one 404 — which is the correct thing to do with a URL that went missing
 * anyway.
 *
 * **What it reveals is per link and is a set, not a level.** Busy/free is the
 * floor and needs no flag; $details is what has been added on top. A level —
 * "none / some / all" — was rejected because the interesting link is not on a
 * line: sharing titles but not locations is what a person wants when their
 * calendar says where they live, and sharing participants but not titles is
 * what a team wants when the subject is confidential and the attendance is not.
 *
 * **Which calendars, explicitly.** Not "all of them", and not "the visible
 * ones": visibility is a sidebar preference that the owner changes for their
 * own reading, and a link whose contents silently followed it would start
 * revealing a calendar the moment somebody ticked it back on. A calendar
 * removed from the account is removed from the link by the cascade on the join
 * table, which is the one automatic change that is safe — it only ever narrows.
 *
 * **No expiry column, deliberately.** Revocation is the control, and an expiry
 * that nothing sweeps is a promise the application does not keep — the link
 * would go on working until somebody read the date in PHP. The window is what
 * bounds the *data*; $revokedAt is what bounds the *link*, and one honest
 * mechanism each is worth more than a third that looks like both.
 */
#[ORM\Entity(repositoryClass: CalendarShareLinkRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'calendar_share_link')]
// The owner's own list, which is the only authenticated read of this table.
#[ORM\Index(name: 'idx_calendar_share_link_usr', columns: ['usr_id'])]
// The public lookup, and the reason it is UNIQUE rather than plain. Every
// unauthenticated request resolves to exactly one link by this column alone, so
// two rows sharing a digest would make a URL ambiguous and the endpoint would
// answer with whichever diary the planner reached first. It is also the
// collision guard on the mint: 32 bytes of CSPRNG will not collide, and the
// constraint is what makes that a failed insert rather than a silent takeover
// of somebody else's link if it ever did.
#[ORM\UniqueConstraint(name: 'uniq_calendar_share_link_token_digest', columns: ['token_digest'])]
class CalendarShareLink
{
    use TimestampableTrait;

    /** Hex SHA-256. Named so the column length and the hash cannot drift apart. */
    public const int DIGEST_LENGTH = 64;

    /**
     * The widest rolling window a link may cover.
     *
     * A year, because that is the longest range anybody plausibly shares and
     * because the public reader walks days: the bound is what stops a hand-
     * edited form turning one GET into a decade of date arithmetic and an
     * occurrence query over ten years of the owner's calendar.
     */
    public const int MAX_ROLLING_DAYS = 366;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Non-nullable and without a default, unlike the older entities in this
     * directory whose equivalents are `?X = null`. A row that exists has one,
     * the column says so, and reading it before it has been assigned throws —
     * which is the right answer to a genuine mistake, and the same argument
     * TimestampableTrait makes about its two timestamps. The nullable spelling
     * costs a phpstan-doctrine "type mapping mismatch" per property, and that
     * baseline is a debt ledger rather than a licence.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /** What the owner calls it in their own list. Never rendered publicly. */
    #[ORM\Column(length: 120)]
    public string $name = '';

    /**
     * SHA-256 of the token, hex. See the class docblock: the token itself is
     * never here, and the only moment it exists is the response that minted it.
     */
    #[ORM\Column(length: self::DIGEST_LENGTH)]
    public string $tokenDigest = '';

    /**
     * What this link adds on top of busy/free, as ShareDetail values.
     *
     * An empty list is the ordinary, safest state and means exactly what it
     * says: times and nothing else.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $details = [];

    /**
     * Named `windowMode` rather than `window` because `WINDOW` is a reserved
     * word in Postgres — the column would have to be quoted in every statement
     * that named it, and the first hand-written query to forget the quotes
     * would fail at run time rather than at review.
     */
    #[ORM\Column(length: 16, enumType: ShareWindow::class, options: ['default' => 'rolling'])]
    public ShareWindow $windowMode = ShareWindow::Rolling;

    /** Days forward from today, for ShareWindow::Rolling. Capped at MAX_ROLLING_DAYS. */
    #[ORM\Column(options: ['default' => 14])]
    public int $rollingDays = 14;

    /** First day shown, for ShareWindow::Fixed. A date, in the owner's zone. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $startsOn = null;

    /** Last day shown, inclusive, for ShareWindow::Fixed. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $endsOn = null;

    /**
     * When the owner switched it off. Kept rather than deleted so the list can
     * say "this one is dead" instead of quietly losing the row somebody is
     * still being asked about — and so a link that was revoked in a hurry can
     * be explained afterwards.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $revokedAt = null;

    /**
     * When somebody last opened it, which is the only thing the owner can
     * learn about the recipient of a link they cannot un-send.
     *
     * Not a counter and not an address. A count invites the reading that a
     * quiet link is a safe one, and an IP is a piece of somebody else's data
     * being retained to answer a question nobody asked.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastViewedAt = null;

    /**
     * The calendars this link covers.
     *
     * ManyToMany rather than a list of ids in jsonb, for the cascade: a deleted
     * calendar has to leave every link that named it, and a jsonb list would go
     * on naming a row that is not there — which the reader would either have to
     * defend against on every read or silently widen past.
     *
     * @var Collection<int, Calendar>
     */
    #[ORM\ManyToMany(targetEntity: Calendar::class)]
    #[ORM\JoinTable(name: 'calendar_share_link_calendar')]
    #[ORM\JoinColumn(name: 'share_link_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'calendar_id', onDelete: 'CASCADE')]
    public private(set) Collection $calendars;

    public function __construct()
    {
        $this->calendars = new ArrayCollection();
    }

    /**
     * Whether this link still answers.
     *
     * Stays a method: it is an interpretation of a nullable timestamp rather
     * than a plain read, and every caller wants the verdict rather than the
     * date.
     */
    public function isLive(): bool
    {
        return null === $this->revokedAt;
    }

    /**
     * Whether this link reveals one particular thing.
     *
     * A method because it takes an argument and asks the stored set about it —
     * the rule §4.4 states. Reads through ShareDetail rather than through the
     * raw strings so a value stored by an install that knew a detail this one
     * does not is not accidentally matched by a string comparison.
     */
    public function reveals(ShareDetail $detail): bool
    {
        return true === in_array($detail->value, $this->details, true);
    }

    /**
     * The details this link reveals, as cases, in declaration order.
     *
     * Stays a method rather than becoming the column's type: the column is
     * jsonb and Doctrine has no list-of-enum mapping, so something has to do
     * the conversion, and doing it here means no caller ever meets the strings.
     *
     * @return list<ShareDetail>
     */
    public function revealed(): array
    {
        return ShareDetail::fromList($this->details);
    }

    /**
     * Replace the revealed set, storing the cases' values in declaration order.
     *
     * A named method rather than a public array, for the reason mutators exist
     * here at all: the column has an invariant — every element is a valid
     * ShareDetail value, and the order is the enum's — that an assignment from
     * a form would not keep.
     *
     * @param list<ShareDetail> $details
     */
    public function reveal(array $details): void
    {
        $values = [];

        foreach (ShareDetail::cases() as $case) {
            if (true === in_array($case, $details, true)) {
                $values[] = $case->value;
            }
        }

        $this->details = $values;
    }

    /**
     * Put this link on exactly these calendars.
     *
     * Whole-set rather than add/remove, because that is what the form posts and
     * because the interesting operation is "untick one" — expressed as two
     * calls it becomes a diff every caller would have to write again.
     *
     * @param list<Calendar> $calendars
     */
    public function cover(array $calendars): void
    {
        $this->calendars->clear();

        foreach ($calendars as $calendar) {
            if (false === $this->calendars->contains($calendar)) {
                $this->calendars->add($calendar);
            }
        }
    }
}
