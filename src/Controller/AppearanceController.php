<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Entity\Embeddable\Appearance;
use App\Entity\User;
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

        $directory = $this->userDirectory((int) $user->getId());

        if (false === is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $previous = $user->appearance->backgroundFile;

        if (null !== $previous && true === is_file($directory.'/'.$previous)) {
            unlink($directory.'/'.$previous);
        }

        $filename = sprintf('%s.%s', Uuid::v7()->toRfc4122(), $file->guessExtension() ?? 'jpg');
        $file->move($directory, $filename);

        $user->appearance
            ->setBackgroundFile($filename)
            ->setBackgroundKind(BackgroundKind::Custom);

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

        $path = $this->userDirectory((int) $user->getId()).'/'.$filename;

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
