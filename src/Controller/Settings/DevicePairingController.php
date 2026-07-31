<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\User\User;
use App\Service\User\DevicePairingService;
use App\Service\User\TwoFactor\QrCodeRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\Turbo\TurboBundle;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Pairing a device by showing it a code.
 *
 * Two halves that deliberately live at different paths and different
 * protection levels:
 *
 * - `/settings/pair` is behind the session firewall. Only a signed-in user can
 *   cause a pairing code to exist.
 * - `/device/pair` is not, because the app has no credential yet — that is the
 *   entire point. It is gated by the code instead, which is 32 bytes of CSPRNG
 *   valid for two minutes and burned on first use.
 */
final class DevicePairingController extends AbstractController
{
    public function __construct(
        private readonly DevicePairingService $pairing,
        private readonly QrCodeRenderer $qrCodes,
    ) {
    }

    /**
     * Issues a code and renders it, for the settings page to poll or embed.
     */
    #[Route('/settings/pair', name: 'app_device_pair_issue', methods: ['POST'])]
    public function issue(Request $request, #[CurrentUser] User $user): Response
    {
        // Minting a credential — which is where this leads — must not be
        // reachable by a cross-site POST.
        if (false === $this->isCsrfTokenValid('device-pair', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        ['code' => $code, 'expiresAt' => $expiresAt] = $this->pairing->issue($user);

        // The address the *browser* reached this server on, which is the one
        // that resolves on this network. A configured canonical URL would be
        // wrong for exactly the self-hosted case this exists for.
        $uri = $this->pairing->pairingUri($request->getSchemeAndHttpHost(), $code);

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('device_pair/_pairing.stream.html.twig', [
                'qr' => $this->qrCodes->dataUri($uri),
                'uri' => $uri,
                'expiresAt' => $expiresAt,
            ]);
        }

        return $this->redirectToRoute('app_settings_index', ['section' => 'app-passwords']);
    }

    /**
     * Exchanges a pairing code for an app password.
     *
     * Unauthenticated by necessity. `security.yaml` must leave this path
     * public — a firewall in front of it would make pairing impossible, since
     * a device that could authenticate would not need to pair.
     */
    #[Route('/device/pair', name: 'app_device_pair_redeem', methods: ['POST'])]
    public function redeem(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);

        if (false === is_array($body)) {
            return $this->problem('invalidArguments', 'Expected a JSON object.', 400);
        }

        $code = $body['code'] ?? null;

        if (false === is_string($code) || '' === $code) {
            return $this->problem('invalidArguments', '"code" is required.', 400);
        }

        // What the credential will be called in the user's app-password list.
        // Taken from the device rather than invented so someone with four
        // phones can tell which one to revoke.
        $name = $body['deviceName'] ?? null;
        $deviceName = is_string($name) && '' !== trim($name)
            ? mb_substr(trim($name), 0, 100)
            : 'Paired device';

        $result = $this->pairing->redeem($code, $deviceName);

        if (null === $result) {
            // One answer for unknown, expired and already-used. Telling them
            // apart would confirm which codes had once been real.
            return $this->problem(
                'notFound',
                'That pairing code is not valid. Codes expire after two minutes and work once.',
                404,
            );
        }

        return new JsonResponse($result);
    }

    private function problem(string $type, string $detail, int $status): JsonResponse
    {
        return new JsonResponse(
            ['type' => $type, 'status' => $status, 'detail' => $detail],
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
