<?php

declare(strict_types=1);

namespace App\Jmap\Controller;

use App\Entity\User\User;
use App\Jmap\Protocol\Exception\InvalidRequestException;
use App\Jmap\Protocol\Exception\UnknownCapabilityException;
use App\Jmap\Protocol\JmapProcessor;
use App\Jmap\Protocol\JmapRequest;
use App\Entity\User\ApiToken;
use App\Security\ApiTokenAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The single JMAP API endpoint. Every read and write flows through here as an
 * ordered list of method calls (RFC 8620 §3.3).
 */
final class JmapApiController extends AbstractController
{
    public function __construct(
        private readonly JmapProcessor $processor,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/jmap/api', name: 'jmap_api', methods: ['POST'])]
    public function api(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        // Which credential this arrived on. Null for the browser session, which
        // is the user themselves and is never limited by an app password's
        // scopes — see JmapProcessor::permitted().
        $token = $this->appPassword($request);

        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->problem('urn:ietf:params:jmap:error:notJSON', 'The request body is not valid JSON.');
        }

        if (false === is_array($payload)) {
            return $this->problem('urn:ietf:params:jmap:error:notRequest', 'The request is not a JSON object.');
        }

        try {
            $jmapRequest = JmapRequest::fromArray($payload);
            $response = $this->processor->process($jmapRequest, $user, $token);
        } catch (InvalidRequestException $exception) {
            return $this->problem('urn:ietf:params:jmap:error:notRequest', $exception->getMessage());
        } catch (UnknownCapabilityException $exception) {
            return $this->problem('urn:ietf:params:jmap:error:unknownCapability', $exception->getMessage());
        }

        return new JsonResponse($response->toArray());
    }

    /**
     * The app password the firewall authenticated, read off the Security token.
     *
     * The badge the authenticator attached is the only place it exists: the
     * firewall puts the caller's User on the token, and deliberately not which
     * credential presented them, because for most of this application that is
     * a detail with no consequence. Here it is the whole question, so it is
     * fished back out.
     *
     * Anything unexpected — no badge, a badge of another type — reads as null,
     * which is the unscoped browser-session answer. That is the wrong direction
     * to fail in for a scoped credential, so the alternative is not tempting:
     * refusing every request without a badge would break the browser, and the
     * badge is attached by this application's own authenticator, not by input.
     */
    private function appPassword(Request $request): ?ApiToken
    {
        $securityToken = $this->tokenStorage->getToken();

        if (false === $securityToken instanceof TokenInterface) {
            return null;
        }

        $appPassword = $securityToken->getAttribute(ApiTokenAuthenticator::TOKEN_ATTRIBUTE);

        return $appPassword instanceof ApiToken ? $appPassword : null;
    }

    private function problem(string $type, string $detail): JsonResponse
    {
        $response = new JsonResponse(
            [
                'type' => $type,
                'status' => Response::HTTP_BAD_REQUEST,
                'detail' => $detail,
            ],
            Response::HTTP_BAD_REQUEST,
        );
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
