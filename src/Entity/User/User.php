<?php

namespace App\Entity\User;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Domain\Enum\Mail\SearchSortOrder;
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

    /**
     * Put a second factor back exactly as another installation had it.
     *
     * Not expressible as startTotpEnrolment() + confirmTotp(): that pair is the
     * enrolment *ceremony* — stage a secret, then record that a human proved it
     * works, now. A restore is not a ceremony and there is no human at it. The
     * confirmation already happened, on another host, on a date the backup
     * carries, and stamping it with today's would quietly rewrite when this
     * account gained its second factor.
     *
     * The secret arrives decrypted from inside the backup's envelope and is
     * re-encrypted by EncryptedStringType on the way to the column, under
     * whatever APP_ENCRYPTION_KEY is in force here — see
     * \App\Service\Backup\ConfigBackupUsers, which is why it can travel at all.
     *
     * Recovery codes are set through the ordinary property: they are SHA-256
     * digests already and there is no state to keep in step with them.
     */
    public function restoreTwoFactor(?string $secret, ?DateTimeImmutable $confirmedAt): static
    {
        $this->totpSecret = $secret;
        // Never confirmed without a secret: the pair is what
        // isTotpAuthenticationEnabled() reads, and a confirmation date with
        // nothing behind it would claim 2FA on an account that has none.
        $this->totpConfirmedAt = null === $secret ? null : $confirmedAt;

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
     * The sidebar sections and label trees the user has collapsed, as a list of
     * keys — `section:labels`, `section:accounts`, `label:<full name>`.
     *
     * COLLAPSED is stored rather than expanded, for the reason
     * SETTING_ADMIN_COLLAPSED_PANELS is: a label created tomorrow, or a section
     * added in a later version, then shows up open without anyone having to
     * backfill every user's bag. The absent state is the useful default.
     *
     * One key space for both, because they are one question asked at different
     * depths — "is this part of the nav showing?" — and a second mechanism
     * beside the first is how two disclosures next to each other end up
     * behaving differently. It also replaces the localStorage the label trees
     * used to be remembered in: that was per browser, and it was restored after
     * the first paint, so every navigation showed the tree open for a frame
     * before JavaScript shut it again.
     *
     * Distinct from SETTING_SIDEBAR_ACCOUNT, which answers a different
     * question — *which* account is expanded, not whether the accounts section
     * is on screen at all. Collapsing the section hides the expanded account's
     * folder list with it and expanding it brings the same account back.
     */
    public const string SETTING_SIDEBAR_COLLAPSED = 'sidebar.collapsed_sections';

    /** The collapse keys for the two whole-section disclosures. */
    public const string SIDEBAR_SECTION_LABELS = 'section:labels';
    public const string SIDEBAR_SECTION_ACCOUNTS = 'section:accounts';

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
     * How wide the live preview beside the appearance settings is.
     *
     * The settings bag for the same reason the calendar's width is: rendered
     * into the first paint, and the same at every desk. It needs no migration —
     * the bag is a JSON column, and a user who has never dragged it reads the
     * default below.
     *
     * Its own key rather than sharing the calendar's: they are two boundaries
     * on two pages with different useful ranges, and one number would mean
     * widening the preview to read a colour narrowed the calendar next time.
     */
    public const string SETTING_APPEARANCE_PREVIEW_WIDTH = 'appearance.preview_width';

    /**
     * Insight extractors this user has switched OFF, as a list of extractor
     * keys. Disabled rather than enabled, so an extractor shipped next
     * release starts working without every user finding a new toggle — the
     * same rule SETTING_ADMIN_COLLAPSED_PANELS records. The catalogue of
     * legal keys is the extractor registry, not this class; an unknown key in
     * the list is inert rather than an error, which is what lets an extractor
     * be removed from a build without a data migration.
     */
    public const string SETTING_INSIGHTS_DISABLED = 'insights.disabled_extractors';

    /**
     * Whether the insight strip above the mail list is switched off.
     *
     * Absent means ON, the rule every switch in this bag follows and the same
     * one SETTING_INSIGHTS_DISABLED gives its reasons for: a feature that
     * shipped switched off is a feature nobody finds. Distinct from that key
     * on purpose — that one silences a SOURCE everywhere, including the strip
     * inside a conversation and the radar panel, while this one is only about
     * whether the mail list wears a strip above it. Somebody who wants parcels
     * found but does not want a band over their inbox is asking for this key,
     * and squeezing both meanings into one switch would make that unsayable.
     */
    public const string SETTING_INSIGHT_PANE_DISABLED = 'insights.pane_disabled';

    /**
     * When the strip was last waved away, as an ATOM string.
     *
     * A timestamp rather than a boolean, because "dismiss" here means "not
     * now" and not "never": the strip returns the moment an insight it has
     * never shown is extracted, which is decided by comparing that insight's
     * createdAt against this instant. A boolean could only have meant "hidden
     * forever", which is what the setting above is for — and a dismissal that
     * silently retired the feature would be a mis-click nobody could undo
     * without finding a settings page they have no reason to suspect.
     */
    public const string SETTING_INSIGHT_PANE_DISMISSED_AT = 'insights.pane_dismissed_at';

    /**
     * Whether a forward opens with its quoted original folded behind the
     * "show quoted text" pill. TRUE (fold) when unset — the pill is the
     * default experience — and stored only when the user switches it off,
     * so the absent key keeps meaning "default" the way every setting in
     * this bag does. Per USER, not per account: how a person likes their
     * compose window is about them, not about which mailbox is sending.
     */
    public const string SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED = 'compose.forward_quote_collapsed';

    /**
     * What pressing Send does to the screen.
     *
     * 'optimistic' (the default, and the absent value): the composer closes at
     * once, the mail appears where it will live, and a toast counts the cancel
     * window down with an Undo beside it.
     *
     * 'hold': the composer stays open and its own Send pill becomes the cancel,
     * closing when the window elapses.
     *
     * A setting rather than a decision, because the two are a real trade and
     * people split on it. Holding keeps the cancel under the pointer that just
     * clicked — nothing moves, and the way back is exactly where the way
     * forward was. Optimistic gives the screen back immediately and asks the
     * user to look somewhere else for the undo. The wait is what people notice:
     * eight seconds of a window that will not close is a long time when you
     * have finished writing and want to get on.
     */
    public const string SETTING_COMPOSE_SEND_FEEDBACK = 'compose.send_feedback';

    /** The composer closes at once and the toast carries the undo. */
    public const string SEND_FEEDBACK_OPTIMISTIC = 'optimistic';

    /** The composer stays open and is its own cancel. */
    public const string SEND_FEEDBACK_HOLD = 'hold';

    /**
     * The default is 19rem — what the column was fixed at before it could move.
     *
     * The maximum is the CALENDAR's maximum, deliberately the same number: both
     * are "a pane beside the thing the page is for", and 560 was a limit
     * inherited from the days when this was a sidebar rather than a peer card.
     * A preview is worth widening — it is the only place on the page where the
     * list density, the preview lines and the snippet clamp can be judged
     * against each other at a realistic width, and at 560 the list still had to
     * use its stacked layout to fit.
     *
     * What keeps this honest is that it is a ceiling and not a promise. The
     * boundary is clamped against what the two cards actually have between
     * them, minus the controls card's own floor (`main-min` in
     * _appearance.html.twig), so 900 is reachable on a window with 900 to spare
     * and the preview simply stops earlier on one without. Note that raising a
     * maximum can only widen the range a stored width is clamped into: nobody's
     * remembered value changes, and the pane they left is the pane they return
     * to.
     */
    public const int APPEARANCE_PREVIEW_MIN_WIDTH = 240;
    public const int APPEARANCE_PREVIEW_MAX_WIDTH = 900;
    public const int APPEARANCE_PREVIEW_DEFAULT_WIDTH = 304;

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

    /** Virtual, out of the settings bag — see SETTING_APPEARANCE_PREVIEW_WIDTH. */
    public int $appearancePreviewWidth {
        get {
            $width = $this->getSetting(
                self::SETTING_APPEARANCE_PREVIEW_WIDTH,
                self::APPEARANCE_PREVIEW_DEFAULT_WIDTH,
            );

            if (false === is_int($width)) {
                return self::APPEARANCE_PREVIEW_DEFAULT_WIDTH;
            }

            return max(self::APPEARANCE_PREVIEW_MIN_WIDTH, min(self::APPEARANCE_PREVIEW_MAX_WIDTH, $width));
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
     * Which order search results come back in, as a SearchSortOrder value.
     *
     * In the settings bag for the same reason the sidebar's expanded account
     * is: it is one string of UI state, nothing queries by it, and the search
     * page re-renders on every visit — so it belongs in the bag that already
     * carries per-user view state to the same user's other devices, rather
     * than in a session that forgets when the browser does.
     *
     * Absent means Recent, which is the default the enum documents.
     */
    public const string SETTING_SEARCH_SORT = 'search.sort';

    /** Virtual, out of the settings bag — see SETTING_SEARCH_SORT. */
    public SearchSortOrder $searchSortOrder {
        get => SearchSortOrder::fromSetting($this->getSetting(self::SETTING_SEARCH_SORT));
        set (SearchSortOrder $order) {
            $this->setSetting(self::SETTING_SEARCH_SORT, $order->value);
        }
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
     * Virtual for the same reason as $collapsedAdminPanels — see
     * SETTING_SIDEBAR_COLLAPSED.
     *
     * @var list<string>
     */
    public array $collapsedSidebarSections {
        get {
            $keys = $this->getSetting(self::SETTING_SIDEBAR_COLLAPSED, []);

            if (false === is_array($keys)) {
                return [];
            }

            return array_values(array_filter($keys, 'is_string'));
        }
    }

    public function isSidebarSectionCollapsed(string $key): bool
    {
        return in_array($key, $this->collapsedSidebarSections, true);
    }

    /** The sidebar's half of setAdminPanelCollapsed(), and the same shape. */
    public function setSidebarSectionCollapsed(string $key, bool $collapsed): static
    {
        $keys = $this->collapsedSidebarSections;

        if (true === $collapsed) {
            if (false === in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        } else {
            $keys = array_values(array_filter(
                $keys,
                static fn (string $existing): bool => $existing !== $key,
            ));
        }

        return $this->setSetting(self::SETTING_SIDEBAR_COLLAPSED, $keys);
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
