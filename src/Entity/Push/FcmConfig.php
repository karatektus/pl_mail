<?php

declare(strict_types=1);

namespace App\Entity\Push;

use App\Domain\DTO\Push\ClientConfig;
use App\Domain\DTO\Push\ServiceAccount;
use App\Domain\Exception\InvalidFirebaseCredentialsException;
use App\Domain\Trait\TimestampableTrait;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\Push\FcmConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The Firebase project this installation pushes through, owned by admins.
 *
 * **In the database rather than in env vars**, and that is the whole reason
 * this table exists. The VAPID keys next to it are env vars because they are
 * generated once by `app:push:generate-vapid-keys` and never touched again; a
 * Firebase service-account key is pasted out of a console by whoever runs the
 * install, is rotated when it leaks, and belongs to a project that may not
 * exist when the container is built. Requiring a container restart to enable
 * Android push would put the setup step behind the deployment mechanism, which
 * on a Raspberry Pi someone else administers is where features go to die. The
 * same call IntegrationProviderConfig made for OAuth credentials.
 *
 * **One row, and the schema says so.** `singleton` is a constant 1 with a
 * unique index over it: there is one Firebase project per installation, and a
 * second row would make "which credentials are in force?" depend on insertion
 * order. A repository method returning `findOneBy([])` would have been the
 * documented version of the same rule, and a documented rule is the one that
 * gets broken by the next writer.
 *
 * **Two files, and the row is only usable with both.** The service-account key
 * is what the server sends with; the google-services.json is what the Android
 * app initialises Firebase FROM, because plMail ships one APK from the Play
 * Store and every install has its own project, so the client config cannot be
 * baked in and is published in the Session instead. A row holding one of the
 * two is a half-finished setup: the server could send to devices that can never
 * register, or the devices could register with a project the server cannot
 * reach. isActive() therefore requires both, and the Session says `fcm: false`
 * until it has them.
 *
 * **And the two must name the same project.** Nothing downstream can detect a
 * mismatch: the app registers happily against project A, the server sends
 * happily to project B, every message is answered SENDER_ID_MISMATCH and the
 * user simply never gets a notification. So it is refused at the point of
 * paste, which is the only place both halves are in one person's hands.
 *
 * The service-account key is encrypted at rest through EncryptedStringType, the
 * same facility mail passwords and OAuth client secrets use. It is a credential
 * that can send a notification to every device that ever registered, so a
 * readable copy in a backup is a readable copy forever.
 *
 * Everything else is stored in clear, and that is a decision rather than an
 * omission. projectId and clientEmail appear in every URL and header the sender
 * builds; applicationId, apiKey and senderId ship inside every Firebase app's
 * APK and are readable by anyone who unzips one. Encrypting values that are
 * published to every authenticated client in the Session would suggest a
 * guarantee that does not exist, and it is what lets the admin page say which
 * project is configured, and a diagnostic read it, without the encryption key.
 */
