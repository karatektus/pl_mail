<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Domain\DTO\Push\NotifyScope;
use App\Domain\Enum\Calendar\CalendarView;
use App\Domain\Enum\Crypto\KeyringRoot;
use App\Domain\Enum\Mail\ComposeAction;
use App\Domain\Enum\Mail\SearchSortOrder;
use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Enum\Mail\SidebarRow;
use App\Domain\Enum\Mail\SwipeAction;
use App\Domain\Enum\Mail\SwipeDirection;
use App\Domain\Enum\User\ClockPlacement;
use App\Domain\Helper\TimezoneHelper;
use App\Domain\Model\UserEntityModel;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\Embeddable\AiPreferences;
use App\Entity\Embeddable\Appearance;
use App\Entity\Embeddable\CategorySorting;
use App\Entity\Embeddable\MessageList;
use App\Entity\Mail\Account;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\User\User\UserRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints\DateTime;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
// On the entity rather than in one form, because the column is unique and every
// form bound to a User therefore fails the same way: the admin panel answered
// 500 on an address that already existed, because nothing looked before
// Postgres did. One rule where the invariant lives beats a copy of the check in
// UserFormType, FirstAdminType and whatever binds a User next.
//
// Deliberately not scoped to undeleted rows, which is why it does not reach for
// UserRepository::findOneByEmailExcept(). The unique index covers soft-deleted
// rows too, so a validator that skipped them would hand the 500 straight back
// for the one address that can still collide — `deleted-<id>@invalid`, which
// the admin delete action writes and which nothing stops someone typing.
#[UniqueEntity(fields: ['email'], message: 'user.email_taken')]
// One account per provider identity. Two rows holding the same pair would make
// a sign-in through the provider land on whichever Postgres returned first.
// Both columns are null on every account that never used the provider, and
// Postgres does not consider nulls equal, so those never collide. Issuer
// first because that is how the one query that reads it asks — both columns,
// by equality — and an install has one issuer and many subjects.
#[ORM\UniqueConstraint(name: 'uniq_user_oidc_identity', columns: ['oidc_issuer', 'oidc_subject'])]
class User extends UserEntityModel implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    use TimestampableTrait;

    /* Core roles */
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    public const string ROLE_USER = 'ROLE_USER';

    /**
     * The shortest password this install accepts, in characters.
     *
     * On the entity rather than in the form that first needed it, because three
     * places have to agree and until now they agreed by coincidence:
     * UserFormType, where an administrator creates somebody; `app:user:password`,
     * where an operator repairs somebody; and ChangePasswordType, where a person
     * changes their own. The first two each held a literal 12 with a comment
     * saying it matched the other, which is a convention rather than a fact —
     * and the third would have been a third chance to pick a different number.
     *
     * FirstAdminType is the one form that does NOT read this, and its lower
     * floor is left alone deliberately. Raising it here would change what the
     * install wizard demands of the very first password on an install, which is
     * a decision about onboarding rather than about this constant.
     */
    public const int PASSWORD_MIN_LENGTH = 12;

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

    /** What a row in the message list shows (#608); see MessageList. */
    #[ORM\Embedded(class: MessageList::class, columnPrefix: 'message_list_')]
    public private(set) MessageList $messageList;

    /**
     * What this person has decided about the AI features an administrator
     * switched on for the installation.
     *
     * An embeddable beside Appearance rather than a handful of keys in
     * $settings, for the reason that bag's own docblock draws the line on: this
     * is a fixed, validated set, and two of its fields are free text that ends
     * up inside a prompt — where the bound has to be a column length and a
     * clamping setter rather than a convention readers are trusted to keep.
     *
     * private(set) like $appearance: the fields inside are public and are
     * written directly, but nobody may swap the whole object out from under a
     * hydrated row.
     */
    #[ORM\Embedded(class: AiPreferences::class, columnPrefix: 'ai_')]
    public private(set) AiPreferences $aiPreferences;

    /**
     * How this person's mail is sorted into tabs.
     *
     * Its own embeddable rather than two more fields in $aiPreferences,
     * because only half of it is about the assistant: whether plMail's sorting
     * overrules the provider's applies just as much on an installation with no
     * model at all. See {@see CategorySorting}.
     *
     * private(set) like the two above.
     */
    #[ORM\Embedded(class: CategorySorting::class, columnPrefix: 'category_')]
    public private(set) CategorySorting $categorySorting;
    /**
     * The hashed password — of the password itself, or of what the browser
     * derived from it. $kdfSalt says which; see App\Security\PasswordKdf.
     *
     * Still publicly writable, because a rehash to a better algorithm keeps the
     * scheme and is exactly that: one write. Anything that sets a NEW password
     * goes through useServerHashedPassword() or useClientDerivedPassword(),
     * which keep the salt and the iteration count in step with it.
     */
    #[ORM\Column]
    public ?string $password = null;

    /**
     * The salt the browser stretches this person's password with, base64. Null
     * means $password is a hash of the password itself.
     *
     * Null for everybody whose password was set before the browser started
     * stretching it, and for anyone whose password was last set by something
     * with no browser to do that: the install wizard, an administrator
     * creating them, `app:user:password`, a seed. Their next sign-in through
     * the form moves them over — see App\Security\PasswordKdfUpgradeListener.
     *
     * private(set), with $kdfIterations, so that the pair cannot be changed
     * without $password, and the three never disagree about which scheme the
     * row is on. A salt left behind on a server-hashed row would have the
     * browser stretch a password the stored hash was never made from, and the
     * account would refuse its own owner.
     */
    #[ORM\Column(name: 'kdf_salt', length: 64, nullable: true)]
    public private(set) ?string $kdfSalt = null;

    /**
     * PBKDF2 iterations for $kdfSalt — stored per user rather than read from
     * configuration, so that raising the configured number later does not lock
     * out everybody whose hash was made with the old one.
     */
    #[ORM\Column(name: 'kdf_iterations', nullable: true)]
    public private(set) ?int $kdfIterations = null;

    /**
     * What the keyring of issue #28 hangs off — see KeyringRoot. The password
     * for every account until one without a usable password sets encryption
     * up with a passphrase of its own; read $keyringRootInEffect rather than
     * this to know which applies before that.
     *
     * private(set), with the two passphrase columns below, for the reason
     * $kdfSalt gives: the root, its salt and its iteration count change
     * together through usePassphraseRoot() or not at all.
     */
    #[ORM\Column(name: 'keyring_root', length: 16, enumType: KeyringRoot::class, options: ['default' => 'password'])]
    public private(set) KeyringRoot $keyringRoot = KeyringRoot::Password;

    /**
     * The salt the browser stretches the encryption passphrase with, base64.
     * Null unless $keyringRoot is the passphrase. Its own salt, not $kdfSalt:
     * an account that later gets a login password must not have the two
     * secrets stretched alike, or a password equal to the passphrase would
     * hand the server — which sees the password's auth hash — the very bytes
     * the passphrase's auth hash would have been.
     */
    #[ORM\Column(name: 'passphrase_salt', length: 64, nullable: true)]
    public private(set) ?string $passphraseSalt = null;

    /**
     * PBKDF2 iterations for $passphraseSalt, per user for the reason
     * $kdfIterations gives.
     */
    #[ORM\Column(name: 'passphrase_iterations', nullable: true)]
    public private(set) ?int $passphraseIterations = null;

    /**
     * Whether a password could sign this person in at all. Empty is the hash
     * no password produces, which OidcUserResolver writes for an account it
     * creates and the admin delete action writes for a removed one.
     */
    public bool $hasUsablePassword {
        get => '' !== (string) $this->password;
    }

    /**
     * The root that applies now: the passphrase once it is set up, and also
     * before — for an account with no usable password, which has nothing else
     * a keyring could hang off and is offered "Set up encryption" instead. An
     * account that gets a password before it sets anything up is a password
     * account like any other.
     */
    public KeyringRoot $keyringRootInEffect {
        get => KeyringRoot::Passphrase === $this->keyringRoot || false === $this->hasUsablePassword
            ? KeyringRoot::Passphrase
            : KeyringRoot::Password;
    }

    /**
     * The current password was chosen by somebody else, and this person has to
     * pick their own before plMail lets them do anything.
     *
     * Set when an administrator creates the account, and by
     * `app:user:password`: in both cases a second person knows the password,
     * and once the keys of issue #28 hang off it, that person could open them.
     * App\Security\PasswordChangeRequiredListener holds the line; the form is
     * App\Controller\Auth\RequiredPasswordController.
     */
    #[ORM\Column(name: 'password_change_required', options: ['default' => false])]
    public bool $passwordChangeRequired = false;

    /**
     * Nullable: removing a picture is a normal thing to do, and the column has
     * always allowed it.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $avatar = null;

    /**
     * The filename of a saved handwritten signature, for stamping onto PDFs.
     *
     * A filename rather than the image, for the same reason the avatar is one:
     * a PNG has no business in a row that is read on every request. Nullable
     * because most people will never save one, and clearing it is a normal
     * thing to do.
     *
     * It is a picture of a name and nothing more — no key material, nothing to
     * verify against. See App\Domain\Helper\SignatureStorage, which is careful
     * about the difference.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $signature = null;

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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $lastLogin = null;



    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    /**
     * The address this user held when they were removed.
     *
     * Removal frees the address on purpose — the delete action rewrites $email
     * to `deleted-<id>@invalid` so the same person can be added back later —
     * and that rewrite destroys the only copy of what they were called. This is
     * the second copy, written at the same moment and never afterwards.
     *
     * It exists because a config restore has to be able to recognise somebody
     * an administrator removed. {@see ConfigBackupUserRestorer} refuses to
     * recreate a removed user, on the grounds that quietly returning a password
     * hash, a TOTP secret and every mailbox credential to a removed account is
     * worse than restoring nothing — but it can only refuse what it can
     * identify, and after the rewrite there was nothing left to match on. The
     * restore had been recreating them, live, as ordinary new users.
     *
     * Deliberately not unique and deliberately not backfilled: an address may
     * be held and released more than once, and rows tombstoned before this
     * column existed have no address left to record. See the migration.
     */
    #[ORM\Column(length: 180, nullable: true)]
    public ?string $emailAtDeletion = null;

    /**
     * When an administrator suspended this account, if they have.
     *
     * The middle setting between "can sign in" and "removed", and the one the
     * panel had no way to express: a colleague on leave, a shared machine that
     * should be shut out for a fortnight, an account that looks compromised.
     * Until this existed the only answer was removal, which frees the address
     * and tombstones the name — a decision far larger than the question being
     * asked, and one nothing here can undo.
     *
     * A timestamp rather than a boolean, mirroring $deletedAt beside it. "When"
     * answers "is it" for free, and an admin looking at a suspended account a
     * month later usually wants to know how long it has been that way. The
     * column is the whole of the state: nothing else is written, nothing is
     * renamed, and every account, message, label and app password stays exactly
     * where it was, so reactivating is clearing this one field.
     *
     * What enforces it is App\Security\UserChecker, which refuses the account
     * at authentication, and UserProvider::refreshUser(), which ends a session
     * that was already open. Neither is expressible as a column, so both are
     * named here — a flag nothing reads is a flag that quietly means nothing.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $deactivatedAt = null;

    /**
     * Whether this account is currently suspended.
     *
     * A property rather than an `isDeactivated()` method: it takes nothing and
     * answers about one field, which is the rule §4.4 of CODESTYLE states.
     * `isDeleted()` next door is a method only because it predates the rule.
     */
    public bool $isDeactivated {
        get => null !== $this->deactivatedAt;
    }

    /**
     * The OpenID Connect identity this account signs in as, if it has one.
     *
     * Issuer and subject together, because that pair is the only thing OIDC
     * promises is stable: `sub` is unique per issuer and nowhere else, and the
     * address an identity provider reports can be edited there at any time.
     * Matching on the address on every sign-in would let whoever can change
     * their own address at the provider walk into any account here that holds
     * the new one. The address is used once, to link an existing account the
     * first time, and only when the provider vouches for it — see
     * App\Service\Oidc\OidcUserResolver.
     *
     * The issuer is kept rather than assumed so that pointing the install at a
     * different provider does not hand the new provider's `sub` values the
     * old provider's accounts: a row linked to another issuer is relinked by
     * address, never matched by subject.
     *
     * Cleared by the admin delete action, so a removed account cannot be
     * signed into through the provider and does not hold the identity against
     * a new account for the same person.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $oidcIssuer = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $oidcSubject = null;

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
        $this->messageList = new MessageList();
        $this->aiPreferences = new AiPreferences();
        $this->categorySorting = new CategorySorting();
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
     * A new password, hashed from the password itself — set by something that
     * had the plaintext and no browser to stretch it first.
     */
    public function useServerHashedPassword(string $hash): void
    {
        $this->password      = $hash;
        $this->kdfSalt       = null;
        $this->kdfIterations = null;
    }

    /**
     * A new password, hashed from the auth hash a browser derived from it with
     * this salt and iteration count.
     */
    public function useClientDerivedPassword(string $hash, string $salt, int $iterations): void
    {
        $this->password      = $hash;
        $this->kdfSalt       = $salt;
        $this->kdfIterations = $iterations;
    }

    public function hasClientDerivedPassword(): bool
    {
        return null !== $this->kdfSalt;
    }

    /**
     * The keyring hangs off an encryption passphrase from now on, stretched
     * with this salt and iteration count — or with new ones, when the
     * passphrase changes. The login, whatever it is, is not touched.
     */
    public function usePassphraseRoot(string $salt, int $iterations): void
    {
        $this->keyringRoot          = KeyringRoot::Passphrase;
        $this->passphraseSalt       = $salt;
        $this->passphraseIterations = $iterations;
    }

    /**
     * The root a config backup recorded, as it was. Only the restore calls
     * this: switching a keyring between roots is not something plMail does,
     * and a passphrase salt left on a password row would be read by nothing.
     */
    public function restoreKeyringRoot(KeyringRoot $root, ?string $salt, ?int $iterations): void
    {
        $this->keyringRoot          = $root;
        $this->passphraseSalt       = KeyringRoot::Passphrase === $root ? $salt : null;
        $this->passphraseIterations = KeyringRoot::Passphrase === $root ? $iterations : null;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials()
    {
        // Nothing to erase: the entity holds only the password hash, never a
        // plaintext — every password form field is unmapped.
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
     * Where the running clock is drawn, as a ClockPlacement value.
     *
     * In the settings bag beside SETTING_CLOCK, and for the same reasons: one
     * string of display preference that nothing queries by. Absent means the
     * topbar, which is the default the enum documents.
     */
    public const string SETTING_CLOCK_PLACEMENT = 'display.clock_placement';

    /** Virtual, out of the settings bag — see SETTING_CLOCK_PLACEMENT. */
    public ClockPlacement $clockPlacement {
        get => ClockPlacement::fromSetting($this->getSetting(self::SETTING_CLOCK_PLACEMENT));
        set (ClockPlacement $placement) {
            $this->setSetting(self::SETTING_CLOCK_PLACEMENT, $placement->value);
        }
    }

    /**
     * The accounts whose folder lists are expanded in the sidebar.
     *
     * Server-side for the same reason the calendar pane's width is: the
     * sidebar re-renders on every visit, and a list restored by JavaScript
     * afterwards is a list the user watches blink. Rendered into the first
     * paint, it never moves.
     *
     * Several at once since #690. It used to be one account, and opening a
     * second closed the first; with labels listed by account an account's row
     * is its whole label tree, and comparing two accounts' trees — the case
     * the issue was about — meant opening and closing them in turn. The key
     * is the one the single id was stored under, and a bag still holding an
     * int reads as that one account (see $expandedAccountIds), so nobody's
     * open account closes on upgrade.
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

    /**
     * Whether the sidebar's LABELS section lists folders too (#221 step 3).
     *
     * A folder is listed under its account, and LABELS holds the tags — a
     * label that is a folder on every account it is a place on, and a tag on
     * none, is under the accounts alone. On, LABELS lists every label again,
     * folders with their folder mark, as it did before step 3. Off by default.
     */
    public const string SETTING_FOLDERS_IN_LABELS = 'sidebar.folders_in_labels';

    /** Virtual, out of the settings bag — see SETTING_FOLDERS_IN_LABELS. */
    public bool $foldersInLabels {
        get => true === $this->getSetting(self::SETTING_FOLDERS_IN_LABELS, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_FOLDERS_IN_LABELS, $on ? true : null);
        }
    }

    /**
     * Whether the sidebar lists labels by account rather than merged (#690).
     *
     * Merged, the default, is the LABELS section: one plMail label, however
     * many accounts it is on. By account, each account's own labels — its
     * LabelBindings — are listed under the account in ACCOUNTS, beside its
     * folders, and LABELS keeps only the labels that are on no account yet,
     * which would otherwise be nowhere. Nothing about the labels changes; this
     * is which half of the model the sidebar shows. Takes precedence over
     * SETTING_FOLDERS_IN_LABELS, which is a question about the merged list.
     */
    public const string SETTING_LABELS_BY_ACCOUNT = 'sidebar.labels_by_account';

    /** Virtual, out of the settings bag — see SETTING_LABELS_BY_ACCOUNT. */
    public bool $labelsByAccount {
        get => true === $this->getSetting(self::SETTING_LABELS_BY_ACCOUNT, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_LABELS_BY_ACCOUNT, $on ? true : null);
        }
    }

    /** The collapse keys for the two whole-section disclosures. */
    public const string SIDEBAR_SECTION_LABELS = 'section:labels';
    public const string SIDEBAR_SECTION_ACCOUNTS = 'section:accounts';

    /**
     * The sidebar rows the user keeps behind "More", as a list of
     * {@see SidebarRow} values — absent for everybody who has never chosen.
     *
     * Three states, and they are three: ABSENT is the default
     * ({@see SidebarRow::defaultBehindMore()}), a list is the choice, and an
     * EMPTY list is a choice too — "nothing behind More" — which is why this
     * is not stored the way SETTING_SIDEBAR_COLLAPSED is. Reading an empty
     * list as absent would hand the default back to the person who had just
     * switched it off.
     *
     * The rows that are behind More are stored rather than the rows that are
     * on top, for the reason the collapsed keys are: a row added in a later
     * version then appears on top, the quiet place, without anybody's bag
     * needing a backfill.
     *
     * Not the same question as the eye toggle in label settings, which hides a
     * row entirely. This only says WHERE a row that exists is drawn.
     */
    public const string SETTING_SIDEBAR_MORE = 'sidebar.more_rows';

    /**
     * When this admin last had the log browser open, as an ISO 8601 string.
     *
     * In the settings bag rather than a column: it is a read marker for one
     * screen, of no interest to anything that queries users, and this is the
     * bag that already carries per-user UI state to their other devices.
     */
    public const string SETTING_LOGS_SEEN_AT = 'admin.logs_seen_at';

    /**
     * The user's workspaces: which panes are on screen, how they are arranged,
     * and which arrangement is active (#755). One document, read and written
     * by WorkspaceStore; see Workspaces for its shape. Divider positions are
     * NOT here: they belong to the device (DeviceLayout).
     */
    public const string SETTING_WORKSPACES = 'layout.workspaces';

    /**
     * Which of day/week/month/agenda the calendar opens on, remembered per
     * SHAPE — the full page and the docked pane keep their own.
     *
     * TWO KEYS, NOT ONE, and the reason is the pane's width. The two surfaces
     * have always opened on different views on purpose: the page on Week, the
     * pane on Agenda, because seven columns in a 380px strip are seven slivers.
     * Remembering one view for both would carry a choice made on a full-width
     * page into a strip that has to widen itself to honour it — see
     * CalendarView::minimumPaneWidth(), which makes picking Month in the pane
     * WIDEN the pane. That is right when somebody picks it there and surprising
     * when it happens because of something they did somewhere else.
     *
     * So each shape remembers what was last chosen in it, and the defaults
     * below are what each opened on before this existed. Nobody's calendar
     * moves until they move it.
     */
    public const string SETTING_CALENDAR_VIEW = 'calendar.view';
    public const string SETTING_CALENDAR_PANE_VIEW = 'calendar.pane_view';

    /**
     * The view of each FURTHER calendar pane, by its id (LayoutPane::$id): a
     * workspace can show several calendars, each on its own view — this week
     * beside this month — and choosing one's view leaves the others alone.
     * The first calendar pane, which has no id, keeps
     * SETTING_CALENDAR_PANE_VIEW. A pane with nothing stored opens on what the
     * first one shows. Ids of panes since removed stay, up to a cap: a
     * handful of short strings, and a pane put back finds its view.
     */
    public const string SETTING_CALENDAR_PANE_VIEWS = 'calendar.pane_views';

    /**
     * How many further panes' views are kept. Past it some are dropped — not
     * strictly the oldest, as the column is JSONB and keeps keys in an order
     * of its own; a dropped pane just opens on the first pane's view.
     */
    private const int CALENDAR_PANE_VIEWS_KEPT = 24;

    /**
     * The list a new task lands on when nothing else was picked (My Day, All, quick
     * add from either). An id, read through TaskListProvisioner::landingFor(), which
     * falls back to the default list when this one is gone, foreign or read-only.
     */
    public const string SETTING_TASKS_ADD_TO = 'tasks.add_to';

    /**
     * What a dragged row does, one key per direction — see SwipeAction and
     * SwipeDirection. The bag, for a choice about THIS screen that needs no column and no migration. Each
     * stores one SwipeAction value; anything else stored reads as the
     * direction's default (SwipeAction::fromSetting).
     */
    public const string SETTING_SWIPE_RIGHT_ACTION = 'swipe.right_action';
    public const string SETTING_SWIPE_LEFT_ACTION = 'swipe.left_action';

    /**
     * How wide the sidebar is, and whether a row too long for it may lean out
     * over the mail to show itself (#699).
     *
     * The bag, so it is rendered into the first paint, so a sidebar never appears at 224px and jumps to the
     * user's 300px once JavaScript runs, and the same at every desk. No
     * migration.
     *
     * Pixels rather than a share, unlike a workspace's dividers: the sidebar holds
     * rows of text whose length does not change with the window, so the width
     * that fits them is a number of pixels on any screen.
     *
     * PEEK is ON by default, the maintainer's call on #699, and an exception to
     * "a setting that changes how plMail behaves starts off" (CONTRIBUTING).
     * It costs nothing where nothing is clipped — the sidebar only leans out
     * over a name that is cut off — and a cut-off name is exactly the problem
     * the issue reports, so the default is the one that answers it without a
     * trip to Settings. Switched off it is stored as false, which is how it
     * stays off.
     */
    public const string SETTING_SIDEBAR_WIDTH = 'sidebar.width';
    public const string SETTING_SIDEBAR_PEEK = 'sidebar.peek';

    /**
     * Matches the clamp in ui--sidebar-split; the server is what enforces it.
     *
     * 224 is what `w-56` was before the boundary existed, so nobody's sidebar
     * moves until they move it. 180 is what a row needs to still be a row (an
     * icon, a word of label and a badge); below that the icon rail is the
     * better answer and the burger already reaches it. 480 is generous because
     * the complaint is a nested tree that will not fit, but it is a number and
     * not "half the window", so a wide screen cannot store a sidebar that a
     * laptop then has to clamp away.
     */
    public const int SIDEBAR_MIN_WIDTH = 180;
    public const int SIDEBAR_MAX_WIDTH = 480;
    public const int SIDEBAR_DEFAULT_WIDTH = 224;

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

    /**
     * The order of the compose window's action bar, as a list of
     * {@see \App\Domain\Enum\Mail\ComposeAction} values — absent for
     * everybody who has never arranged it, and cleared again by "Reset", so
     * a change to the default order reaches them. Read through
     * $composeActionOrder, which makes any stored list whole.
     */
    public const string SETTING_COMPOSE_ACTION_ORDER = 'compose.action_order';

    /** The composer closes at once and the toast carries the undo. */
    public const string SEND_FEEDBACK_OPTIMISTIC = 'optimistic';

    /** The composer stays open and is its own cancel. */
    public const string SEND_FEEDBACK_HOLD = 'hold';

    /** Virtual, out of the settings bag — see SETTING_TASKS_ADD_TO. */
    public ?int $tasksAddToListId {
        get {
            $id = $this->getSetting(self::SETTING_TASKS_ADD_TO);

            return is_int($id) && 0 < $id ? $id : null;
        }
    }

    /**
     * Virtual, out of the settings bag — see SETTING_SIDEBAR_WIDTH.
     *
     * Clamped on the way out as well as on the way in: a stored width outside
     * the range is a sidebar nobody can drag back.
     */
    public int $sidebarWidth {
        get {
            $width = $this->getSetting(self::SETTING_SIDEBAR_WIDTH, self::SIDEBAR_DEFAULT_WIDTH);

            if (false === is_int($width)) {
                return self::SIDEBAR_DEFAULT_WIDTH;
            }

            return max(self::SIDEBAR_MIN_WIDTH, min(self::SIDEBAR_MAX_WIDTH, $width));
        }
        set (int $width) {
            $this->setSetting(self::SETTING_SIDEBAR_WIDTH, max(self::SIDEBAR_MIN_WIDTH, min(self::SIDEBAR_MAX_WIDTH, $width)));
        }
    }

    /** Virtual, out of the settings bag — see SETTING_SIDEBAR_PEEK. */
    public bool $sidebarPeek {
        get => false !== $this->getSetting(self::SETTING_SIDEBAR_PEEK, true);
        set (bool $peek) {
            $this->setSetting(self::SETTING_SIDEBAR_PEEK, $peek);
        }
    }

    /**
     * Virtual, out of the settings bag — see SETTING_SWIPE_RIGHT_ACTION.
     *
     * Read per direction: an unreadable value here falls back to Right's default
     * and never touches the left choice.
     */
    public SwipeAction $swipeRightAction {
        get => SwipeAction::fromSetting(
            $this->getSetting(self::SETTING_SWIPE_RIGHT_ACTION),
            SwipeDirection::Right->defaultAction(),
        );
        set (SwipeAction $action) {
            $this->setSetting(self::SETTING_SWIPE_RIGHT_ACTION, $action->value);
        }
    }

    /** Virtual, out of the settings bag — see SETTING_SWIPE_LEFT_ACTION. Falls back to Left's default. */
    public SwipeAction $swipeLeftAction {
        get => SwipeAction::fromSetting(
            $this->getSetting(self::SETTING_SWIPE_LEFT_ACTION),
            SwipeDirection::Left->defaultAction(),
        );
        set (SwipeAction $action) {
            $this->setSetting(self::SETTING_SWIPE_LEFT_ACTION, $action->value);
        }
    }

    /**
     * The view the full calendar page opens on. Week until somebody chooses
     * otherwise, which is what it always did.
     *
     * Virtual, out of the settings bag, so it is rendered into the first
     * paint and follows the user to every desk. `tryFrom` rather than `from`: the bag is JSON that a config
     * restore or an older build may have written, and an unrecognised value is
     * a reason to open on the default rather than to fail rendering the
     * calendar.
     */
    public CalendarView $calendarView {
        get => CalendarView::tryFrom((string) $this->getSetting(self::SETTING_CALENDAR_VIEW, ''))
            ?? CalendarView::Week;
        set (CalendarView $view) {
            $this->setSetting(self::SETTING_CALENDAR_VIEW, $view->value);
        }
    }

    /**
     * The same for the docked pane, which opens on Agenda by default and keeps
     * its own answer — see SETTING_CALENDAR_PANE_VIEW for why the two are not
     * one setting.
     */
    public CalendarView $calendarPaneView {
        get => CalendarView::tryFrom((string) $this->getSetting(self::SETTING_CALENDAR_PANE_VIEW, ''))
            ?? CalendarView::Agenda;
        set (CalendarView $view) {
            $this->setSetting(self::SETTING_CALENDAR_PANE_VIEW, $view->value);
        }
    }

    /**
     * The view the calendar pane of that id opens on; null is the first pane.
     * See SETTING_CALENDAR_PANE_VIEWS.
     */
    public function calendarPaneViewFor(?string $paneId): CalendarView
    {
        if (null === $paneId) {
            return $this->calendarPaneView;
        }

        $views = $this->getSetting(self::SETTING_CALENDAR_PANE_VIEWS);
        $view  = is_array($views) && is_string($views[$paneId] ?? null) ? CalendarView::tryFrom($views[$paneId]) : null;

        return $view ?? $this->calendarPaneView;
    }

    /** Keep the view of the calendar pane of that id; null is the first pane. */
    public function setCalendarPaneViewFor(?string $paneId, CalendarView $view): void
    {
        if (null === $paneId) {
            $this->calendarPaneView = $view;

            return;
        }

        $views = $this->getSetting(self::SETTING_CALENDAR_PANE_VIEWS);
        $views = is_array($views) ? array_filter($views, static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH) : [];

        unset($views[$paneId]);
        $views[$paneId] = $view->value;

        $this->setSetting(self::SETTING_CALENDAR_PANE_VIEWS, array_slice($views, -self::CALENDAR_PANE_VIEWS_KEPT, null, true));
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

    /**
     * Whether single keys act on mail — `e` archives, `#` deletes, `j` and `k`
     * move through the list. See assets/controllers/ui/shortcuts_controller.js.
     *
     * ON unless somebody has said otherwise, which is the opposite of the
     * client these are borrowed from. Gmail ships them off because a stray key
     * can file mail; here every one of those actions answers with an Undo, and
     * a feature that has to be found in a settings page before it exists is a
     * feature for the handful of people who went looking.
     *
     * Only a stored false is off, so the key is absent for nearly everybody
     * and the default can be read without a migration having written it.
     */
    public const string SETTING_KEYBOARD_SHORTCUTS = 'keyboard.shortcuts';

    /** Virtual, out of the settings bag — see SETTING_KEYBOARD_SHORTCUTS. */
    public bool $keyboardShortcuts {
        get => false !== $this->getSetting(self::SETTING_KEYBOARD_SHORTCUTS, true);
        set(bool $on) {
            $this->setSetting(self::SETTING_KEYBOARD_SHORTCUTS, $on ? null : false);
        }
    }

    /**
     * Whether the buttons that delete mail for good are offered: the flame on a
     * row in Spam and the bin, and the same button above a selection there.
     *
     * OFF unless somebody switches it on — the opposite of the shortcuts above,
     * and for the opposite reason. This is the one action in plMail with no
     * Undo, and nothing that cannot be taken back should be one stray click
     * away for somebody who never asked for it. Only a stored true is on, so
     * the key is absent for nearly everybody, and routes that delete for good
     * refuse with a 403 while it is, not only the templates that draw the
     * button: a switch that only hid the flame would leave a hand-made POST
     * working. Per USER, like every setting in this bag — Settings → Inbox →
     * Disposal.
     */
    public const string SETTING_DISPOSAL_DELETE_NOW = 'disposal.delete_now';

    /**
     * Pinned conversations (#654), three keys in the bag: no column and no
     * migration for a choice about how this person's lists behave.
     *
     * - reminder_at: how many pins before pinning asks "pin anyway?". A
     *   reminder, never a limit; 0 switches it off.
     * - duration_days: how long a new pin lasts; absent for forever.
     * - keep_on_move: whether a pin survives the conversation being moved or
     *   archived; absent for yes. Stored only when switched off, so the bag
     *   carries nothing for anybody on the default.
     */
    public const string SETTING_PIN_REMINDER_AT = 'pins.reminder_at';
    public const string SETTING_PIN_DURATION_DAYS = 'pins.duration_days';
    public const string SETTING_PIN_KEEP_ON_MOVE = 'pins.keep_on_move';

    /**
     * Whether the lists show conversations or single emails (#615). Absent
     * for conversations, the default; a stored false lists one row per email.
     * A display preference switched from the list toolbar as often as from
     * Settings, so in the bag like the rest of them.
     */
    public const string SETTING_CONVERSATION_VIEW = 'list.conversations';

    /** Twenty: about a screenful of rows, past which the top of a list is no longer "the few I need". */
    public const int PIN_REMINDER_DEFAULT = 20;

    /**
     * The longest a pin may be set to last: ten years, which is forever by
     * another name, and keeps the end date a date the database can hold.
     */
    public const int PIN_DURATION_MAX_DAYS = 3650;

    /** The reminder can go this high; past it, "off" is the honest setting. */
    public const int PIN_REMINDER_MAX = 999;

    /** Virtual, out of the settings bag — see SETTING_PIN_REMINDER_AT. Anything unreadable is the default. */
    public int $pinReminderAt {
        get {
            $stored = $this->getSetting(self::SETTING_PIN_REMINDER_AT);

            if (false === is_int($stored) || $stored < 0 || $stored > self::PIN_REMINDER_MAX) {
                return self::PIN_REMINDER_DEFAULT;
            }

            return $stored;
        }
        set(int $count) {
            $count = max(0, min(self::PIN_REMINDER_MAX, $count));

            $this->setSetting(self::SETTING_PIN_REMINDER_AT, self::PIN_REMINDER_DEFAULT === $count ? null : $count);
        }
    }

    /** Virtual, out of the settings bag — see SETTING_PIN_DURATION_DAYS. Null is forever. */
    public ?int $pinDurationDays {
        get {
            $stored = $this->getSetting(self::SETTING_PIN_DURATION_DAYS);

            if (false === is_int($stored) || $stored < 1 || $stored > self::PIN_DURATION_MAX_DAYS) {
                return null;
            }

            return $stored;
        }
        set(?int $days) {
            $this->setSetting(
                self::SETTING_PIN_DURATION_DAYS,
                null === $days ? null : max(1, min(self::PIN_DURATION_MAX_DAYS, $days)),
            );
        }
    }

    /** Virtual, out of the settings bag — see SETTING_PIN_KEEP_ON_MOVE. On unless switched off. */
    public bool $pinKeepOnMove {
        get => false !== $this->getSetting(self::SETTING_PIN_KEEP_ON_MOVE, true);
        set(bool $keep) {
            $this->setSetting(self::SETTING_PIN_KEEP_ON_MOVE, $keep ? null : false);
        }
    }

    /** Virtual, out of the settings bag — see SETTING_CONVERSATION_VIEW. On unless switched off. */
    public bool $conversationView {
        get => false !== $this->getSetting(self::SETTING_CONVERSATION_VIEW, true);
        set(bool $on) {
            $this->setSetting(self::SETTING_CONVERSATION_VIEW, $on ? null : false);
        }
    }

    /** Virtual, out of the settings bag — see SETTING_DISPOSAL_DELETE_NOW. */
    public bool $deleteNowButtons {
        get => true === $this->getSetting(self::SETTING_DISPOSAL_DELETE_NOW, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_DISPOSAL_DELETE_NOW, $on ? true : null);
        }
    }

    /**
     * What mail is worth a notification, as switches the user turns on: an
     * inbox tab each, and a label each. See NotifyScope for the keys and
     * NotifyScopes for which switches a message falls under.
     *
     * PER USER, NOT PER DEVICE (#369). The same mail notifying on one browser
     * and not on another, or on the phone and not on the laptop, is the
     * confusing outcome, not a feature. The one honest reason to want two sets
     * of rules is work against private mail, and that belongs to a set of
     * accounts, not to a device. Stored here, it reaches every browser through
     * PushDispatcher and the phone apps through NotificationSettings/get, which
     * apply it themselves because their pushes also drive their sync.
     *
     * PRIMARY ONLY UNTIL SOMEBODY CHOOSES, which is Gmail's default and the
     * Android app's. The browsers used to be told of every arrival; that was
     * changed on purpose, and the changelog says so. The key is absent for
     * everybody who has not chosen, and the default stored back is absent again,
     * so no migration writes it.
     *
     * An empty list is allowed and means no mail notifies. It is a choice the
     * phone can already make (Primary off, nothing else on), and the server
     * refusing to store it would make the phone's screen lie.
     */
    public const string SETTING_NOTIFY_SCOPES = 'notifications.scopes';

    /**
     * The switches that are on — [NotifyScope::PRIMARY] until chosen.
     *
     * Setting keeps only well-formed keys, once each and in the order given; a
     * key for a tab or label that has since been deleted is kept, because it is
     * harmless and the phone may still show it. See NotifyScope::isWellFormed().
     *
     * @var list<string>
     */
    public array $notifyScopes {
        get {
            $stored = $this->getSetting(self::SETTING_NOTIFY_SCOPES);

            if (false === is_array($stored)) {
                return [NotifyScope::PRIMARY];
            }

            return array_values(array_filter($stored, 'is_string'));
        }
        set (array $scopes) {
            $kept = [];

            foreach ($scopes as $scope) {
                if (true === is_string($scope) && true === NotifyScope::isWellFormed($scope)) {
                    $kept[$scope] = $scope;
                }
            }

            $kept = array_values($kept);

            $this->setSetting(self::SETTING_NOTIFY_SCOPES, [NotifyScope::PRIMARY] === $kept ? null : $kept);
        }
    }

    public function notifiesFor(string $scope): bool
    {
        return true === in_array($scope, $this->notifyScopes, true);
    }

    /**
     * Whether a browser's "new mail" notification names the sender and the
     * subject (#760). The push itself stays content-free: the service worker
     * asks for the words over this user's own session when it arrives, through
     * WebPushController::inboxState(), see NewMailNotice.
     *
     * OFF unless somebody switches it on, like SETTING_NOTIFY_SKIP_READ below:
     * the words then show on a locked screen and in a shared screen too. Only a
     * stored true is on, and no migration writes it. Browsers only: the phone
     * apps write their own notifications.
     */
    public const string SETTING_NOTIFY_SHOW_CONTENT = 'notifications.show_content';

    /** Virtual, out of the settings bag — see SETTING_NOTIFY_SHOW_CONTENT. */
    public bool $notifyShowsContent {
        get => true === $this->getSetting(self::SETTING_NOTIFY_SHOW_CONTENT, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_NOTIFY_SHOW_CONTENT, $on ? true : null);
        }
    }

    /**
     * Whether a browser drops a notification for mail that has been read by the
     * time it would show it.
     *
     * A web push is content-free (a StateChange cannot say WHICH mail arrived)
     * and cannot be recalled, so a message that is read or deleted on a phone a
     * minute after it arrived has already told every other browser. The service
     * worker therefore waits a moment, asks WebPushController::inboxState()
     * whether recent mail under one of the switches above is still unread, and
     * shows nothing when not. Per user, for the reason the switches are.
     *
     * OFF unless somebody switches it on. Only a stored true is on, so the key
     * is absent for nearly everybody and no migration writes it.
     */
    public const string SETTING_NOTIFY_SKIP_READ = 'notifications.skip_read';

    /** Virtual, out of the settings bag — see SETTING_NOTIFY_SKIP_READ. */
    public bool $notifySkipsRead {
        get => true === $this->getSetting(self::SETTING_NOTIFY_SKIP_READ, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_NOTIFY_SKIP_READ, $on ? true : null);
        }
    }

    /**
     * How mail goes out once plMail can encrypt it — Settings → Encryption →
     * Sending. Four switches in the settings bag, each stored only when it
     * differs from its default, like the two above.
     *
     *   autocrypt      ON:  every message carries an Autocrypt header with
     *                       the sender's public key, so Thunderbird, Delta Chat
     *                       and the like can answer encrypted. Invisible to the
     *                       people who read the mail.
     *   attach key     OFF: the public key as a file on every message, for
     *                       recipients whose program does not read Autocrypt.
     *   sign plain     OFF: sign mail that goes out unencrypted. A
     *                       signature.asc confuses people without PGP, which is
     *                       why it is off; mail to somebody whose key plMail
     *                       knows is signed whatever this says.
     *   block partial  OFF: when some recipients can be reached encrypted and
     *                       others cannot, refuse to send rather than send the
     *                       mail unencrypted to all of them, which is the
     *                       default and what Proton does.
     */
    /**
     * Whether any of this person's accounts keeps decrypted mail on the
     * server (Account::SETTING_KEEP_DECRYPTED) — kept here, by the settings
     * page that switches those, so the layout can decide whether to mount the
     * background decrypter without a query of its own on every page.
     */
    public const string SETTING_KEEPS_DECRYPTED_ANY = 'crypto.keeps_decrypted_any';

    public const string SETTING_CRYPTO_AUTOCRYPT     = 'crypto.autocrypt';
    public const string SETTING_CRYPTO_ATTACH_KEY    = 'crypto.attach_key';
    public const string SETTING_CRYPTO_SIGN_PLAIN    = 'crypto.sign_plain';
    public const string SETTING_CRYPTO_BLOCK_PARTIAL = 'crypto.block_partial';

    /**
     * protect subject  ON:  an encrypted message carries its subject inside the
     *                       encryption, and the outside says "..." (RFC 9788,
     *                       what Thunderbird does by default). A recipient
     *                       whose program cannot read it sees "...".
     */
    public const string SETTING_CRYPTO_PROTECT_SUBJECT = 'crypto.protect_subject';

    /** Virtual, out of the settings bag — see SETTING_CRYPTO_PROTECT_SUBJECT. */
    public bool $protectSubject {
        get => false !== $this->getSetting(self::SETTING_CRYPTO_PROTECT_SUBJECT, true);
        set(bool $on) {
            $this->setSetting(self::SETTING_CRYPTO_PROTECT_SUBJECT, $on ? null : false);
        }
    }

    /** Virtual, out of the settings bag — see SETTING_CRYPTO_AUTOCRYPT. */
    public bool $autocryptHeader {
        get => false !== $this->getSetting(self::SETTING_CRYPTO_AUTOCRYPT, true);
        set(bool $on) {
            $this->setSetting(self::SETTING_CRYPTO_AUTOCRYPT, $on ? null : false);
        }
    }

    /** Virtual, out of the settings bag — see SETTING_CRYPTO_AUTOCRYPT. */
    public bool $attachPublicKey {
        get => true === $this->getSetting(self::SETTING_CRYPTO_ATTACH_KEY, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_CRYPTO_ATTACH_KEY, $on ? true : null);
        }
    }

    /** Virtual, out of the settings bag — see SETTING_CRYPTO_AUTOCRYPT. */
    public bool $signPlainMail {
        get => true === $this->getSetting(self::SETTING_CRYPTO_SIGN_PLAIN, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_CRYPTO_SIGN_PLAIN, $on ? true : null);
        }
    }

    /** Virtual, out of the settings bag — see SETTING_CRYPTO_AUTOCRYPT. */
    public bool $blockPartialEncryption {
        get => true === $this->getSetting(self::SETTING_CRYPTO_BLOCK_PARTIAL, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_CRYPTO_BLOCK_PARTIAL, $on ? true : null);
        }
    }

    /**
     * The addresses whose key the person does not want in plMail's Web Key
     * Directory (WkdDirectory). A list of the ones switched OFF rather than of
     * the ones switched on, so that publishing is the default the day an
     * administrator enables the domain, and so an address that becomes the
     * person's later starts published like the rest. Absent when empty.
     */
    public const string SETTING_CRYPTO_WKD_UNPUBLISHED = 'crypto.wkd_unpublished';

    /**
     * Virtual, out of the settings bag — see SETTING_CRYPTO_WKD_UNPUBLISHED.
     * Lower-cased and de-duplicated on the way in, so a comparison against a
     * binding's address never depends on how somebody typed it.
     *
     * @var list<string>
     */
    public array $wkdUnpublished {
        get {
            $addresses = $this->getSetting(self::SETTING_CRYPTO_WKD_UNPUBLISHED, []);

            return true === is_array($addresses) ? array_values(array_filter($addresses, 'is_string')) : [];
        }
        set(array $addresses) {
            $addresses = array_values(array_unique(array_map(static fn (string $address): string => strtolower(trim($address)), $addresses)));
            $this->setSetting(self::SETTING_CRYPTO_WKD_UNPUBLISHED, [] === $addresses ? null : $addresses);
        }
    }

    /**
     * Whether a lookup of a contact's key goes on to the public key servers
     * when the address's own domain has none (KeyServerLookup) — Settings →
     * Encryption → Contacts' keys.
     *
     * ON unless switched off, as Thunderbird asks keys.openpgp.org by default:
     * without it most people's keys are not found, and mail to them goes out
     * unencrypted. The price is that the server's operator learns which
     * address somebody here looked up, which is why the switch is there and
     * its hint says so. Stored only as a false.
     */
    public const string SETTING_CRYPTO_KEY_SERVERS = 'crypto.key_servers';

    /** Virtual, out of the settings bag — see SETTING_CRYPTO_KEY_SERVERS. */
    public bool $lookUpKeyServers {
        get => false !== $this->getSetting(self::SETTING_CRYPTO_KEY_SERVERS, true);
        set(bool $on) {
            $this->setSetting(self::SETTING_CRYPTO_KEY_SERVERS, $on ? null : false);
        }
    }

    /**
     * Whether mail to one of the person's OWN addresses is encrypted (#703):
     * the RecipientPolicy a contact's key carries in ContactKey::$encrypt,
     * for the addresses that have no contact key because the key is the
     * person's own. Set from the lock's popover in the composer
     * (RecipientPolicies).
     *
     * Here and not on AddressKeyBinding, where it would sit beside the key
     * like a contact's does: bindings are rewritten whenever the keyring is
     * — a key added, made primary, retired — and the choice is about the
     * address and the provider's app that reads it, not about which key
     * happens to serve it this month. By address, lower-cased, like
     * SETTING_CRYPTO_WKD_UNPUBLISHED; only `always` and `never` are kept, so
     * an address set back to Auto leaves nothing behind.
     */
    public const string SETTING_CRYPTO_OWN_ENCRYPT = 'crypto.own_encrypt';

    /**
     * Virtual, out of the settings bag — see SETTING_CRYPTO_OWN_ENCRYPT.
     *
     * @var array<string, string> address => RecipientPolicy value
     */
    public array $ownAddressEncrypt {
        get {
            $stored   = $this->getSetting(self::SETTING_CRYPTO_OWN_ENCRYPT, []);
            $policies = [];

            foreach (true === is_array($stored) ? $stored : [] as $address => $policy) {
                if (true === is_string($address) && true === is_string($policy)) {
                    $policies[$address] = $policy;
                }
            }

            return $policies;
        }
        set(array $policies) {
            $kept = [];

            foreach ($policies as $address => $policy) {
                if ('always' === $policy || 'never' === $policy) {
                    $kept[strtolower(trim((string) $address))] = $policy;
                }
            }

            ksort($kept);
            $this->setSetting(self::SETTING_CRYPTO_OWN_ENCRYPT, [] === $kept ? null : $kept);
        }
    }

    /**
     * Whether remote images load in every message without the "Show images"
     * bar, as if every sender were on the allowlist (#124).
     *
     * OFF unless somebody switches it on. The block is what keeps a sender's
     * tracking pixel from learning that, when and where a message was opened,
     * and that protection is worth most to the people who never thought to ask
     * for it. Somebody who has weighed it and would rather not click every time
     * can say so once. Only a stored true is on, so the key is absent for
     * nearly everybody.
     *
     * Spam is still blocked with this on — MessageRenderer reads a message in
     * Spam as the provider disputing who sent it, and no standing permission
     * outranks that. Images still come through the proxy, so a sender learns
     * that the message was opened but not the reader's address. Per USER, like
     * the allowlist it widens — Settings → Reading & writing → Reading.
     */
    public const string SETTING_IMAGES_ALWAYS_SHOW = 'images.always_show';

    /** Virtual, out of the settings bag — see SETTING_IMAGES_ALWAYS_SHOW. */
    public bool $alwaysShowImages {
        get => true === $this->getSetting(self::SETTING_IMAGES_ALWAYS_SHOW, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_IMAGES_ALWAYS_SHOW, $on ? true : null);
        }
    }

    /**
     * Which looked-up sender pictures this reader wants (#639), kind value =>
     * on/off, out of the settings bag. Only what differs from the kind's own
     * default is stored — see SenderPictureKind::onByDefault() for why
     * Gravatar alone starts off — so a later release that changes a default
     * reaches everyone who never chose.
     *
     * Per user, beside the remote images switch it resembles: Settings →
     * Reading & writing → Reading. None of it applies until the administrator
     * allows lookups at all (SenderPictureSettings).
     */
    public const string SETTING_SENDER_PICTURES = 'sender_pictures';

    /**
     * Stays a method: it takes the kind and asks the stored choices about it.
     * Contact photos and other users' pictures are not lookups and are always
     * on.
     */
    public function allowsSenderPicture(SenderPictureKind $kind): bool
    {
        if (false === $kind->isLookup()) {
            return true;
        }

        $chosen = $this->getSetting(self::SETTING_SENDER_PICTURES, []);
        $value  = is_array($chosen) ? ($chosen[$kind->value] ?? null) : null;

        return is_bool($value) ? $value : $kind->onByDefault();
    }

    public function allowSenderPicture(SenderPictureKind $kind, bool $on): static
    {
        $chosen = $this->getSetting(self::SETTING_SENDER_PICTURES, []);
        $chosen = is_array($chosen) ? $chosen : [];

        if ($on === $kind->onByDefault()) {
            unset($chosen[$kind->value]);
        } else {
            $chosen[$kind->value] = $on;
        }

        return $this->setSetting(self::SETTING_SENDER_PICTURES, [] === $chosen ? null : $chosen);
    }

    /**
     * Whether this user's profile picture is served to the internet as their
     * Libravatar (#642), for every address of theirs: OFF until they turn it
     * on, and only offered while the administrator runs the Libravatar route.
     *
     * The user's own choice and not the administrator's, because what it
     * does is personal: anybody who knows one of their addresses can fetch
     * their face. The administrator decides the server answers; each person
     * decides whether it answers for them. Only a stored true is on.
     */
    public const string SETTING_LIBRAVATAR_PUBLISH = 'libravatar.publish';

    /** Virtual, out of the settings bag — see SETTING_LIBRAVATAR_PUBLISH. */
    public bool $publishesLibravatar {
        get => true === $this->getSetting(self::SETTING_LIBRAVATAR_PUBLISH, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_LIBRAVATAR_PUBLISH, $on ? true : null);
        }
    }

    /**
     * Whether an account's own inbox (the account row in the sidebar) is shown
     * in the category tabs as well as the unified Inbox (#55).
     *
     * OFF unless somebody switches it on. With it on, clicking an account opens
     * its Primary tab, and its Social or Promotions mail is one more click
     * away; off, the account page is the account's whole inbox, which is what
     * it always was and what the account's own "Inbox" folder row still lists.
     * Turning a familiar page into a narrower one is a change somebody should
     * ask for, not one that arrives with an upgrade.
     *
     * Only read while the inbox tabs are on at all (CategorySorting::$tabs):
     * somebody who chose One list gets one list everywhere, whatever this says.
     * Only a stored true is on, so the key is absent for nearly everybody.
     * Per USER — Settings → Inbox → Categories & sorting.
     */
    public const string SETTING_ACCOUNT_INBOX_TABS = 'categories.account_tabs';

    /** Virtual, out of the settings bag — see SETTING_ACCOUNT_INBOX_TABS. */
    public bool $accountInboxTabs {
        get => true === $this->getSetting(self::SETTING_ACCOUNT_INBOX_TABS, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_ACCOUNT_INBOX_TABS, $on ? true : null);
        }
    }

    /**
     * The last plMail release this person was shown What's new for, as
     * `0.3.6` (#165).
     *
     * State, not a choice: WhatsNew writes it when the dialog is drawn, and
     * nobody sets it by hand. Everybody who existed when the dialog shipped
     * was given 0.3.1 by Version20261016090000; anybody created since gets the
     * running release on their first request (WhatsNewFirstSighting), which
     * is what keeps the dialog off a fresh install.
     */
    public const string SETTING_WHATS_NEW_SEEN = 'whats_new.seen_version';

    /** Virtual, out of the settings bag — see SETTING_WHATS_NEW_SEEN. */
    public ?string $whatsNewSeenVersion {
        get {
            $stored = $this->getSetting(self::SETTING_WHATS_NEW_SEEN);

            return true === is_string($stored) && 1 === preg_match('/^\d+\.\d+\.\d+$/', $stored) ? $stored : null;
        }
        set (?string $version) {
            $this->setSetting(self::SETTING_WHATS_NEW_SEEN, $version);
        }
    }

    /**
     * "Don't show this after updates", from the What's new dialog's footer, and
     * its way back under Settings → General (#165).
     *
     * Quiet means quiet: the dialog does not open by itself and the ? in the
     * top bar shows no dot. What's new is still in that menu for someone who
     * goes looking. Off unless somebody asks, so only a stored true is on.
     */
    public const string SETTING_WHATS_NEW_QUIET = 'whats_new.quiet';

    /** Virtual, out of the settings bag — see SETTING_WHATS_NEW_QUIET. */
    public bool $whatsNewQuiet {
        get => true === $this->getSetting(self::SETTING_WHATS_NEW_QUIET, false);
        set(bool $on) {
            $this->setSetting(self::SETTING_WHATS_NEW_QUIET, $on ? true : null);
        }
    }

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
     * Virtual: it is read out of the settings bag and has no column of its own.
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

    /**
     * Virtual, out of the settings bag — see SETTING_SIDEBAR_ACCOUNT.
     *
     * @var list<int>
     */
    public array $expandedAccountIds {
        get {
            $stored = $this->getSetting(self::SETTING_SIDEBAR_ACCOUNT);

            if (true === is_int($stored)) {
                return [$stored];
            }

            if (false === is_array($stored)) {
                return [];
            }

            return array_values(array_filter($stored, 'is_int'));
        }
        set (array $accountIds) {
            $kept = array_values(array_unique(array_filter($accountIds, 'is_int')));

            $this->setSetting(self::SETTING_SIDEBAR_ACCOUNT, [] === $kept ? null : $kept);
        }
    }

    public function isAccountExpanded(int $accountId): bool
    {
        return true === in_array($accountId, $this->expandedAccountIds, true);
    }

    public function setAccountExpanded(int $accountId, bool $expanded): void
    {
        $others = array_values(array_filter($this->expandedAccountIds, static fn (int $id): bool => $id !== $accountId));

        $this->expandedAccountIds = true === $expanded ? [...$others, $accountId] : $others;
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

    /**
     * The rows behind "More", in the order the sidebar draws them — see
     * SETTING_SIDEBAR_MORE for why absent and empty are different answers.
     *
     * Read defensively, because the bag outlives the code that wrote it: what
     * is not a row, a repeat, and the pinned Inbox are dropped rather than
     * thrown on, so one bad value cannot take the sidebar down. The result is
     * sorted into the enum's own order so the caller never has to.
     *
     * @var list<SidebarRow>
     */
    public array $sidebarMoreRows {
        get {
            $stored = $this->getSetting(self::SETTING_SIDEBAR_MORE);

            if (false === is_array($stored)) {
                return SidebarRow::defaultBehindMore();
            }

            return array_values(array_filter(
                SidebarRow::cases(),
                static fn (SidebarRow $row): bool => false === $row->isPinned() && in_array($row->value, $stored, true),
            ));
        }
    }

    /**
     * Stores the choice as clean strings in the sidebar's order — see
     * $sidebarMoreRows. An empty list is stored as an empty list, never as a
     * cleared key.
     *
     * @param list<SidebarRow> $rows
     */
    public function setSidebarMoreRows(array $rows): static
    {
        $values = array_map(
            static fn (SidebarRow $row): string => $row->value,
            array_values(array_filter(
                SidebarRow::cases(),
                static fn (SidebarRow $row): bool => false === $row->isPinned() && in_array($row, $rows, true),
            )),
        );

        return $this->setSetting(self::SETTING_SIDEBAR_MORE, $values);
    }

    /**
     * Virtual, out of the settings bag — see SETTING_COMPOSE_ACTION_ORDER.
     *
     * @var list<ComposeAction>
     */
    public array $composeActionOrder {
        get => ComposeAction::ordered($this->getSetting(self::SETTING_COMPOSE_ACTION_ORDER));
    }

    /**
     * Store an order, or forget it: null, or the default order itself, clears
     * the key, so somebody who arranged the bar back to how it came follows
     * the default from then on.
     *
     * @param list<ComposeAction>|null $order
     */
    public function setComposeActionOrder(?array $order): static
    {
        $whole = null === $order ? ComposeAction::cases() : ComposeAction::ordered(array_map(
            static fn (ComposeAction $action): string => $action->value,
            $order,
        ));

        if ($whole === ComposeAction::cases()) {
            unset($this->settings[self::SETTING_COMPOSE_ACTION_ORDER]);

            return $this;
        }

        return $this->setSetting(self::SETTING_COMPOSE_ACTION_ORDER, array_map(
            static fn (ComposeAction $action): string => $action->value,
            $whole,
        ));
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
        if (false === $this->accounts->contains($account)) {
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
