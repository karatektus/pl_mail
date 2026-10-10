<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Middleware;

use App\Infrastructure\Messaging\Message\BackfillEmbeddingsMessage;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Infrastructure\Messaging\Message\SummariseThreadMessage;
use App\Infrastructure\Messaging\Message\EmbedMessagesMessage;
use App\Infrastructure\Messaging\Stamp\AiTaskStamp;
use App\Service\Ai\AiTaskContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Uid\Uuid;

/** Persist the task stamp before transport serialization; retries retain it. */
final readonly class AiTaskMiddleware implements MiddlewareInterface
{
    public function __construct(private AiTaskContext $context) {}
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if (!$message instanceof ClassifyMailMessage && !$message instanceof EmbedMessagesMessage && !$message instanceof BackfillEmbeddingsMessage && !$message instanceof SummariseThreadMessage) {
            return $stack->next()->handle($envelope, $stack);
        }
        $stamp = $envelope->last(AiTaskStamp::class);
        if (null === $stamp) {
            // Old queued messages have no correlation stamp. Their delivery ID
            // remains stable after a worker crash; persist the derived stamp
            // when Messenger requeues them under a new delivery ID.
            $legacy = $envelope->last(\Symfony\Component\Messenger\Stamp\TransportMessageIdStamp::class)?->getId();
            $id = $message instanceof BackfillEmbeddingsMessage && null !== $message->runId
                ? AiTaskContext::forRun($message->runId)
                : (null !== $legacy ? $this->context->forLegacyDelivery((string) $legacy) : Uuid::v4()->toRfc4122());
            $stamp = new AiTaskStamp($id); $envelope = $envelope->with($stamp);
        }
        $old = $this->context->current; $this->context->current = $stamp->id;
        try { return $stack->next()->handle($envelope, $stack); }
        finally { $this->context->current = $old; }
    }
}
