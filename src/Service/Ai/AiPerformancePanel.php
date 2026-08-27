<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiProbe;
use App\Domain\DTO\Ai\BackfillProgress;
use App\Domain\DTO\Ai\LoadedModel;
use App\Domain\Enum\Ai\MetricWindow;
use App\Repository\Ai\AiCallMetricRepository;
use App\Repository\Ai\AiSettingsRepository;
use DateTimeImmutable;

/**
 * One reading of the model host, assembled for the admin panel.
 *
 * WHY THIS IS SERVER-SIDE AND NOT A FETCH FROM THE BROWSER
 * ────────────────────────────────────────────────────────
 * The browser cannot reach the model host and must not be taught to. It is a
 * different origin, it is usually an address only this server can route to, and
 * `connect-src 'self'` refuses it in production anyway. So the panel asks this
 * application, and this application asks the host.
 *
 * SHORT TIMEOUTS, BECAUSE THIS IS POLLED
 * ──────────────────────────────────────
 * A host that has been switched off does not refuse the connection — it says
 * nothing at all, and the socket sits open until it times out. With
 * OllamaClient's five-second default that is ten seconds an admin page spends
 * hanging before it can render the words "not reachable", every five seconds,
 * for as long as somebody leaves the page open. The numbers below are chosen so
 * a dead host is REPORTED faster than the poll interval; a live host on a LAN
 * answers both in single-digit milliseconds and never notices them.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * ────────────────────────────────
 * It never sends a prompt, never loads a model, and never asks anything that
 * would reset a keep-alive. /api/tags and /api/ps both report; polling them
 * costs the host nothing. A panel that warmed the model in order to describe it
 * would be measuring itself.
 */
