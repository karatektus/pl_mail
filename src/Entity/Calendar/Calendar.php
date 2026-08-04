<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One list of events, owned by a user.
 *
 * User-scoped like Label and MailRule, and for the same reason: a calendar is
 * the user's, not an account's. The optional account is what makes "the
 * calendar the email came from" expressible — every mail account is provisioned
 * a CalendarRole::Account calendar, and that is where anything extracted from
 * its mail lands unless the user says otherwise.
 *
 * The optional integration is for calendars mirrored from elsewhere. It is
 * declared now rather than added later because remote_id and sync_token are
 * meaningless without knowing which remote they belong to, and a partial unique
 * index over the three is what stops a re-sync duplicating every calendar.
 */
#[ORM\Entity(repositoryClass: CalendarRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'calendar')]
#[ORM\Index(name: 'idx_calendar_usr', columns: ['usr_id'])]
#[ORM\Index(name: 'idx_calendar_account', columns: ['account_id'])]
class Calendar
{
    use TimestampableTrait;

    /** Fallback when a user has expressed no preference. */
    public const string DEFAULT_COLOR = '#2563eb';

    /**
     * The colours a calendar may be, and the order they are offered in.
     *
     * Here rather than on CalendarProvisioner, which is where they started:
     * the provisioner walks the list so a second account is not the same blue
     * as the first, and the settings picker offers it so a user's own calendar
     * can be any colour a provisioned one can. Two lists would drift, and the
     * drift is invisible until somebody makes a calendar in a colour the
     * palette no longer contains.
     *
     * @var list<string>
     */
    public const array COLORS = [
        '#2563eb', '#7c3aed', '#db2777', '#ea580c',
        '#16a34a', '#0891b2', '#ca8a04', '#dc2626',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $usr = null;

    /**
     * The mail account this calendar belongs to, for CalendarRole::Account.
     * Null on every other role.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Account $account = null;

    /** The connection this calendar mirrors, for CalendarRole::Remote. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Integration $integration = null;

    #[ORM\Column(length: 120)]
    public string $name = '';

    /** #rrggbb, matching Label's colour handling. */
    #[ORM\Column(length: 7, options: ['default' => self::DEFAULT_COLOR])]
    public string $color = self::DEFAULT_COLOR;

    /**
     * IANA zone this calendar is displayed in. Events carry their own; this is
     * the fallback for ones that do not, and the zone a new event starts in.
     */
    #[ORM\Column(length: 64, options: ['default' => 'UTC'])]
    public string $timeZone = 'UTC';

    #[ORM\Column(length: 20, enumType: CalendarRole::class, options: ['default' => 'custom'])]
    public CalendarRole $role = CalendarRole::Custom;

    /** Unticked in the sidebar hides it from every view without deleting it. */
    #[ORM\Column(options: ['default' => true])]
    public bool $isVisible = true;

    /** The one a new event lands in when nothing else picked. */
    #[ORM\Column(options: ['default' => false])]
    public bool $isDefault = false;

    /** A mirror of somewhere that does not accept writes back. */
    #[ORM\Column(options: ['default' => false])]
    public bool $isReadOnly = false;

    #[ORM\Column(options: ['default' => 0])]
    public int $sortOrder = 0;

    /** Opaque id at the remote, for CalendarRole::Remote. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $remoteId = null;

    /** CalDAV sync-token or provider equivalent; opaque, never parsed. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $syncToken = null;

    /**
     * Free-form per-calendar preferences, mirroring Account::$settings — the
     * long tail that does not deserve a column. Readers assume their defaults
     * at the call site.
     *
     * @var array<string,mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $settings = [];

    /** @var Collection<int, CalendarEvent> */
    #[ORM\OneToMany(targetEntity: CalendarEvent::class, mappedBy: 'calendar')]
    public private(set) Collection $events;

    public function __construct()
    {
        $this->events = new ArrayCollection();
    }
}
