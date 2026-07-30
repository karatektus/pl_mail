<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\User\ApiToken;
use App\Entity\User\User;
use App\Form\ApiTokenType;
use App\Repository\User\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Manage app passwords from settings.
 *
 * The generated secret is passed to the view exactly once, on the response to
 * the request that created it. It is never stored in the session or re-shown:
 * if the user misses it, the correct move is to revoke and generate another.
 */
#[Route('/settings/app-passwords', name: 'app_api_token_')]
#[IsGranted('ROLE_USER')]
final class ApiTokenController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiTokenRepository $tokenRepository,
    ) {
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ApiTokenType::class);
        $form->handleRequest($request);

        // An invalid token lands here too: minting a credential must not be
        // reachable by a cross-site POST, which it was while this read the
        // request bag directly.
        if (false === $form->isSubmitted() || false === $form->isValid()) {
            return $this->streamResponse($request, 'app_password.name_required', null, 'error');
        }

        $name = (string) $form->get('name')->getData();

        ['token' => $token, 'secret' => $secret] = ApiToken::create($user, $name);

        $this->em->persist($token);
        $this->em->flush();

        return $this->streamResponse($request, 'app_password.created', $secret);
    }

    #[Route('/{id}/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(Request $request, int $id): Response
    {
        if (false === $this->isCsrfTokenValid('app-password-revoke' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $token = $this->tokenRepository->findOneOwnedBy($id, $this->getUser());

        if (null === $token) {
            throw $this->createNotFoundException('No such app password.');
        }

        $token->revoke();
        $this->em->flush();

        return $this->streamResponse($request, 'app_password.revoked');
    }

    private function streamResponse(
        Request $request,
        string $toastMessage,
        ?string $secret = null,
        string $toastType = 'success',
    ): Response {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('api_token/_mutation.stream.html.twig', [
                'apiTokens' => $this->tokenRepository->findForUser($this->getUser()),
                'newSecret' => $secret,
                'toastMessage' => $toastMessage,
                'toastType' => $toastType,
                // A fresh form: the replaced frame carries the create field, and
                // reusing the submitted one would redisplay what was just saved.
                'apiTokenForm' => $this->createForm(ApiTokenType::class)->createView(),
            ]);
        }

        return $this->redirectToRoute('app_settings_index', ['section' => 'app-passwords']);
    }
}
