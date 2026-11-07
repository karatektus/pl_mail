<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\ImageSize;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\DraftAttachmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What hangs off a draft: files beside the body, and images inside it.
 *
 * Split out of ComposeController, which is the one seam in that class that is
 * genuinely a seam. Everything else there — new, reply, forward, draft, send,
 * schedule, undo — is a single pipeline that builds the same ComposeType form
 * from the same request context and answers with the same window, so pulling
 * "sending" away from "composing" would only have moved nine shared private
 * helpers into a shared parent and called the result two classes. These three
 * actions share none of that: no form, no compose context, no window. They take
 * a draft and a file and answer with the attachment strip.
 *
 * They keep their own copy of the draft guard rather than inheriting one, and
 * that is the whole of what they need from the class they came from.
 */
#[Route('/compose', name: 'app_compose_')]
final class ComposeAttachmentController extends AbstractController
{
    use ChecksCsrf;

    /**
     * Per-file ceiling for compose attachments. Public because the compose
     * window reads it too, to refuse an oversized file before it is uploaded,
     * and the file picker reads it to refuse a remote file the same way.
     *
     * The rule itself belongs to DraftAttachmentService, which enforces it;
     * this is the name the window and the picker know it by. It moved here with
     * the actions it describes — it was left behind on ComposeController for
     * one commit, which is exactly the kind of stray this split was for.
     */
    public const int MAX_ATTACHMENT_BYTES = DraftAttachmentService::MAX_BYTES;

    public function __construct(
        private readonly DraftAttachmentService  $attachments,
        private readonly TranslatorInterface     $translator,
        private readonly AttachmentStorageHelper $storage,
        private readonly EntityManagerInterface  $entityManager,
    ) {}

    /**
     * Attach files to a draft. The window forces a save before uploading, so
     * there is always a Message to hang the parts off; the sending side
     * already turns MessageParts into MIME attachments.
     *
     * Answers with the attachment strip for the window to swap in.
     */
    #[Route('/attachments/{id}', name: 'attachments_add', methods: ['POST'])]
    public function addAttachments(Request $request, Message $message): Response
    {
        $this->assertDraft($message);
        // Every action here writes to a draft, so each proves it came from our
        // own page: the shared `ajax` token, in the X-CSRF-Token header.
        $this->assertCsrf($request, 'ajax');

        $files = array_values(array_filter(
            $request->files->all('files'),
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));

        // Nothing arrived at all. Almost always post_max_size: PHP discards the
        // whole body, so $_FILES is empty and there is no per-file error to
        // read — silence here is what made an oversized upload look like a
        // no-op instead of a refusal.
        if (0 === count($files)) {
            return $this->uploadError($this->translator->trans('compose.upload.post_too_large'));
        }

        // Encrypted in the browser to the person's own key, for a draft that
        // is sealed (assets/controllers/compose/encryption_controller.js).
        $refusal = $this->attachments->attach($message, $files, '1' === $request->request->get('sealed'));

        if (null !== $refusal) {
            return $this->uploadError($refusal);
        }

        return $this->render('compose/_attachments.html.twig', ['message' => $message]);
    }

