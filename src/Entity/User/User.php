<?php

namespace App\Entity\User;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Domain\Helper\TimezoneHelper;
use App\Domain\Model\UserEntityModel;
use App\Entity\Embeddable\Appearance;
use App\Entity\Mail\Account;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\User\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use App\Domain\Trait\TimestampableTrait;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints\DateTime;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
class User extends UserEntityModel implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    use TimestampableTrait;

    /* Core roles */
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    public const string ROLE_USER = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    public ?string $email = null;

    /**
     * The roles granted explicitly, which is not the same list the security
     * layer sees — read through getRoles(), which adds the implied ROLE_USER.
     */
    #[ORM\Column]
    public array $roles = [];

    #[ORM\Embedded(class: Appearance::class, columnPrefix: 'appearance_')]
    public private(set) Appearance $appearance;
    /**
     * The hashed password
     */
    #[ORM\Column]
    public ?string $password = null;

    /**
     * Nullable: removing a picture is a normal thing to do, and the column has
     * always allowed it.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $avatar = null;

    /**
     * The shared TOTP secret, base32, encrypted at rest by the same libsodium
     * key as mailbox passwords — anyone who can read it can mint valid codes
     * forever, so it belongs in the same bracket as a credential, not a
     * setting. Only the enrolment service and the QR renderer have any business
     * reading it, and only startTotpEnrolment()/disableTotp() may write it —
     * hence private(set), so a secret cannot be swapped in without the
     * confirmation state being reset alongside it.
     */
    #[ORM\Column(name: 'totp_secret', type: EncryptedStringType::NAME, nullable: true)]
    public private(set) ?string $totpSecret = null;

    /**
     * When the user proved the secret works, by typing a code from their app.
     *
     * Separate from the secret existing on purpose: enrolment writes the secret
     * first so the QR can be scanned, and a secret that has never been
     * confirmed must not lock anyone out. 2FA is *on* only once this is set,
     * which is exactly what isTotpAuthenticationEnabled() answers.
     */
    #[ORM\Column(name: 'totp_confirmed_at', nullable: true)]
    public private(set) ?\DateTimeImmutable $totpConfirmedAt = null;

    /**
     * Unused recovery codes, as SHA-256 digests.
     *
     * Hashed for the same reason the secret is encrypted, and by a plain digest
     * rather than a password hasher for the same reason as ApiToken: each code
     * is 64 bits of CSPRNG output, so there is nothing to brute-force and no
     * key stretching to buy. Codes are removed from this list as they are
     * spent, so its length is the "N remaining" the settings page shows.
     *
     * Reindexed on every write, so spending a code cannot turn the column into
     * a JSON object: array_filter() leaves holes, and a sparse PHP array
     * encodes as {"1":"…"} rather than a list, which comes back the wrong
     * shape.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'backup_codes', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $backupCodes = [] {
        set (array $codes) => array_values($codes);
    }

    /** How many recovery codes are left to spend. */
    public int $backupCodeCount {
        get => count($this->backupCodes);
    }

    /**
     * Preferred interface locale. Null means "follow the server default".
     */
    #[ORM\Column(length: 16, nullable: true)]
    public ?string $locale = null;

    /**
     * Preferred display timezone, as an IANA identifier. Null means "never
     * chose one" — see UserTimezoneResolver, which turns that into the
     * install's configured default. Storage stays UTC throughout; this only
     * decides what the user is shown.
     *
     * Anything the system does not recognise becomes null — the same clamping
     * Appearance does with a bad hex colour, and for the same reason: a
     * preference is not worth an exception, and null is a state the reader
     * already handles. Storing an unknown identifier would make every later
     * `new DateTimeZone()` throw somewhere far away from here.
     *
     * Doctrine hydrates through `RawValuePropertyAccessor`, which skips hooks,
     * so this runs on application writes only and never re-checks a stored row.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $timezone = null {
        set (?string $value) {
            $this->timezone = true === TimezoneHelper::isKnown($value) ? $value : null;
        }
    }

    /**
     * Free-form per-user preferences, mirroring Account::$settings. For UI
     * state that is worth remembering but does not deserve a column of its
     * own — readers assume their defaults at the call site.
     *
     * Appearance stays in its own embeddable: it is a fixed, validated set the
     * theme system reads on every render. This is the bag for the long tail.
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $settings = [];

    #[ORM\Column(length: 255)]
    public ?string $nameFirst = null;

    #[ORM\Column(length: 255)]
    public ?string $nameLast = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    public ?\DateTimeInterface $lastLogin = null;



    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    /**
     * @var Collection<int, Account>
     */
    #[ORM\OneToMany(targetEntity: Account::class, mappedBy: 'usr', orphanRemoval: true)]
    public private(set) Collection $accounts;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->accounts = new ArrayCollection();
        $this->appearance = new Appearance();
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials()
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    /* ── Two-factor authentication ──────────────────────────────────────── */

    /**
     * RFC 6238 defaults, and they are not ours to tune.
     *
     * Google Authenticator ignores the `algorithm` and `digits` parameters in
     * the otpauth:// URI and assumes SHA-1 and 6 digits regardless. Configuring
     * anything else here produces an enrolment that scans cleanly and then
     * rejects every code, with nothing on either side saying why.
     */
    private const string TOTP_ALGORITHM = TotpConfiguration::ALGORITHM_SHA1;
    private const int TOTP_PERIOD = 30;
    private const int TOTP_DIGITS = 6;

    /** How many recovery codes an enrolment or a regeneration hands out. */
    public const int BACKUP_CODE_COUNT = 8;

    /**
     * 2FA is on once the secret has been confirmed — see $totpConfirmedAt for
     * why a stored secret is not by itself enough.
     */
    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecret && null !== $this->totpConfirmedAt;
    }

    public function getTotpAuthenticationUsername(): ?string
    {
        return $this->getUserIdentifier();
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->totpSecret) {
            return null;
        }

        return new TotpConfiguration(
            $this->totpSecret,
            self::TOTP_ALGORITHM,
            self::TOTP_PERIOD,
            self::TOTP_DIGITS,
        );
    }

    /**
     * Stage a secret for enrolment. Unconfirmed until confirmTotp() — so
     * starting an enrolment and walking away cannot lock the account.
     */
    public function startTotpEnrolment(string $secret): static
    {
        $this->totpSecret = $secret;
        $this->totpConfirmedAt = null;

        return $this;
    }

    public function confirmTotp(): static
    {
        $this->totpConfirmedAt ??= new \DateTimeImmutable();

        return $this;
    }

    /**
     * Turn 2FA off and leave nothing behind that could turn it back on.
     *
     * Recovery codes go with the secret: they are alternative proofs of the
     * same factor, and a set left lying around would still open the account
     * after the user believes they have removed the second factor. Trusted
     * devices are withdrawn by the caller, which has the repository.
     */
    public function disableTotp(): static
    {
        $this->totpSecret = null;
        $this->totpConfirmedAt = null;
        $this->backupCodes = [];

        return $this;
    }

    public static function hashBackupCode(string $code): string
    {
        // Normalised so the grouping dashes the user is shown, and the case
        // their keyboard happens to be in, do not decide whether a valid code
        // is accepted.
        return hash('sha256', strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? ''));
    }

    public function isBackupCode(string $code): bool
    {
        $candidate = self::hashBackupCode($code);

        foreach ($this->backupCodes as $hash) {
            if (true === hash_equals($hash, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $candidate = self::hashBackupCode($code);

        // The set hook reindexes what is left, so filtering cannot leave the
        // column holding a JSON object.
        $this->backupCodes = array_filter(
            $this->backupCodes,
            static fn (string $hash): bool => false === hash_equals($hash, $candidate),
        );
    }

    /** Admin panels the user has collapsed, as a list of panel keys. */
    public const string SETTING_ADMIN_COLLAPSED_PANELS = 'admin.collapsed_panels';

    /**
     * Twelve-hour or twenty-four-hour clock, as a ClockFormat value.
     *
     * Absent means "follow the interface language", which is the state everyone
     * is in until they open Settings — see ClockFormat::forLocale(), and
     * ClockFormatResolver, which is the only thing that should read this key.
     *
     * In the settings bag rather than beside $locale and $timezone as a column
     * of its own, because unlike those two nothing ever queries by it and
     * nothing outside the renderer cares: it is one of three strings, read once
     * per request, and changing it changes only digits.
     */
    public const string SETTING_CLOCK = 'display.clock';

    /**
     * The account whose folder list is expanded in the sidebar, or null.
     *
     * Server-side for the same reason the calendar pane's width is: the
     * sidebar re-renders on every visit, and a list restored by JavaScript
     * afterwards is a list the user watches blink. Rendered into the first
     * paint, it never moves.
     */
    public const string SETTING_SIDEBAR_ACCOUNT = 'sidebar.expanded_account';

    /**
     * When this admin last had the log browser open, as an ISO 8601 string.
     *
     * In the settings bag rather than a column: it is a read marker for one
     * screen, of no interest to anything that queries users, and this is the
     * bag that already carries per-user UI state to their other devices.
     */
    public const string SETTING_LOGS_SEEN_AT = 'admin.logs_seen_at';

    /**
     * Whether the calendar pane is docked open beside the mail panes, and how
     * wide it is in pixels.
     *
     * Server-side rather than in localStorage so the width is rendered into
     * the first paint — a pane that appears at 380px and jumps to the user's
     * 520px once JavaScript runs is worse than no memory at all — and so it
     * follows the user to their other devices, which is the whole reason this
     * bag exists.
     */
    public const string SETTING_CALENDAR_PANE_OPEN = 'calendar.pane_open';
    public const string SETTING_CALENDAR_PANE_WIDTH = 'calendar.pane_width';

    /**
     * Which of the three positions the topbar's calendar switch is in — see
     * CalendarPaneMode, which is what SETTING_CALENDAR_PANE_OPEN grew into when
     * "the calendar, full width, without leaving the mail" turned out to be the
     * state people wanted and a boolean could not hold.
     *
     * The old key is still read as the fallback, so an upgrade does not reset
     * anybody's pane: `true` there means Split, which is what it always meant.
     * It is no longer written.
     */
    public const string SETTING_CALENDAR_PANE_MODE = 'calendar.pane_mode';

    /** Matches the clamp in ui--split; the server is what enforces it. */
    public const int CALENDAR_PANE_MIN_WIDTH = 320;
    public const int CALENDAR_PANE_MAX_WIDTH = 900;
    public const int CALENDAR_PANE_DEFAULT_WIDTH = 380;

    /**
     * Virtual, so there is no column behind it — the value lives in the
     * settings bag, and Doctrine refuses to map a property whose hooks do not
     * touch a backing store.
     */
    public int $calendarPaneWidth {
        get {
            $width = $this->getSetting(self::SETTING_CALENDAR_PANE_WIDTH, self::CALENDAR_PANE_DEFAULT_WIDTH);

            if (false === is_int($width)) {
                return self::CALENDAR_PANE_DEFAULT_WIDTH;
            }

            return max(self::CALENDAR_PANE_MIN_WIDTH, min(self::CALENDAR_PANE_MAX_WIDTH, $width));
        }
    }

    /**
     * Virtual, out of the settings bag — see SETTING_CALENDAR_PANE_MODE.
     *
     * Falls back to the boolean this replaced rather than to Mail, so upgrading
     * does not shut the pane on everybody who had it open.
     */
    public CalendarPaneMode $calendarPaneMode {
        get => CalendarPaneMode::fromSetting(
            $this->getSetting(self::SETTING_CALENDAR_PANE_MODE),
            true === $this->getSetting(self::SETTING_CALENDAR_PANE_OPEN, false)
                ? CalendarPaneMode::Split
                : CalendarPaneMode::Mail,
        );
        set (CalendarPaneMode $mode) {
            $this->setSetting(self::SETTING_CALENDAR_PANE_MODE, $mode->value);
        }
    }

    /**
     * Stays a method rather than becoming a property: there is no boolean
     * column here to read. It interprets the mode, which is the thing actually
     * stored, and answers the one question most callers have — is there a
     * calendar on this page at all?
     */
    public function isCalendarPaneOpen(): bool
    {
        return $this->calendarPaneMode->showsCalendar();
    }

    /**
     * When setup was finished or dismissed, ISO-8601. Absent means pending,
     * which is the right default for a user who has never seen the wizard —
     * including everyone who existed before it did, so there is nothing to
     * backfill.
     */
    public const string SETTING_ONBOARDING_COMPLETED_AT = 'onboarding.completed_at';

    /**
     * The step to resume at. Written before anything that navigates away, so a
     * trip through Google's consent screen comes back where it left off.
     */
    public const string SETTING_ONBOARDING_STEP = 'onboarding.step';

    /** Steps the user chose to skip, as a list of OnboardingStep values. */
    public const string SETTING_ONBOARDING_SKIPPED = 'onboarding.skipped';

    /**
     * Steps the user actually answered. Separate from skipped: the progress
     * rail marks these done, and a skipped step must not look answered.
     */
    public const string SETTING_ONBOARDING_DONE_STEPS = 'onboarding.done_steps';

    public function getSetting(string $key, mixed $default = null): mixed
    {
        if (true === array_key_exists($key, $this->settings)) {
            return $this->settings[$key];
        }

        return $default;
    }

    public function setSetting(string $key, mixed $value): static
    {
        $this->settings[$key] = $value;

        return $this;
    }

    /**
     * Virtual for the same reason as $calendarPaneWidth: it is read out of the
     * settings bag and has no column of its own.
     *
     * @var list<string>
     */
    public array $collapsedAdminPanels {
        get {
            $panels = $this->getSetting(self::SETTING_ADMIN_COLLAPSED_PANELS, []);

            if (false === is_array($panels)) {
                return [];
            }

            return array_values(array_filter($panels, 'is_string'));
        }
    }

    /** Virtual, out of the settings bag — see SETTING_SIDEBAR_ACCOUNT. */
    public ?int $expandedAccountId {
        get {
            $accountId = $this->getSetting(self::SETTING_SIDEBAR_ACCOUNT);

            return is_int($accountId) ? $accountId : null;
        }
        set (?int $accountId) {
            $this->setSetting(self::SETTING_SIDEBAR_ACCOUNT, $accountId);
        }
    }

    /**
     * Virtual for the same reason as $collapsedAdminPanels. Null until the log
     * browser has been opened once, which counts everything as unseen — a
     * fresh admin should be told about the errors already waiting for them.
     */
    public ?DateTimeImmutable $logsSeenAt {
        get {
            $seenAt = $this->getSetting(self::SETTING_LOGS_SEEN_AT);

            if (false === is_string($seenAt)) {
                return null;
            }

            try {
                return new DateTimeImmutable($seenAt);
            } catch (Exception) {
                // A bag written by an older version, or by hand. Unreadable
                // is the same as never read.
                return null;
            }
        }
        set (?DateTimeImmutable $seenAt) {
            $this->setSetting(self::SETTING_LOGS_SEEN_AT, $seenAt?->format(DateTimeInterface::ATOM));
        }
    }

    /**
     * Collapsed is stored rather than expanded so a panel added later shows up
     * open by default, without having to backfill every user's settings.
     */
    public function setAdminPanelCollapsed(string $panel, bool $collapsed): static
    {
        $panels = $this->collapsedAdminPanels;

        if (true === $collapsed) {
            if (false === in_array($panel, $panels, true)) {
                $panels[] = $panel;
            }
        } else {
            $panels = array_values(array_filter(
                $panels,
                static fn (string $existing): bool => $existing !== $panel,
            ));
        }

        return $this->setSetting(self::SETTING_ADMIN_COLLAPSED_PANELS, $panels);
    }

    public function addAccount(Account $account): static
    {
        if (!$this->accounts->contains($account)) {
            $this->accounts->add($account);
            $account->usr = $this;
        }

        return $this;
    }

    public function removeAccount(Account $account): static
    {
        if ($this->accounts->removeElement($account)) {
            // set the owning side to null (unless already changed)
            if ($account->usr === $this) {
                $account->usr = null;
            }
        }

        return $this;
    }
}
