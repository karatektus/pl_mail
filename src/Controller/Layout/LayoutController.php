<?php

declare(strict_types=1);

namespace App\Controller\Layout;

use App\Controller\ChecksCsrf;
use App\Domain\DTO\Layout\Workspaces;
use App\Entity\User\User;
use App\Service\Layout\WorkspaceStore;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where the page saves the user's workspaces (#755).
 *
 * One endpoint for every change — a split, a move, a rename, a switch, a new
 * workspace — because the page holds the whole document and every change is
 * an edit to it. Posting the whole thing also means a lost write is repaired
 * by the next one, rather than leaving the server one step behind forever.
 *
 * Divider positions are not posted here: they are the device's and live in
 * its cookie (DeviceLayout). What arrives here is only the arrangement, and
 * the proportions a new device should start from.
 */
#[Route('/layout', name: 'app_layout_')]
#[IsGranted('ROLE_USER')]
final class LayoutController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly WorkspaceStore         $store,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Replace the user's workspaces with the posted document.
     *
     * JSON in the body and the token in a header: the document is nested, and
     * a form encoding of a tree is a format nobody would choose. A document
     * that does not parse is refused with 422 and nothing is written — the
     * page keeps what it has, and the stored copy stays the last good one.
     */
    #[Route('/workspaces', name: 'workspaces', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'layout_workspaces');

        try {
            $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->json(['error' => 'invalid'], 422);
        }

        $workspaces = Workspaces::fromArray($data);

        if (null === $workspaces) {
            return $this->json(['error' => 'invalid'], 422);
        }

        /** @var User $user */
        $user = $this->getUser();

        $this->store->save($user, $workspaces);
        $this->em->flush();

        return $this->json($workspaces->toArray());
    }
}