#[ORM\Entity(repositoryClass: FcmConfigRepository::class)]
#[ORM\Table(name: 'fcm_config')]
// The singleton guarantee. One column, one value, one row — the index is the
// only thing that can hold it, because every check performed in PHP happens
// before the insert and therefore before the other request's insert.
#[ORM\UniqueConstraint(name: 'uniq_fcm_config_singleton', columns: ['singleton'])]
#[ORM\HasLifecycleCallbacks]
class FcmConfig
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Always 1. Never read, never written by anything but the constructor — it
     * exists so the unique index above has a column to be unique over.
     */
    #[ORM\Column(options: ['default' => 1])]
    public private(set) int $singleton = 1;

    /**
     * An admin can turn FCM off without throwing the credentials away, which is
     * the difference between "we are not using this" and "we have lost the key".
     */
    #[ORM\Column]
    public bool $isEnabled = false;

    #[ORM\Column(name: 'service_account_json', type: EncryptedStringType::NAME, nullable: true)]
    public private(set) ?string $serviceAccountJson = null;

    #[ORM\Column(name: 'project_id', length: 255, nullable: true)]
    public private(set) ?string $projectId = null;

    #[ORM\Column(name: 'client_email', length: 255, nullable: true)]
    public private(set) ?string $clientEmail = null;

    /** `mobilesdk_app_id` — FirebaseOptions.Builder::setApplicationId. */
    #[ORM\Column(name: 'application_id', length: 255, nullable: true)]
    public private(set) ?string $applicationId = null;

    /** The Android client's `current_key` — setApiKey. */
    #[ORM\Column(name: 'api_key', length: 255, nullable: true)]
    public private(set) ?string $apiKey = null;

    /** `project_number` — setGcmSenderId. */
    #[ORM\Column(name: 'sender_id', length: 64, nullable: true)]
    public private(set) ?string $senderId = null;

    /**
     * Which registered package the three values above were taken from.
     *
     * Never published and never read by the sender — it is here so the admin
     * page can show which of a multi-client google-services.json won, because
     * "the app is not receiving anything" and "the file registered a debug
     * variant first" are otherwise indistinguishable from the outside.
     */
    #[ORM\Column(name: 'android_package', length: 255, nullable: true)]
    public private(set) ?string $androidPackage = null;

    /**
     * Store one or both files, having proved they are what they claim and that
     * they name the same project.
     *
     * **One method taking both**, rather than a setter each. The invariant is
     * about the pair, and two setters make it impossible to state: applying a
     * new service account first would compare it against the OLD client config
     * and refuse a perfectly consistent replacement of both, and applying them
     * in the other order would do the same in reverse. Null means "keep what is
     * stored", which is the write-only-credential rule the form relies on.
     *
     * Nothing is assigned until every check has passed, so a refused save
     * leaves the row exactly as it was rather than half-updated.
     *
     * @throws InvalidFirebaseCredentialsException naming what is missing, wrong
     *                                             or mismatched
     */
    public function useCredentials(?string $serviceAccountJson, ?string $googleServicesJson): void
    {
        $account = null === $serviceAccountJson ? null : ServiceAccount::fromJson($serviceAccountJson);
        $client  = null === $googleServicesJson ? null : ClientConfig::fromGoogleServicesJson($googleServicesJson);

        // The project each half will name once this save lands: the incoming
        // file's, or the stored one's when that half is being kept.
        $serviceProject = $account?->projectId ?? ($this->hasServiceAccount() ? $this->projectId : null);
        $clientProject  = $client?->projectId ?? ($this->hasClientConfig() ? $this->projectId : null);

        if (null !== $serviceProject && null !== $clientProject && $serviceProject !== $clientProject) {
            throw new InvalidFirebaseCredentialsException(sprintf(
                'These two files belong to different Firebase projects: the service-account key is for "%s" '
                . 'and the google-services.json is for "%s". Messages sent with one can never reach an app '
                . 'registered with the other. Download both from the same project.',
                $serviceProject,
                $clientProject,
            ));
        }

        if (null !== $account) {
            $this->serviceAccountJson = $serviceAccountJson;
            $this->clientEmail        = $account->clientEmail;
        }

        if (null !== $client) {
            $this->applicationId  = $client->applicationId;
            $this->apiKey         = $client->apiKey;
            $this->senderId       = $client->senderId;
            $this->androidPackage = $client->packageName;
        }

        // One column for both halves, because they have just been proved equal.
        $this->projectId = $serviceProject ?? $clientProject;
    }

    /**
     * Forget the service-account key, and stop — leaving isEnabled true with
     * nothing behind it would advertise `fcm: true` in the Session and refuse
     * every create.
     *
     * The client config stays. It is public, it is a nuisance to fetch again,
     * and an admin rotating a leaked key has no reason to re-paste it. projectId
     * therefore stays too, because the client half still names it.
     */
    public function forgetServiceAccount(): void
    {
        $this->serviceAccountJson = null;
        $this->clientEmail        = null;
        $this->isEnabled          = false;

        if (false === $this->hasClientConfig()) {
            $this->projectId = null;
        }
    }

    /**
     * Whether a credential is on file. Never renders the key itself — the form
     * shows that one exists, not what it is, the same rule
     * IntegrationProviderConfig::hasClientSecret() states.
     *
     * Stays a method: an existence check over a credential is not a read of it.
     */
    public function hasServiceAccount(): bool
    {
        return null !== $this->serviceAccountJson && '' !== $this->serviceAccountJson;
    }

    /**
     * Whether the values an Android client needs to initialise Firebase are on
     * file. All four move together, so applicationId answers for the set.
     */
    public function hasClientConfig(): bool
    {
        return null !== $this->applicationId && null !== $this->apiKey && null !== $this->senderId;
    }

    /**
     * Configured AND turned on — the single question the Session capability and
     * every refusal are asked, so it is answered in one place.
     *
     * Both halves, for the reason the class docblock gives: a server that can
     * send to devices that can never register is not a working install, it is
     * a silent one.
     *
     * Stays a method rather than a column: a stored "is it usable" flag would
     * be a fourth thing to keep in step with the three it is derived from.
     */
    public function isActive(): bool
    {
        return true === $this->isEnabled
            && true === $this->hasServiceAccount()
            && true === $this->hasClientConfig();
    }
}
