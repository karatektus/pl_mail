<?php

declare(strict_types=1);

namespace App\Controller\Crypto;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Crypto\UserKeyCopyKind;
use App\Domain\Exception\KeyringRefused;
use App\Entity\User\User;
use App\Service\Crypto\Keyring;
use App\Service\Crypto\KeyringView;
use App\Service\Crypto\SmimeCertificates;
use App\Service\Demo\DemoMode;
use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where a browser keeps the keyring it made, and fetches it back.
 *
 * Thin over App\Service\Crypto\Keyring, which holds every rule; this turns
 * JSON into calls and refusals into a 422 the page can log. Each write answers
 * with the whole keyring as it now stands (KeyringView), so the browser never
 * has to guess what a write did to rows it did not name — a key deleted
 * because another was imported for its address, say.
 *
 * Writes carry the `keyring` CSRF token in X-CSRF-Token; see ChecksCsrf.
 */
#[Route('/keyring', name: 'app_keyring_')]
#[IsGranted('ROLE_USER')]
final class KeyringController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly Keyring           $keyring,
        private readonly KeyringView       $view,
        private readonly SmimeCertificates $smime,
        private readonly DemoMode          $demoMode,
        private readonly LoggerInterface   $logger,
    ) {
    }

    #[Route('', name: 'state', methods: ['GET'])]
    public function state(#[CurrentUser] User $user): JsonResponse
    {
        return $this->keyringResponse($user);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->write($request, $user, function (array $body) use ($user): void {
            $this->keyring->create(
                $user,
                (string) ($body['userKey'] ?? ''),
                $this->list($body['addressKeys'] ?? null),
                isset($body['deviceCopy']) ? (string) $body['deviceCopy'] : null,
                isset($body['deviceLabel']) ? (string) $body['deviceLabel'] : null,
                isset($body['passphrase']) && is_array($body['passphrase']) ? $body['passphrase'] : null,
            );
        });
    }

    /**
     * A new encryption passphrase for a keyring that hangs off one: its salt
     * and iteration count, and every active user key re-wrapped under it. The
     * passphrase itself never comes here. See Keyring::changePassphrase().
     */
    #[Route('/passphrase', name: 'passphrase_change', methods: ['POST'])]
    public function changePassphrase(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->write($request, $user, function (array $body) use ($user): void {
            $this->keyring->changePassphrase(
                $user,
                is_array($body['passphrase'] ?? null) ? $body['passphrase'] : [],
                is_array($body['rewrapped'] ?? null) ? $body['rewrapped'] : [],
            );
        });
    }

    #[Route('/address-keys', name: 'address_keys_add', methods: ['POST'])]
    public function addAddressKeys(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->write($request, $user, function (array $body) use ($user): void {
            $this->keyring->addAddressKeys($user, (int) ($body['userKeyId'] ?? 0), $this->list($body['addressKeys'] ?? null));
        });
    }

    #[Route('/address-keys/used', name: 'address_keys_used', methods: ['POST'])]
    public function markUsed(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->write($request, $user, function (array $body) use ($user): void {
            $this->keyring->markUsed($user, $this->list($body['ids'] ?? null));
        });
    }

    #[Route('/address-keys/{id}/primary', name: 'address_key_primary', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function makePrimary(Request $request, #[CurrentUser] User $user, int $id): JsonResponse
    {
        return $this->write($request, $user, function (array $body) use ($user, $id): void {
            $this->keyring->makePrimary($user, $id, (string) ($body['email'] ?? ''));
        });
    }

    #[Route('/address-keys/{id}/compromised', name: 'address_key_compromised', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markCompromised(Request $request, #[CurrentUser] User $user, int $id): JsonResponse
    {
        return $this->write($request, $user, function () use ($user, $id): void {
            $this->keyring->markCompromised($user, $id);
        });
    }

    #[Route('/user-keys/{id}/rewrap', name: 'user_key_rewrap', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rewrap(Request $request, #[CurrentUser] User $user, int $id): JsonResponse
    {
        return $this->write($request, $user, function (array $body) use ($user, $id): void {
            $this->keyring->rewrap($user, $id, (string) ($body['wrapped'] ?? ''));
        });
    }

    #[Route('/copies', name: 'copy_add', methods: ['POST'])]
    public function addCopy(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->write($request, $user, function (array $body) use ($user): void {
            $kind = UserKeyCopyKind::tryFrom((string) ($body['kind'] ?? ''))
                ?? throw new KeyringRefused('Unknown kind of recovery copy.');

            $this->keyring->addCopy(
                $user,
                (int) ($body['userKeyId'] ?? 0),
                $kind,
                (string) ($body['wrapped'] ?? ''),
                isset($body['label']) ? (string) $body['label'] : null,
            );
        });
    }

    #[Route('/copies/{id}/used', name: 'copy_used', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function copyUsed(Request $request, #[CurrentUser] User $user, int $id): JsonResponse
    {
        return $this->write($request, $user, function () use ($user, $id): void {
            $this->keyring->touchCopy($user, $id);
        });
    }

    #[Route('/copies/{id}/remove', name: 'copy_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeCopy(Request $request, #[CurrentUser] User $user, int $id): JsonResponse
    {
        return $this->write($request, $user, function () use ($user, $id): void {
            $this->keyring->removeCopy($user, $id);
        });
    }

    /**
     * An S/MIME certificate from a PKCS#12 file the browser opened: the
     * certificate and its chain as PEM, the private key wrapped under the
     * user key named. See SmimeCertificates for what is refused.
     */
    #[Route('/smime', name: 'smime_import', methods: ['POST'])]
    public function importSmime(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return $this->write($request, $user, function (array $body) use ($user): void {
            $this->smime->import(
                $user,
                (int) ($body['userKeyId'] ?? 0),
                (string) ($body['certificate'] ?? ''),
                (string) ($body['chain'] ?? ''),
                $body['privateKey'] ?? null,
            );
        });
    }

    #[Route('/smime/{id}/remove', name: 'smime_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeSmime(Request $request, #[CurrentUser] User $user, int $id): JsonResponse
    {
        return $this->write($request, $user, function () use ($user, $id): void {
            $this->smime->remove($user, $id);
        });
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param Closure(array<string, mixed>): void $write
     */
    private function write(Request $request, User $user, Closure $write): JsonResponse
    {
        $this->assertCsrf($request, 'keyring');

        if (true === $this->demoMode->isEnabled()) {
            throw $this->createAccessDeniedException('A demo mailbox has no keyring.');
        }

        $body = json_decode($request->getContent(), true);

        try {
            $write(is_array($body) ? $body : []);
        } catch (KeyringRefused $refused) {
            // Logged, because the person cannot do anything about it and the
            // only witness of a browser sending something broken is here.
            $this->logger->warning('Keyring write refused: {reason}', ['reason' => $refused->getMessage(), 'user' => $user->id]);

            return new JsonResponse(['error' => $refused->getMessage()], 422);
        }

        return $this->keyringResponse($user);
    }

    private function keyringResponse(User $user): JsonResponse
    {
        // Wrapped key material for one person: never to be kept by anything
        // between the browser and here.
        return new JsonResponse($this->view->build($user), headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
