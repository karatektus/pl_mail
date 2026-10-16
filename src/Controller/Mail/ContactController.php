<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Service\AddressBook\ContactSuggester;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/contacts', name: 'app_contacts_')]
#[IsGranted('IS_AUTHENTICATED')]
final class ContactController extends AbstractController
{
    public function __construct(
        private readonly ContactSuggester $suggester,
    ) {}

    #[Route('/autocomplete', name: 'autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('q'));

        if (mb_strlen($query) < 1) {
            return $this->json([]);
        }

        // The harvest and the address books together, ranked once — see
        // ContactSuggester for why JMAP reads the same list.
        $results = [];

        foreach ($this->suggester->suggest($this->getUser(), $query) as $suggestion) {
            $results[] = [
                'email'         => $suggestion->email,
                'displayName'   => $suggestion->name,
                'initials'      => $suggestion->initials(),
                'frequency'     => $suggestion->frequency,
                'inAddressBook' => $suggestion->inAddressBook,
            ];
        }

        return $this->json($results);
    }
}
