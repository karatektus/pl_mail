<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Mail\PostIngestResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something that wants to react to newly ingested mail, once, for the whole
 * batch, from any of the three sync paths.
 *
 * Implementations are auto-tagged app.post_ingest_step and run at the very end
 * of PostIngestPipeline. Adding one is writing a class — nothing else in the
 * application changes, and in particular no sync path is touched.
 *
 * Three rules the pipeline enforces, and the reasons they are rules:
 *
 *   There is exactly one hook and it runs AFTER the final flush. A step never
 *   sees a half-built batch. MailRuleEngine matches in SQL against rows that
 *   have been flushed, so anything mutated mid-pass is invisible to it — a hook
 *   placed inside the loop would let a step change a message the rule engine
 *   then evaluates from stale database state, and mail would be filed
 *   somewhere the user's rules do not explain.
 *
 *   Steps dispatch, they do not work. afterCommit() should queue a Messenger
 *   job and return. It runs inside a sync batch, on the worker holding an IMAP
 *   connection or a Graph rate-limit budget; a parse, an HTTP call or an image
 *   decode belongs in its own handler. ApplyMailRuleHandler is the shape to
 *   copy — a job taking a list of message ids.
 *
 *   Steps cannot fail a sync. The pipeline catches and logs whatever a step
 *   throws, and carries on with the next one. A broken step costs whatever it
 *   was going to do, never the mail.
 */
#[AutoconfigureTag('app.post_ingest_step')]
interface PostIngestStepInterface
{
    public function afterCommit(PostIngestResult $result): void;
}
