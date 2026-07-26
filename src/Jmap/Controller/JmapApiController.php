<?php

declare(strict_types=1);

namespace App\Jmap\Controller;

use App\Entity\User;
use App\Jmap\Protocol\Exception\InvalidRequestException;
use App\Jmap\Protocol\Exception\UnknownCapabilityException;
use App\Jmap\Protocol\JmapProcessor;
use App\Jmap\Protocol\JmapRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The single JMAP API endpoint. Every read and write flows through here as an
 * ordered list of method calls (RFC 8620 §3.3).
 */
final class JmapApiController extends AbstractController
{
    public function __construct(
        private readonly JmapProcessor $processor,
    ) {
    }

    #[Route('/jmap/api', name: 'jmap_api', methods: ['POST'])]
    public function api(Request $request, #[CurrentUser] User $user): JsonResponse
    {
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
            $response = $this->processor->process($jmapRequest, $user);
        } catch (InvalidRequestException $exception) {
            return $this->problem('urn:ietf:params:jmap:error:notRequest', $exception->getMessage());
        } catch (UnknownCapabilityException $exception) {
            return $this->problem('urn:ietf:params:jmap:error:unknownCapability', $exception->getMessage());
        }

        return new JsonResponse($response->toArray());
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
