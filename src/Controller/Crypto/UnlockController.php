<?php

declare(strict_types=1);

namespace App\Controller\Crypto;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Security\Crypto\SessionUnlock;
use App\Security\PasswordKdfUpgrader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The session's half of an unlocked keyring, and the way to get one when the
 * sign-in did not leave it behind.
 *
 * A sign-in through the form leaves it: the form sends the unlock key with the
 * credentials (see SessionUnlockLoginListener). Everything else — a session
 * reopened by a remember-me cookie, the one the install wizard opens, one from
 * before this existed — has a person who is signed in and a keyring that is
 * shut. POST here with the password, stretched, opens it: the auth hash is
 * checked like a sign-in and, if it holds, the browser's new unlock key is
 * kept. See SessionUnlock for what the two halves are.
 *
 * An account whose hash is still of the password itself proves it with the
 * password, once, and is moved over in the same breath — that is the only way
 * its browser can get a key to open anything with.
 */
#[Route('/keyring/unlock', name: 'app_keyring_unlock_')]
#[IsGranted('ROLE_USER')]
final class UnlockController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly SessionUnlock               $sessionUnlock,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordKdfUpgrader         $upgrader,
        private readonly RateLimiterFactoryInterface $keyringUnlockLimiter,
    ) {
    }

    #[Route('', name: 'current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        $unlockKey = $this->sessionUnlock->current();

        return new JsonResponse(
            null === $unlockKey ? ['unlockKey' => null] : ['unlockKey' => $unlockKey],
            null === $unlockKey ? Response::HTTP_NOT_FOUND : Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    #[Route('', name: 'open', methods: ['POST'])]
    public function open(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->assertCsrf($request, 'keyring');

        // A signed-in session is not proof of the password — an unlocked screen
        // is the whole threat — so guessing it here is throttled like a sign-in.
        if (false === $this->keyringUnlockLimiter->create((string) $user->id)->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $body      = json_decode($request->getContent(), true);
        $authHash  = (string) ($body['authHash'] ?? '');
        $password  = (string) ($body['password'] ?? '');
        $unlockKey = (string) ($body['unlockKey'] ?? '');

        if (false === SessionUnlock::isWellFormed($unlockKey)) {
            return new JsonResponse(['error' => 'malformed'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $proven = true === $user->hasClientDerivedPassword()
            ? $this->hasher->isPasswordValid($user, $authHash)
            : '' !== $password && $this->hasher->isPasswordValid($user, $password)
                && $this->upgrader->upgrade($user, $authHash, (string) $user->email);

        if (false === $proven) {
            return new JsonResponse(['error' => 'wrong_password'], Response::HTTP_FORBIDDEN);
        }

        $this->sessionUnlock->store($unlockKey);

        return new JsonResponse(['unlocked' => true], headers: ['Cache-Control' => 'no-store']);
    }
}
