<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\DTO\Backup\ConfigBackupPlan;
use App\Domain\DTO\Backup\ConfigBackupPlanItem;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Backup\ConfigBackupChange;
use App\Domain\Enum\Backup\ConfigBackupDisposition;
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
 * the same list plan() produced and writes exactly the items it marked as
 * written; it does not re-decide anything. A review that says one thing and an
 * apply that does another is the specific failure this shape exists to make
 * impossible.
 *
 * **Uploading the file is meant to be the whole job**, and on the supported
 * deployment it is. What this writes:
 *
 *   - the three configuration tables, which plMail owns outright, and whose
 *     credentials are re-encrypted with THIS instance's key on the way in —
 *     the whole reason the envelope carries them decrypted. Live on commit;
 *   - the JWT keypair and anything else under the shared secrets directory,
 *     when this process can actually write it, measured at review time;
 *   - **the environment values, into `var/secrets/generated.env`** — the file
 *     `frankenphp/generate-secrets.sh` mints on first run and
 *     `frankenphp/docker-entrypoint.sh` loads before it execs the server. This
 *     used to be refused on the grounds that a PHP process cannot change its
 *     own environment, which is true and beside the point: nobody hand-edits
 *     these on a plMail install, the generated file is where they live, every
 *     service mounts the volume it is on, and the app can write it. What a
 *     restore owes the operator is not two dozen lines to paste but the values
 *     in place and one sentence: restart the stack.
 *
 * Three fates remain that a restore cannot simply produce, and each is a
 * property of the deployment rather than a caution — see
 * {@see ConfigBackupDisposition}: APP_ENCRYPTION_KEY is kept on purpose,
 * POSTGRES_PASSWORD belongs to a role inside a database plMail is only a client
 * of, and a name pinned non-empty in the process environment beats the
 * generated file at the next start by the entrypoint's own rule.
 *
 * **A failed write demotes rather than aborts.** If a path that looked writable
 * turns out not to be — a race, a full disk — those items come back as
 * `NotWritable` in the plan, with the instruction they would have had. An
 * import that threw here would leave the operator with a committed database and
 * no account of what happened to the rest.
 *
 * **Order: database, then files, then the environment file.** The database is
 * the only transactional part and the only one that can be refused by its own
 * contents (FcmConfig::restore() rejects a mismatched Firebase project), so it
 * goes first and takes everything down with it if it fails. The two file writes
 * are not transactional, and pretending otherwise would only move the failure.
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
     * Execute the plan and report what happened.
     *
     * The database writes go inside one transaction, so a document whose
     * Firebase key is refused by FcmConfig::restore() does not leave three
     * provider registrations from someone else's install behind — the review
     * showed one operation and it stays one operation. The two file halves are
     * written after the commit, because they are not transactional and
     * pretending otherwise would only move the failure.
     *
     * The returned plan is the *receipt*: the items come back re-stated with
     * what actually became of them, which for a write that failed is not what
     * the review promised. That is the one place a plan and its execution are
     * allowed to differ, and it differs in the direction of the truth.
     *
     * @param array<string, mixed> $document
     */
    public function apply(array $document): ConfigBackupPlan
    {
        $plan = $this->plan($document);

        $this->entityManager->wrapInTransaction(function () use ($plan, $document): void {
            $this->applyDatabase($plan, $this->section($document, 'database'));
        });

        $items = $this->applyFiles($plan, $this->section($document, 'files'));
        $items = $this->applyEnvironment($items, $this->section($document, 'env'));

        return new ConfigBackupPlan($items, $plan->instance, $plan->exportedAt, applied: true);
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

            $disposition = $this->environment->dispositionFor($name);

            $items[] = new ConfigBackupPlanItem(
                ConfigBackupSection::Environment,
                $name,
                $this->changeFor($this->environment->current($name), $value),
                $disposition,
                // Only where there is somewhere for the operator to put it. A
                // line to paste beside a value plMail has already written is
                // the instruction wall this rework exists to remove — and it is
                // worse than noise, because it reads as an unfinished job.
                $this->instructionIsUseful($disposition)
                    ? $this->environment->instructionFor($name, $value)
                    : null,
            );
        }

        return $items;
    }

    /**
     * Whether a pasteable line is worth showing beside an item.
     *
     * Yes for the three fates with somewhere to go — a compose file to edit, an
     * external system to change, a path this process could not write — and for
     * the one note, APP_ENCRYPTION_KEY, which an operator restoring the old
     * *database* alongside this backup does genuinely need in their hand.
     */
    private function instructionIsUseful(ConfigBackupDisposition $disposition): bool
    {
        return $disposition->needsOperator() || $disposition->isNote();
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

            $disposition = $this->files->dispositionFor($name);

            $items[] = new ConfigBackupPlanItem(
                ConfigBackupSection::SecretsFile,
                $name,
                $this->changeFor($this->files->read($name), $contents),
                $disposition,
                // The path on THIS instance, which is the only part of a file a
                // review page can usefully hand over — the bytes are already in
                // the backup the operator is holding.
                $this->instructionIsUseful($disposition) ? $this->files->pathFor($name) : null,
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
                ConfigBackupDisposition::Applied,
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
                    ConfigBackupDisposition::Applied,
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
        foreach ($plan->items as $item) {
            if (ConfigBackupSection::Database !== $item->section
                || false === $item->isWritten()
                || false === $item->change->isMaterial()
            ) {
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
                || false === $item->isWritten()
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
                    ConfigBackupDisposition::NotWritable,
                    $this->files->pathFor($item->key),
                );
            }
        }

        return $items;
    }

    /**
     * Write the environment half into `var/secrets/generated.env` — the file
     * the entrypoint owns, reads at every start, and every service in the stack
     * mounts.
     *
     * **One write for the whole section**, not one per name. The file is read,
     * merged over and rewritten under a single exclusive lock — the same lock
     * `generate-secrets.sh` takes — so no other service can ever observe it
     * half-restored, and keys this backup says nothing about are left exactly
     * where they were. Twenty-four separate writes would be twenty-four
     * opportunities for a worker booting alongside to read a partial file.
     *
     * **Shadowed values are written anyway.** A name pinned in the process
     * environment will beat this at the next start, but the pin is the
     * operator's to remove, and the moment they remove it the restored value is
     * already there. Writing it costs nothing and not writing it would mean the
     * fix is two steps instead of one. It stays flagged either way.
     *
     * **A failure demotes the whole batch**, because the write is one
     * operation: there is no partial outcome to report. The items come back as
     * `NotWritable` carrying the lines to paste, which is precisely the old
     * behaviour — now the fallback rather than the design.
     *
     * @param list<ConfigBackupPlanItem> $items
     * @param array<string, mixed>       $env
     *
     * @return list<ConfigBackupPlanItem>
     */
    private function applyEnvironment(array $items, array $env): array
    {
        $values = [];

        foreach ($items as $item) {
            if (ConfigBackupSection::Environment !== $item->section
                || false === $item->isWritten()
                || false === $item->change->isMaterial()
            ) {
                continue;
            }

            $value = $env[$item->key] ?? null;

            if (is_string($value) && '' !== $value) {
                $values[$item->key] = $value;
            }
        }

        if ([] === $values) {
            return $items;
        }

        try {
            $this->environment->apply($values);

            return $items;
        } catch (Throwable $e) {
            $this->logger->warning('Could not restore environment values from a config backup.', [
                'names'     => array_keys($values),
                'exception' => $e,
            ]);
        }

        return array_values(array_map(
            function (ConfigBackupPlanItem $item) use ($values, $env): ConfigBackupPlanItem {
                if (false === array_key_exists($item->key, $values) || ConfigBackupSection::Environment !== $item->section) {
                    return $item;
                }

                $value = $env[$item->key] ?? '';

                return new ConfigBackupPlanItem(
                    $item->section,
                    $item->key,
                    $item->change,
                    ConfigBackupDisposition::NotWritable,
                    $this->environment->instructionFor($item->key, is_string($value) ? $value : ''),
                );
            },
            $items,
        ));
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
