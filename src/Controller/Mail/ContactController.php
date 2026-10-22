<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Repository\Mail\ContactRepository;
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
                // The topbar search's from:/to: completions show an
                // address-book contact's photo; everyone else keeps the icon.
                'photoUrl'      => true === $suggestion->hasPhoto && null !== $suggestion->contactId
                    ? $this->generateUrl('app_contacts_photo_show', ['id' => $suggestion->contactId])
                    : null,
            ];
        }

        return $this->json($results);
    }

    /**
     * The same list for the composer's To, Cc and Bcc, in the shape the
     * autocomplete bundle's field reads. See ContactAutocompleteField.
     *
     * The field is an entity field over the harvested addresses, and the
     * bundle's own endpoint for it could only ever search those — a contact in
     * an address book that had never been mailed was "No results found".
     *
     * An address the harvest knows is offered under its id, as the bundle's
     * endpoint did, so a recipient already on a draft is still recognised as
     * chosen. One only in an address book is offered as the address itself,
     * which the field turns into a harvested row when the form is submitted
     * (ContactAutocompleteField::resolveCreatedOptions()).
     */
    #[Route('/autocomplete/recipients', name: 'autocomplete_recipients', methods: ['GET'])]
    public function recipients(Request $request, ContactRepository $harvest): JsonResponse
    {
        $user  = $this->getUser();
        $query = trim($request->query->getString('query'));

        if (null === $user || '' === $query) {
            return $this->json(['results' => []]);
        }

        $suggestions = $this->suggester->suggest($user, $query);
        $known       = $harvest->findByEmailsForUser($user, array_map(static fn ($s): string => $s->email, $suggestions));
        $results     = [];

        foreach ($suggestions as $suggestion) {
            $id = $known[mb_strtolower($suggestion->email)]->id ?? null;

            $results[] = [
                'value' => null !== $id ? (string) $id : $suggestion->email,
                'text'  => null !== $suggestion->name && '' !== $suggestion->name
                    ? sprintf('%s <%s>', $suggestion->name, $suggestion->email)
                    : $suggestion->email,
            ];
        }

        return $this->json(['results' => $results]);
    }
}
