<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Repository\User\UserRepository;
use App\Security\PasswordKdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The step before a sign-in: how to stretch the password about to be typed.
 *
 * The login form calls this with the address, stretches the password with the
 * salt and iteration count it gets back, and posts the result instead of the
 * password — see App\Security\PasswordKdf for the construction and
 * assets/controllers/auth/login_controller.js for the caller.
 *
 * Public by necessity (`^/login` is PUBLIC_ACCESS in security.yaml): nobody is
 * signed in yet. A POST rather than a GET only so the address stays out of
 * access logs; nothing is written, so there is no CSRF token to check.
 *
 * Unknown addresses are answered, not refused. A 404 here would turn the form
 * into an oracle for which addresses have accounts — the password limiter's
 * whole point is that guessing is slow, and that is moot if the list of whom
 * to guess at comes free. PasswordKdf::parametersFor() says what still leaks.
 */
final class PreloginController extends AbstractController
{
    public function __construct(
        private readonly UserRepository              $users,
        private readonly PasswordKdf                 $kdf,
        private readonly RateLimiterFactoryInterface $loginPreloginLimiter,
    ) {
    }

    #[Route('/login/prelogin', name: 'app_login_prelogin', methods: ['POST'])]
    public function prelogin(Request $request): JsonResponse
    {
        if (false === $this->loginPreloginLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        }

        $email = trim($request->request->getString('email'));

        if ('' === $email) {
            return new JsonResponse(['error' => 'email_required'], 400);
        }

        $user = $this->users->findOneBy(['email' => $email]);

        return new JsonResponse($this->kdf->parametersFor($user, $email), headers: [
            // An answer about one person's account, given to whoever asked.
            'Cache-Control' => 'no-store',
        ]);
    }
}
