<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Entity\Embeddable\Appearance;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/settings/appearance', name: 'app_appearance_')]
#[IsGranted('ROLE_USER')]
final class AppearanceController extends AbstractController
{
    private const array ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/var/uploads/backgrounds')]
        private readonly string                 $backgroundDirectory,
    ) {
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payload = $request->toArray();

        $user->appearance->applyArray($payload);
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Remembers how wide the live preview pane is.
     *
     * Its own endpoint rather than a field on the appearance payload, for the
     * same reason the calendar's is its own: the drag handle writes on release
     * and this is a layout preference, not part of the theme — it must not turn
     * up in an exported theme file or be applied by importing somebody else's.
     *
     * Unlike the appearance payload beside it, this takes a CSRF token. That is
     * not inconsistency: a request forged against `update` can only rewrite the
     * user's own colours, which is why that one has never carried a token,
     * whereas this is a new state-changing POST and new ones carry tokens. The
     * width itself is clamped server-side too — the client's bounds are a
     * convenience, and a stored 40000 would wedge the settings page.
     */
    #[Route('/pane-state', name: 'pane_state', methods: ['POST'])]
    public function paneState(Request $request): JsonResponse
    {
        if (false === $this->isCsrfTokenValid('appearance_pane_state', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();

        if (true === $request->request->has('width')) {
            $user->setSetting(User::SETTING_APPEARANCE_PREVIEW_WIDTH, max(
                User::APPEARANCE_PREVIEW_MIN_WIDTH,
                min(User::APPEARANCE_PREVIEW_MAX_WIDTH, $request->request->getInt('width')),
            ));

            $this->entityManager->flush();
        }

        return $this->json(['width' => $user->appearancePreviewWidth]);
    }

    #[Route('/background', name: 'background_upload', methods: ['POST'])]
    public function uploadBackground(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $file = $request->files->get('background');

        if (false === $file instanceof UploadedFile) {
            return $this->json(['ok' => false, 'error' => 'appearance.background.missing'], Response::HTTP_BAD_REQUEST);
        }

        if (false === in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            return $this->json(['ok' => false, 'error' => 'appearance.background.type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $directory = $this->userDirectory((int) $user->id);

        if (false === is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $previous = $user->appearance->backgroundFile;

        if (null !== $previous && true === is_file($directory.'/'.$previous)) {
            unlink($directory.'/'.$previous);
        }

        $filename = sprintf('%s.%s', Uuid::v7()->toRfc4122(), $file->guessExtension() ?? 'jpg');
        $file->move($directory, $filename);

        $user->appearance->backgroundFile = $filename;
        $user->appearance->backgroundKind = BackgroundKind::Custom;

        $this->entityManager->flush();

        return $this->json([
            'ok'  => true,
            'url' => $this->generateUrl('app_appearance_background_show', ['filename' => $filename]),
        ]);
    }

    #[Route('/background/{filename}', name: 'background_show', requirements: ['filename' => '[0-9a-f\-]+\.[a-z]{3,4}'], methods: ['GET'])]
    public function showBackground(string $filename): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->appearance->backgroundFile !== $filename) {
            throw $this->createNotFoundException();
        }

        $path = $this->userDirectory((int) $user->id).'/'.$filename;

        if (false === is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPublic();
        $response->setMaxAge(31536000);

        return $response;
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $response = new Response(
            json_encode($user->appearance->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'plmail-theme.json'),
        );

        return $response;
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $payload = $request->toArray();

        if (1 !== ($payload['version'] ?? null)) {
            return $this->json(['ok' => false, 'error' => 'appearance.import.version'], Response::HTTP_BAD_REQUEST);
        }

        $user->appearance->applyArray($payload);
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/reset', name: 'reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $user->appearance->applyArray(new Appearance()->toArray());
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    private function userDirectory(int $userId): string
    {
        return sprintf('%s/%d', $this->backgroundDirectory, $userId);
    }
}
