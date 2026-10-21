<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository as AddressBookRepository;
use App\Repository\Mail\ContactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/contacts', name: 'app_contacts_')]
#[IsGranted('IS_AUTHENTICATED')]
final class ContactController extends AbstractController
{
    private const LIMIT = 8;

    public function __construct(
        private readonly ContactRepository $contactRepository,
        private readonly AddressBookRepository $addressBook,
    ) {}

    /**
     * Powers the topbar search's from:/to:/cc: operator completions (see
     * assets/controllers/mail/search_controller.js).
     *
     * Address-book hits come first — they are the contacts the user keeps on
     * purpose — then the harvested correspondents fill the remainder. A
     * harvested hit whose address is already covered by the address book is
     * dropped so the dropdown never shows the same address twice.
     */
    #[Route('/autocomplete', name: 'autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('q'));

        if (mb_strlen($query) < 1) {
            return $this->json([]);
        }

        $user = $this->getUser();
        \assert($user instanceof User);

        $results = [];
        $seenEmails = [];

        foreach ($this->addressBook->findAddressBookForAutocomplete($user, $query, self::LIMIT) as $hit) {
            $contact = $hit['contact'];
            $seenEmails[mb_strtolower($hit['email'])] = true;
            $results[] = [
                'email'         => $hit['email'],
                'displayName'   => $contact->displayName,
                'initials'      => $this->initials($contact->displayName),
                'frequency'     => null,
                'isAddressBook' => true,
                'photoUrl'      => null !== $contact->photoPath && '' !== $contact->photoPath
                    ? $this->generateUrl('app_contacts_photo_show', ['id' => $contact->id])
                    : null,
            ];
        }

        $remaining = self::LIMIT - \count($results);

        if ($remaining > 0) {
            $contacts = $this->contactRepository->findForAutocomplete($user, $query, $remaining);

            foreach ($contacts as $contact) {
                if (isset($seenEmails[mb_strtolower($contact->email)])) {
                    continue;
                }
                $results[] = [
                    'email'         => $contact->email,
                    'displayName'   => $contact->displayName,
                    'initials'      => $contact->initials,
                    'frequency'     => $contact->frequency,
                    'isAddressBook' => false,
                    'photoUrl'      => null,
                ];
            }
        }

        return $this->json($results);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $out = '';
        foreach (\array_slice($parts, 0, 2) as $part) {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $out;
    }
}
