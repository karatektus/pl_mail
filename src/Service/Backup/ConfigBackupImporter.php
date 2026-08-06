<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\DTO\Backup\ConfigBackupPlan;
use App\Domain\DTO\Backup\ConfigBackupPlanItem;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Backup\ConfigBackupChange;
use App\Domain\Enum\Backup\ConfigBackupObstacle;
use App\Domain\Enum\Backup\ConfigBackupSection;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\ConfigBackupException;
use App\Infrastructure\Backup\ConfigBackupCipher;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Opening a config backup, saying honestly what it would do, and then doing the
 * part that is genuinely ours to do.
 *
 * **Three methods and a hard line between the second and the third.** open()
 * decrypts and validates and touches nothing. plan() compares and touches
 * nothing. apply() writes. The admin sees the output of plan() and has to press
 * a second button before apply() runs, because the interesting case is not the
 * empty install — it is the running one, where every line of the plan is a
 * credential about to be replaced by a different credential, and the only
 * moment anybody can notice that is before.
 *
 * **The plan is not advice, it is the thing that gets executed.** apply() walks
 * the same list plan() produced and does exactly its automatic items; it does
 * not re-decide anything. A review that says one thing and an apply that does
 * another is the specific failure this shape exists to make impossible.
 *
 * **What is honestly writable is a short list**, and it is short for reasons
 * that are properties of the deployment rather than of this code:
 *
 *   - the three configuration tables, which plMail owns outright, and whose
 *     credentials are re-encrypted with THIS instance's key on the way in —
 *     the whole reason the envelope carries them decrypted;
 *   - files under the shared secrets directory, and only when this process can
 *     actually write them, measured at review time;
 *   - nothing in the environment, ever. A PHP process cannot change the
 *     variables it was started with, and quietly writing them into a file the
 *     entrypoint reads at the NEXT container start is not what an admin who
 *     pressed "apply" was told would happen. Those come back as exact lines to
 *     paste, which is the only truthful thing to offer.
 *
 * **A failed write demotes rather than aborts.** If a file that looked writable
 * turns out not to be — a race, a full disk — that item is reported as manual
 * in the plan that comes back, with the same instruction it would have had. An
 * import that threw here would leave the admin with a committed database and no
 * account of what happened to the rest.
 */