    /**
     * Place an image in the body rather than beside it.
     *
     * JSON rather than a Turbo Stream because nothing on the page is being
     * replaced: the answer is a reference the editor drops in at the caret,
     * which is a caret operation, not a DOM region swap. ContactController's
     * autocomplete is the precedent.
     *
     * Paste and drag-drop come through here too — the alternative is a
     * multi-megabyte data: URI inside every autosave of the draft.
     */
    #[Route('/inline-image/{id}', name: 'inline_image', methods: ['POST'])]
    public function addInlineImage(Request $request, Message $message): Response
    {
        $this->assertDraft($message);
        $this->assertCsrf($request, 'ajax');

        $file = $request->files->get('file');

        if (false === $file instanceof UploadedFile) {
            return $this->json(
                ['error' => $this->translator->trans('compose.upload.post_too_large')],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        $result = $this->attachments->attachInline($message, $file);

        if (is_string($result)) {
            return $this->json(['error' => $result], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $this->json([
            'id'        => $result->id,
            'contentId' => $result->contentId,
            'url'       => $this->generateUrl('app_mail_attachment', ['id' => $result->id]),
        ]);
    }

    #[Route('/attachment/{id}/remove', name: 'attachment_remove', methods: ['POST'])]
    public function removeAttachment(Request $request, MessagePart $part): Response
    {
        $message = $part->message;

        $this->assertDraft($message);
        $this->assertCsrf($request, 'ajax');

        $this->attachments->remove($part);

        return $this->render('compose/_attachments.html.twig', ['message' => $message]);
    }

    /**
     * The picture as it was uploaded, for the browser to scale from (#502).
     *
     * The part's own address serves whatever stands in for it now, which
     * after a first scaling is the smaller copy; scaling that again would
     * lose detail each time the size changes. A part never scaled has no
     * separate original, so this serves its file. Always as a download and
     * as bytes without a type: it is read by fetch(), never shown.
     */
    #[Route('/attachment/{id}/original', name: 'attachment_original', methods: ['GET'])]
    public function original(MessagePart $part): Response
    {
        $this->assertDraft($part->message);

        $relative = $part->originalStoragePath ?? $part->storagePath;
        $absolute = null === $relative ? null : $this->storage->getAbsolutePath($relative);

        if (null === $absolute || false === is_file($absolute)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'original');

        return $response;
    }

    /**
     * A smaller copy of one image, made by the browser, in place of the one
     * the part holds. The original stays on the server. See
     * DraftAttachmentService::replaceWithScaled() for what is accepted.
     */
    #[Route('/attachment/{id}/scaled', name: 'attachment_scaled', methods: ['POST'])]
    public function scaled(Request $request, MessagePart $part): Response
    {
        $this->assertDraft($part->message);
        $this->assertCsrf($request, 'ajax');

        $file = $request->files->get('file');

        if (false === $file instanceof UploadedFile) {
            return $this->uploadError($this->translator->trans('compose.upload.post_too_large'));
        }

        $refusal = $this->attachments->replaceWithScaled($part, $file, '1' === $request->request->get('sealed'));

        if (null !== $refusal) {
            return $this->uploadError($refusal);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** The original back in place of the smaller copy. */
    #[Route('/attachment/{id}/restore', name: 'attachment_restore', methods: ['POST'])]
    public function restore(Request $request, MessagePart $part): Response
    {
        $this->assertDraft($part->message);
        $this->assertCsrf($request, 'ajax');

        $this->attachments->restoreOriginal($part);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Remember the size the person picked, once the browser has scaled every
     * image to it, and answer with the strip, whose sizes have changed.
     * Anything that is not one of the three sizes is Original.
     */
    #[Route('/image-size/{id}', name: 'image_size', methods: ['POST'])]
    public function imageSize(Request $request, Message $message): Response
    {
        $this->assertDraft($message);
        $this->assertCsrf($request, 'ajax');

        $message->imageSize = ImageSize::tryFrom((string) $request->request->get('size'));
        $this->entityManager->flush();

        return $this->render('compose/_attachments.html.twig', ['message' => $message]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Plain text so the window can show the reason as-is; the HTML answer of a
     * successful upload is the attachment strip.
     */
    private function uploadError(string $message): Response
    {
        return new Response(
            $message,
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }

    /**
     * Attachments may only be touched on a draft the user owns that has not
     * gone out yet — a sent message's parts are a record of what was sent.
     */
    private function assertDraft(?Message $message): void
    {
        if (null === $message) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        if (false === $message->isDraft() || null !== $message->sentAt) {
            throw $this->createAccessDeniedException('Only unsent drafts can be edited.');
        }
    }
}
