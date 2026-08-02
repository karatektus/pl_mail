<?php

namespace App\Entity\User;

use App\Domain\Helper\TimezoneHelper;
use App\Domain\Model\UserEntityModel;
use App\Entity\Embeddable\Appearance;
use App\Entity\Mail\Account;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\User\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
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
class User extends UserEntityModel implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    /* Core roles */
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    public const string ROLE_USER = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Embedded(class: Appearance::class, columnPrefix: 'appearance_')]
    public private(set) Appearance $appearance;
    /**
     * The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatar = null;

    /**
     * The shared TOTP secret, base32, encrypted at rest by the same libsodium
     * key as mailbox passwords — anyone who can read it can mint valid codes
     * forever, so it belongs in the same bracket as a credential, not a
     * setting.
     */
    #[ORM\Column(name: 'totp_secret', type: EncryptedStringType::NAME, nullable: true)]
    private ?string $totpSecret = null;

    /**
     * When the user proved the secret works, by typing a code from their app.
     *
     * Separate from the secret existing on purpose: enrolment writes the secret
     * first so the QR can be scanned, and a secret that has never been
     * confirmed must not lock anyone out. 2FA is *on* only once this is set,
     * which is exactly what isTotpAuthenticationEnabled() answers.
     */
    #[ORM\Column(name: 'totp_confirmed_at', nullable: true)]
    private ?\DateTimeImmutable $totpConfirmedAt = null;

    /**
     * Unused recovery codes, as SHA-256 digests.
     *
     * Hashed for the same reason the secret is encrypted, and by a plain digest
     * rather than a password hasher for the same reason as ApiToken: each code
     * is 64 bits of CSPRNG output, so there is nothing to brute-force and no
     * key stretching to buy. Codes are removed from this list as they are
     * spent, so its length is the "N remaining" the settings page shows.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'backup_codes', type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $backupCodes = [];

    /**
     * Preferred interface locale. Null means "follow the server default".
     */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $locale = null;

    /**
     * Preferred display timezone, as an IANA identifier. Null means "never
     * chose one" — see UserTimezoneResolver, which turns that into the
     * install's configured default. Storage stays UTC throughout; this only
     * decides what the user is shown.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $timezone = null;

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
    private ?string $nameFirst = null;

    #[ORM\Column(length: 255)]
    private ?string $nameLast = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLogin = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /**
     * @var Collection<int, Account>
     */
    #[ORM\OneToMany(targetEntity: Account::class, mappedBy: 'usr', orphanRemoval: true)]
    private Collection $accounts;

    public function __construct()
    {
        $this->setCreatedAt(new \DateTimeImmutable());
        $this->setUpdatedAt(new \DateTimeImmutable());
        $this->setDeletedAt(null);
        $this->accounts = new ArrayCollection();
        $this->appearance = new Appearance();
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
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

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
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
     * The pending or active secret. Only the enrolment service and the QR
     * renderer have any business reading this.
     */
    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
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

    public function getTotpConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->totpConfirmedAt;
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
     * @return list<string>
     */
    public function getBackupCodeHashes(): array
    {
        return $this->backupCodes;
    }

    public function countBackupCodes(): int
    {
        return count($this->backupCodes);
    }

    /**
     * @param list<string> $hashes
     */
    public function setBackupCodeHashes(array $hashes): static
    {
        $this->backupCodes = array_values($hashes);

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

        $this->backupCodes = array_values(array_filter(
            $this->backupCodes,
            static fn (string $hash): bool => false === hash_equals($hash, $candidate),
        ));
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): self
    {
        // Nullable: removing a picture is a normal thing to do, and the column
        // has always allowed it.
        $this->avatar = $avatar;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    /**
     * Anything the system does not recognise becomes null — the same clamping
     * Appearance does with a bad hex colour, and for the same reason: a
     * preference is not worth an exception, and null is a state the reader
     * already handles. Storing an unknown identifier would make every later
     * `new DateTimeZone()` throw somewhere far away from here.
     */
    public function setTimezone(?string $timezone): self
    {
        $this->timezone = true === TimezoneHelper::isKnown($timezone) ? $timezone : null;

        return $this;
    }

    /** Admin panels the user has collapsed, as a list of panel keys. */
    public const string SETTING_ADMIN_COLLAPSED_PANELS = 'admin.collapsed_panels';

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

    /** Matches the clamp in ui--split; the server is what enforces it. */
    public const int CALENDAR_PANE_MIN_WIDTH = 320;
    public const int CALENDAR_PANE_MAX_WIDTH = 900;
    public const int CALENDAR_PANE_DEFAULT_WIDTH = 380;

    public function getCalendarPaneWidth(): int
    {
        $width = $this->getSetting(self::SETTING_CALENDAR_PANE_WIDTH, self::CALENDAR_PANE_DEFAULT_WIDTH);

        if (false === is_int($width)) {
            return self::CALENDAR_PANE_DEFAULT_WIDTH;
        }

        return max(self::CALENDAR_PANE_MIN_WIDTH, min(self::CALENDAR_PANE_MAX_WIDTH, $width));
    }

    public function isCalendarPaneOpen(): bool
    {
        return true === $this->getSetting(self::SETTING_CALENDAR_PANE_OPEN, false);
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
     * @return list<string>
     */
    public function getCollapsedAdminPanels(): array
    {
        $panels = $this->getSetting(self::SETTING_ADMIN_COLLAPSED_PANELS, []);

        if (false === is_array($panels)) {
            return [];
        }

        return array_values(array_filter($panels, 'is_string'));
    }

    /**
     * Collapsed is stored rather than expanded so a panel added later shows up
     * open by default, without having to backfill every user's settings.
     */
    public function setAdminPanelCollapsed(string $panel, bool $collapsed): static
    {
        $panels = $this->getCollapsedAdminPanels();

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

    public function getNameFirst(): ?string
    {
        return $this->nameFirst;
    }

    public function setNameFirst(string $nameFirst): self
    {
        $this->nameFirst = $nameFirst;

        return $this;
    }

    public function getNameLast(): ?string
    {
        return $this->nameLast;
    }

    public function setNameLast(string $nameLast): self
    {
        $this->nameLast = $nameLast;

        return $this;
    }

    public function getLastLogin(): ?\DateTimeInterface
    {
        return $this->lastLogin;
    }

    public function setLastLogin(?\DateTimeInterface $lastLogin): self
    {
        $this->lastLogin = $lastLogin;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /**
     * @return Collection<int, Account>
     */
    public function getAccounts(): Collection
    {
        return $this->accounts;
    }

    public function addAccount(Account $account): static
    {
        if (!$this->accounts->contains($account)) {
            $this->accounts->add($account);
            $account->setUsr($this);
        }

        return $this;
    }

    public function removeAccount(Account $account): static
    {
        if ($this->accounts->removeElement($account)) {
            // set the owning side to null (unless already changed)
            if ($account->getUsr() === $this) {
                $account->setUsr(null);
            }
        }

        return $this;
    }
}
