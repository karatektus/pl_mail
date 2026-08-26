<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\AiCallTiming;
use App\Domain\Enum\Ai\AiCallFeature;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One row per model call, and nothing about what was in it.
 *
 * WHAT IS NOT RECORDED IS THE POINT
 * ─────────────────────────────────
 * No prompt, no completion, no message id, no subject, no address. A table
 * that recorded what was asked would be a second copy of the mailbox, kept
 * outside every retention rule the mail itself obeys, on an installation whose
 * whole argument for a local model is that nothing leaves the building. The
 * error column takes a category from a closed set for the same reason: an HTTP
 * client's exception message routinely quotes the request body back, and the
 * request body is somebody's mail. Exception messages keep going to the
 * logger, where they already go, and never here.
 *
 * DBAL, NOT THE ORM, AND DELIBERATELY NO ENTITY
 * ─────────────────────────────────────────────
 * Following EmbeddingStore, and for a sharper reason than symmetry.
 * BackfillEmbeddingsHandler calls EntityManager::clear() after every chunk of
 * fifty and then dispatches the next one. An unflushed ORM row would be
 * detached by that clear and silently dropped — fifty at a time, for as long
 * as a backfill runs, which is exactly the window the panel exists to show.
 * Flushing here instead would be worse: it would push out whatever
 * half-processed Message state the handler happened to be holding, on the
 * schedule of a metrics write.
 *
 * So: one autocommit INSERT that owns nothing and joins nothing.
 *
 * NOTHING HERE MAY FAIL THE CALLER
 * ────────────────────────────────
 * A missing table on a container that came up before the migration lock
 * released, a connection that has gone away inside a long worker — none of it
 * is a reason for somebody's reply to fail to be drafted. Every throwable is
 * caught and logged at warning, and the caller is never told.
 */
final readonly class AiCallRecorder
{
    public function __construct(
        private Connection      $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The feature is REQUIRED and has no default.
     *
     * A new call site cannot record as "unknown" and cannot silently inherit
     * somebody else's tag: it has to say what it is for, at the point where
     * that is still known.
     */
    public function record(
        AiCallFeature $feature,
        string        $model,
        bool          $succeeded,
        ?string       $errorKind,
        AiCallTiming  $timing,
    ): void {
        try {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO ai_call_metric (
                        feature, model,
                        prompt_tokens, prompt_duration_ns,
                        eval_tokens, eval_duration_ns,
                        load_duration_ns, total_duration_ns,
                        succeeded, error_kind, created_at
                    ) VALUES (
                        :feature, :model,
                        :promptTokens, :promptDurationNs,
                        :evalTokens, :evalDurationNs,
                        :loadDurationNs, :totalDurationNs,
                        :succeeded, :errorKind, NOW()
                    )
                SQL,
                [
                    'feature'          => $feature->value,
                    // Truncated rather than refused: a model tag longer than
                    // the column is a strange name, not a reason to lose the
                    // measurement that goes with it.
                    'model'            => mb_substr($model, 0, 128),
                    'promptTokens'     => $timing->promptTokens,
                    'promptDurationNs' => $timing->promptDurationNs,
                    'evalTokens'       => $timing->evalTokens,
                    'evalDurationNs'   => $timing->evalDurationNs,
                    'loadDurationNs'   => $timing->loadDurationNs,
                    'totalDurationNs'  => $timing->totalDurationNs,
                    'succeeded'        => $succeeded,
                    'errorKind'        => $errorKind,
                ],
                [
                    'succeeded' => ParameterType::BOOLEAN,
                ],
            );
        } catch (Throwable $exception) {
            // Warning, not error: the user-facing operation succeeded or failed
            // on its own merits and this changes neither. It is worth a line
            // because a panel that is quietly empty is worse than one that is
            // loudly broken.
            $this->logger->warning('AiCallRecorder: could not record a model call', [
                'feature'   => $feature->value,
                'exception' => $exception,
            ]);
        }
    }
}
