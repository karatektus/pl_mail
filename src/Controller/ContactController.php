<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ContactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/contacts', name: 'app_contacts_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ContactController extends AbstractController
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
    ) {}

    #[Route('/autocomplete', name: 'autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('q'));

        if (mb_strlen($query) < 1) {
            return $this->json([]);
        }

        $contacts = $this->contactRepository->findForAutocomplete(
            $this->getUser(),
            $query,
        );

        $results = [];

        foreach ($contacts as $contact) {
            $results[] = [
                'email'       => $contact->getEmail(),
                'displayName' => $contact->getDisplayName(),
                'initials'    => $contact->getInitials(),
                'frequency'   => $contact->getFrequency(),
            ];
        }

        return $this->json($results);
    }
}