final readonly class ConfigBackupImporter
{
    public function __construct(
        private ConfigBackupCipher      $cipher,
        private ConfigBackupEnvironment $environment,
        private ConfigBackupFiles       $files,
        private ConfigBackupDatabase    $database,
        private EntityManagerInterface  $entityManager,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * Decrypt, then prove the contents are a document this build understands.
     *
     * @return array<string, mixed>
     *
     * @throws ConfigBackupException
     */
    public function open(string $envelope, #[SensitiveParameter] string $password): array
    {
        $document = $this->cipher->open($envelope, $password);

        // The envelope already said this, and it says it again from inside:
        // the outer claim is unauthenticated — anyone can write a header — and
        // the inner one has come through a Poly1305 tag.
        if (ConfigBackupCipher::FORMAT !== ($document['format'] ?? null)) {
            throw ConfigBackupException::malformed('The decrypted document does not identify itself as a plMail config backup.');
        }

        $version = $document['version'] ?? null;

        if (false === is_int($version)) {
            throw ConfigBackupException::malformed('The decrypted document has no numeric version.');
        }

        if ($version > ConfigBackupExporter::DOCUMENT_VERSION) {
            throw ConfigBackupException::unsupportedVersion($version, ConfigBackupExporter::DOCUMENT_VERSION);
        }

        return $document;
    }

    /**
     * What this backup would do to this instance, with nothing done.
     *
     * @param array<string, mixed> $document
     */
    public function plan(array $document): ConfigBackupPlan
    {
        $items = [
            ...$this->environmentItems($this->section($document, 'env')),
            ...$this->fileItems($this->section($document, 'files')),
            ...$this->databaseItems($this->section($document, 'database')),
        ];

        return new ConfigBackupPlan($items, $this->instance($document), $this->exportedAt($document));
    }

    /**
     * Execute the automatic half of the plan and report what happened.
     *
     * The database writes go inside one transaction, so a document whose
     * Firebase key is refused by FcmConfig::restore() does not leave three
     * provider registrations from someone else's install behind — the review
     * showed one operation and it stays one operation. Files are written after
     * the commit and individually, because they are not transactional and
     * pretending otherwise would only move the failure.
     *
     * @param array<string, mixed> $document
     */
    public function apply(array $document): ConfigBackupPlan
    {
        $plan = $this->plan($document);

        $this->entityManager->wrapInTransaction(function () use ($plan, $document): void {
            $this->applyDatabase($plan, $this->section($document, 'database'));
        });

        return new ConfigBackupPlan(
            $this->applyFiles($plan, $this->section($document, 'files')),
            $plan->instance,
            $plan->exportedAt,
            applied: true,
        );
    }

    // ── Private: planning ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $env
     *
     * @return list<ConfigBackupPlanItem>
     */
    private function environmentItems(array $env): array
    {
        $items = [];

        // Inventory order first, so the two variables whose mishandling costs
        // the most are the two an admin reads first; anything the file carries
        // that this build does not know about follows, in the file's own order.
        $names = [
            ...array_values(array_filter($this->environment->variables(), static fn (string $name): bool => array_key_exists($name, $env))),
            ...array_values(array_filter(array_keys($env), fn (string $name): bool => false === in_array($name, $this->environment->variables(), true))),
        ];

        foreach ($names as $name) {
            $value = $env[$name] ?? null;

            if (false === is_string($value) || '' === $value) {
                continue;
            }

            $items[] = new ConfigBackupPlanItem(
                ConfigBackupSection::Environment,
                $name,
                $this->changeFor($this->environment->current($name), $value),
                $this->environment->obstacleFor($name),
                $this->environment->instructionFor($name, $value),
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $files
     *
     * @return list<ConfigBackupPlanItem>
     */
    private function fileItems(array $files): array
    {
        $items = [];

        foreach ($this->files->names() as $name) {
            $encoded = $files[$name] ?? null;

            if (false === is_string($encoded)) {
                continue;
            }

            $contents = base64_decode($encoded, true);

            if (false === $contents) {
                throw ConfigBackupException::malformed(sprintf('The file "%s" is not valid base64.', $name));
            }

            $obstacle = $this->files->obstacleFor($name);

            $items[] = new ConfigBackupPlanItem(
                ConfigBackupSection::SecretsFile,
                $name,
                $this->changeFor($this->files->read($name), $contents),
                $obstacle,
                // The path on THIS instance, which is the only part of a file a
                // review page can usefully hand over — the bytes are already in
                // the backup the admin is holding.
                null === $obstacle ? null : $this->files->pathFor($name),
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $database
     *
     * @return list<ConfigBackupPlanItem>
     */
    private function databaseItems(array $database): array
    {
        $current = $this->database->current();
        $items   = [];

        $fcm        = $database[ConfigBackupDatabase::FCM_CONFIG] ?? null;
        $currentFcm = $current[ConfigBackupDatabase::FCM_CONFIG] ?? null;

        if (is_array($fcm)) {
            $items[] = new ConfigBackupPlanItem(
                ConfigBackupSection::Database,
                ConfigBackupDatabase::FCM_CONFIG,
                $this->changeForArray(is_array($currentFcm) ? $currentFcm : null, $fcm),
            );
        }

        // A provider this build has never heard of is skipped rather than
        // refused: a backup from a newer plMail must restore the parts this one
        // understands instead of failing whole. It is skipped SILENTLY only
        // because the review lists what will happen — an unlisted provider is
        // visibly not in the plan.
        foreach ([ConfigBackupDatabase::MAIL_PROVIDERS, ConfigBackupDatabase::INTEGRATION_PROVIDERS] as $table) {
            $live = $this->providerNames($current, $table);

            foreach ($this->providerNames($database, $table) as $key => $values) {
                if (false === $this->isKnownProvider($table, $key)) {
                    continue;
                }

                $items[] = new ConfigBackupPlanItem(
                    ConfigBackupSection::Database,
                    $table . '.' . $key,
                    $this->changeForArray($live[$key] ?? null, $values),
                );
            }
        }

        return $items;
    }

    // ── Private: applying ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $database
     */
    private function applyDatabase(ConfigBackupPlan $plan, array $database): void
    {
        foreach ($plan->automatic() as $item) {
            if (ConfigBackupSection::Database !== $item->section || false === $item->change->isMaterial()) {
                continue;
            }

            [$table, $key] = array_pad(explode('.', $item->key, 2), 2, '');

            if (ConfigBackupDatabase::FCM_CONFIG === $table) {
                $fcm = $database[ConfigBackupDatabase::FCM_CONFIG] ?? [];
                $this->database->restoreFcmConfig(is_array($fcm) ? $fcm : []);

                continue;
            }

            $values = $this->providerNames($database, (string) $table)[(string) $key] ?? [];

            if (ConfigBackupDatabase::MAIL_PROVIDERS === $table && null !== $provider = MailProvider::tryFrom((string) $key)) {
                $this->database->restoreMailProvider($provider, $values);
            }

            if (ConfigBackupDatabase::INTEGRATION_PROVIDERS === $table && null !== $integration = Provider::tryFrom((string) $key)) {
                $this->database->restoreIntegrationProvider($integration, $values);
            }
        }
    }

    /**
     * Whether this build has a driver for the provider a document names.
     *
     * Two enums and one question, so the planner and the applier cannot come to
     * different answers about the same key — which would show up as a review
     * promising a restore that silently does nothing.
     */
    private function isKnownProvider(string $table, string $key): bool
    {
        return match ($table) {
            ConfigBackupDatabase::MAIL_PROVIDERS        => null !== MailProvider::tryFrom($key),
            ConfigBackupDatabase::INTEGRATION_PROVIDERS => null !== Provider::tryFrom($key),
            default                                     => false,
        };
    }

    /**
     * @param array<string, mixed> $files
     *
     * @return list<ConfigBackupPlanItem>
     */
    private function applyFiles(ConfigBackupPlan $plan, array $files): array
    {
        $items = [];

        foreach ($plan->items as $item) {
            if (ConfigBackupSection::SecretsFile !== $item->section
                || false === $item->isAutomatic()
                || false === $item->change->isMaterial()
            ) {
                $items[] = $item;

                continue;
            }

            $encoded = $files[$item->key] ?? null;

            // Already proved decodable while planning; re-decoded rather than
            // carried on the item, because a plan is a description of what will
            // happen and putting a private key inside one would mean the review
            // page was holding it.
            $contents = is_string($encoded) ? base64_decode($encoded, true) : false;

            try {
                if (false === $contents) {
                    throw new RuntimeException('the file is not valid base64');
                }

                $this->files->write($item->key, $contents);

                $items[] = $item;
            } catch (Throwable $e) {
                // Demoted, not thrown: see the class docblock. The admin gets
                // the path and can put it there themselves, which is exactly
                // what they would have been told had is_writable said no.
                $this->logger->warning('Could not restore a secrets file from a config backup.', [
                    'file'      => $item->key,
                    'exception' => $e,
                ]);

                $items[] = new ConfigBackupPlanItem(
                    $item->section,
                    $item->key,
                    $item->change,
                    ConfigBackupObstacle::NotWritable,
                    $this->files->pathFor($item->key),
                );
            }
        }

        return $items;
    }

    // ── Private: reading the document ─────────────────────────────────────────

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function section(array $document, string $name): array
    {
        $section = $document[$name] ?? null;

        return is_array($section) ? $section : [];
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, array<string, mixed>>
     */
    private function providerNames(array $source, string $table): array
    {
        $rows   = $source[$table] ?? null;
        $result = [];

        if (false === is_array($rows)) {
            return $result;
        }

        foreach ($rows as $key => $values) {
            if (is_string($key) && is_array($values)) {
                $result[$key] = $values;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function instance(array $document): ?string
    {
        $instance = $document['instance'] ?? null;

        return is_string($instance) && '' !== $instance ? $instance : null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function exportedAt(array $document): ?DateTimeImmutable
    {
        $exportedAt = $document['exportedAt'] ?? null;

        if (false === is_string($exportedAt)) {
            return null;
        }

        // A date the file states and nothing depends on. An unparseable one is
        // shown as "unknown" rather than failing an import — the credentials
        // are still good, and refusing them over a timestamp would be absurd.
        try {
            return new DateTimeImmutable($exportedAt);
        } catch (Throwable) {
            return null;
        }
    }

    private function changeFor(?string $current, string $incoming): ConfigBackupChange
    {
        if (null === $current) {
            return ConfigBackupChange::Absent;
        }

        // hash_equals rather than ===, because half of these comparisons are
        // over credentials and a timing-variable compare over a secret is a
        // habit worth not having, even where the attacker would have to be
        // holding the file already.
        return hash_equals($current, $incoming) ? ConfigBackupChange::Unchanged : ConfigBackupChange::Differs;
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>      $incoming
     */
    private function changeForArray(?array $current, array $incoming): ConfigBackupChange
    {
        if (null === $current) {
            return ConfigBackupChange::Absent;
        }

        // Sorted by key before encoding: the two sides come from an ORM read
        // and from a JSON document, and their key order has no reason to agree.
        ksort($current);
        ksort($incoming);

        return json_encode($current) === json_encode($incoming)
            ? ConfigBackupChange::Unchanged
            : ConfigBackupChange::Differs;
    }
}