final readonly class AiPerformancePanel
{
    /**
     * The reachability check's budget. Two and a half seconds: long enough for
     * a busy host on a slow LAN, short enough that a dead one is reported
     * before the next poll leaves.
     */
    private const float PROBE_TIMEOUT = 2.5;

    /** The residency read's budget, tighter because it is the one that polls. */
    private const float PS_TIMEOUT = 1.5;

    /** How far back to look for what a cold load costs on this hardware. */
    private const string COLD_LOAD_LOOKBACK = '-30 days';

    public function __construct(
        private AiSettingsRepository   $settings,
        private OllamaClient           $client,
        private AiCallMetricRepository $metrics,
        private EmbeddingBackfill      $backfill,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(MetricWindow $window): array
    {
        $settings = $this->settings->currentOrDefault();
        $now      = new DateTimeImmutable();

        $configured = $settings->isConfigured();
        $ready      = $settings->isEnabled && $configured;

        // Nothing is asked of the host while the master switch is off. Off is a
        // valid configuration, not a fault, and a panel that kept probing would
        // be reporting on a feature nobody has switched on.
        $probe = $ready ? $this->client->probe((string) $settings->baseUrl, self::PROBE_TIMEOUT) : null;

        $loaded = null !== $probe && true === $probe->reachable
            ? $this->client->ps((string) $settings->baseUrl, self::PS_TIMEOUT)
            : [];

        $features = $this->metrics->perFeatureSince($window->since($now));
        $byModel  = $this->metrics->perModelSince($window->since($now));

        return [
            'enabled'    => $settings->isEnabled,
            'configured' => $configured,
            'ready'      => $ready,
            'features'   => [
                'search'     => $settings->searchEnabled,
                'categorise' => $settings->categorisationEnabled,
                'writing'    => $settings->writingHelpEnabled,
                'summary'    => $settings->summaryEnabled,
            ],
            'host' => [
                'baseUrl'   => $settings->baseUrl,
                'reachable' => $probe->reachable ?? false,
                'version'   => $probe?->version,
                'reason'    => $probe?->reason,
            ],
            'models'      => $this->models($settings->chatModel, $settings->embeddingModel, $probe, $loaded),
            'loaded'      => array_map(static fn (LoadedModel $model): array => self::describe($model, $now), $loaded),
            'anyPartial'  => self::anyPartial($loaded),
            'latest'      => $this->metrics->latest(),
            'window'      => $window->value,
            'perFeature'  => self::withShare($features),
            'perModel'    => self::withShare($byModel),
            'hasHistory'  => [] !== $features,
            // Asked for ONLY when the answer is going to be shown — the panel
            // says what a cold load costs in the state where nothing is
            // resident, and nowhere else. It is a percentile over a month of
            // rows, and during a backfill that month holds a row per embedded
            // message; running it on every five-second poll would make the
            // panel the most expensive thing on the installation.
            'coldLoadMs'  => $ready && [] === $loaded
                ? $this->metrics->typicalColdLoadMs($now->modify(self::COLD_LOAD_LOOKBACK))
                : null,
            'backfill'    => $this->backfill->progress(),
            'checkedAt'   => $now->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * The same reading, in a shape json_encode will not mangle.
     *
     * The template gets objects because Twig reads them comfortably; the
     * payload cannot — a BackfillProgress would encode as its public properties
     * with a DateTimeImmutable rendered as a three-key object with a timezone
     * in it. One conversion, here, rather than at every call site.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    public function payload(array $snapshot): array
    {
        $backfill = $snapshot['backfill'] ?? null;

        $snapshot['backfill'] = $backfill instanceof BackfillProgress ? $backfill->toArray() : null;

        return $snapshot;
    }

    /**
     * The two configured models, and whether the host actually has them.
     *
     * The commonest real failure this panel catches, and it is invisible from
     * everywhere else: a model name typed into settings that nobody ever
     * pulled. The feature is switched on, the configuration looks complete, and
     * every request 404s forever.
     *
     * `installed` is null when nothing could be asked — an unreachable host
     * says nothing about which models it holds, and painting "missing" over
     * that would send an operator to pull a model they already have.
     *
     * @param list<LoadedModel> $loaded
     *
     * @return list<array<string, mixed>>
     */
    private function models(?string $chat, ?string $embedding, ?AiProbe $probe, array $loaded): array
    {
        $rows = [];

        foreach ([['chat', $chat], ['embedding', $embedding]] as [$role, $name]) {
            $named = null !== $name && '' !== trim($name);

            $rows[] = [
                'role'      => $role,
                'name'      => $named ? trim((string) $name) : null,
                'installed' => $named && null !== $probe && true === $probe->reachable
                    ? $probe->hasModel(trim((string) $name))
                    : null,
                'resident'  => $named && self::isResident(trim((string) $name), $loaded),
            ];
        }

        return $rows;
    }

    /**
     * Is this configured name one of the models in memory?
     *
     * The same tag-tolerant comparison AiProbe::hasModel() makes, and for the
     * same reason: /api/ps answers "llama3.1:8b" for a setting that says
     * "llama3.1", and a strict comparison would report a resident model as cold
     * on exactly the installations that are configured the way people configure
     * them.
     *
     * @param list<LoadedModel> $loaded
     */
    private static function isResident(string $name, array $loaded): bool
    {
        foreach ($loaded as $model) {
            if ($model->name === $name || $name === explode(':', $model->name)[0]) {
                return true;
            }
        }

        return false;
    }

    /** @param list<LoadedModel> $loaded */
    private static function anyPartial(array $loaded): bool
    {
        foreach ($loaded as $model) {
            if (false === $model->fullyOnGpu()) {
                return true;
            }
        }

        return false;
    }

    /**
     * One resident model, in the words a panel can print.
     *
     * The byte counts are turned into labels here rather than in the template,
     * because the JSON payload wants the same words: "20.3 GiB resident, fully
     * on the GPU" is the sentence, and two places computing it would eventually
     * disagree about what a gigabyte is.
     *
     * @return array<string, mixed>
     */
    private static function describe(LoadedModel $model, DateTimeImmutable $now): array
    {
        $fraction = $model->gpuFraction();
        $onCpu    = null === $model->sizeBytes || null === $model->sizeVramBytes
            ? null
            : max(0, $model->sizeBytes - $model->sizeVramBytes);

        return [
            'name'            => $model->name,
            'sizeBytes'       => $model->sizeBytes,
            'sizeLabel'       => self::bytes($model->sizeBytes),
            'vramBytes'       => $model->sizeVramBytes,
            'vramLabel'       => self::bytes($model->sizeVramBytes),
            'cpuLabel'        => self::bytes($onCpu),
            'fullyOnGpu'      => $model->fullyOnGpu(),
            // Rounded DOWN, so a model that is 99.6% on the GPU does not read
            // as 100% beside a warning saying it is not.
            'gpuPercent'      => null === $fraction ? null : (int) floor($fraction * 100),
            'unloadsIn'       => $model->secondsUntilUnload($now),
            'parameterSize'   => $model->parameterSize,
            'quantisation'    => $model->quantisation,
            'contextLength'   => $model->contextLength,
        ];
    }

    /**
     * Each row's share of the busiest one, for the bars.
     *
     * A share rather than a count, because the bar is drawn as a percentage
     * width and the template should not be doing arithmetic to find the
     * maximum. No chart library is involved and none is wanted: four rows and a
     * div each.
     *
     * @param list<array{bucket: string, calls: int, errors: int, coldLoads: int,
     *     genTokensPerSecondP50: float|null, genTokensPerSecondP95: float|null,
     *     promptTokensPerSecondP50: float|null, loadMsP50: float|null}> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function withShare(array $rows): array
    {
        $busiest = 0;

        foreach ($rows as $row) {
            $busiest = max($busiest, $row['calls']);
        }

        $out = [];

        foreach ($rows as $row) {
            $out[] = [...$row, 'share' => $busiest > 0 ? (int) round($row['calls'] / $busiest * 100) : 0];
        }

        return $out;
    }

    /**
     * Bytes as a person reads them.
     *
     * Binary units, because that is what Ollama reports and what every tool an
     * operator would compare this against prints. One decimal: "20.3 GiB" is
     * the number people quote, "20 GiB" loses the difference between a model
     * that fits and one that does not.
     */
    private static function bytes(?int $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $size  = (float) $value;
        $unit  = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            ++$unit;
        }

        return sprintf($unit < 2 ? '%d %s' : '%.1f %s', $size, $units[$unit]);
    }
}
