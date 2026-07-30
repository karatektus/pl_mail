<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Integration\Capability;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Infrastructure\Messaging\Message\UploadAttachmentsMessage;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Integration\IntegrationDriverRegistry;
use App\Service\Mail\AttachmentResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Uploads a message's attachments to a service, for the saveToIntegration
 * rule action.
 *
 * Failure policy: never fatal, never retried into a loop. A revoked app
 * password or a full disk at the other end would otherwise have every matching
 * message fail three times and land in the failed transport, which buries real
 * problems under noise. The error is recorded on the Integration — the same
 * field the settings list reads — so the user sees one clear "this connection
 * stopped working" instead of a queue full of dead envelopes.
 *
 * Inline parts are skipped: they are the images in the body, not the files the
 * user thinks of as attachments, and saving a mail signature logo to someone's
 * Nextcloud on every matching message would be a nuisance rather than a
 * feature.
 */
#[AsMessageHandler]
final readonly class UploadAttachmentsHandler
{
    public function __construct(
        private MessageRepository         $messageRepository,
        private IntegrationRepository     $integrationRepository,
        private IntegrationDriverRegistry $drivers,
        private AttachmentResolver        $attachmentResolver,
        private EntityManagerInterface    $em,
        private LoggerInterface           $logger,
    ) {
    }

    public function __invoke(UploadAttachmentsMessage $message): void
    {
        $mail = $this->messageRepository->find($message->messageId);
        $integration = $this->integrationRepository->find($message->integrationId);

        if (null === $mail || null === $integration) {
            // The message or the connection went away between dispatch and
            // now. Nothing to do, and nothing worth alarming about.
            return;
        }

        if (false === $integration->supports(Capability::Upload)) {
            return;
        }

        $parts = $this->attachments($mail);

        if ([] === $parts) {
            return;
        }

        $folder = $message->folder ?? $integration->getSetting('upload.folder');
        $uploaded = 0;

        foreach ($parts as $part) {
            if (true === $this->upload($integration, $part, $folder)) {
                ++$uploaded;
            }
        }

        if ($uploaded > 0) {
            $integration->recordSuccess();
        }

        $this->em->flush();

        $this->logger->info('UploadAttachmentsHandler: finished', [
            'messageId'     => $message->messageId,
            'integrationId' => $message->integrationId,
            'uploaded'      => $uploaded,
            'total'         => count($parts),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function upload(Integration $integration, MessagePart $part, ?string $folder): bool
    {
        $filename = (string) ($part->getFilename() ?: 'attachment');

        try {
            $this->drivers->forIntegration($integration)->upload(
                $integration,
                $this->attachmentResolver->absolutePathFor($part),
                $filename,
                (string) ($part->getContentType() ?: 'application/octet-stream'),
                $folder,
            );

            return true;
        } catch (IntegrationException $e) {
            // The connection itself is at fault, so say so where the user will
            // look for it.
            $integration->recordFailure($e->getMessage());

            $this->logger->warning('UploadAttachmentsHandler: upload rejected', [
                'partId'        => $part->getId(),
                'integrationId' => $integration->id,
                'reason'        => $e->getMessage(),
            ]);

            return false;
        } catch (\Throwable $e) {
            // Materialising the original failed, or something else went wrong
            // locally. Not the integration's fault, so its health is left
            // alone — marking it broken would send the user to fix the wrong
            // thing.
            $this->logger->warning('UploadAttachmentsHandler: could not read attachment', [
                'partId' => $part->getId(),
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<MessagePart>
     */
    private function attachments(Message $mail): array
    {
        $parts = [];

        foreach ($mail->getMessageParts() as $part) {
            if (false === (bool) $part->isInline()) {
                $parts[] = $part;
            }
        }

        return $parts;
    }
}
