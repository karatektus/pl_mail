<?php

declare(strict_types=1);

namespace App\Controller\Contacts;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\Helper\AddressHelper;
use App\Entity\User\User;
use App\Service\Contacts\ContactManager;
use App\Twig\AddressBookExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Add to contacts" on a sender in the message view.
 *
 * Deliberately a button, not something that happens on its own: most of what
 * arrives is newsletters and no-reply addresses, and an address book that
 * fills itself with those stops being one.
 */
final class ContactFromMailController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    public function __construct(
        private readonly ContactManager $manager,
        private readonly AddressBookExtension $addressBook,
    ) {
    }

    #[Route('/contacts/from-mail', name: 'app_contacts_from_mail', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $this->assertCsrf($request, 'contacts_from_mail');

        $address = AddressHelper::email((string) $request->request->get('address'));

        if (false === AddressHelper::isValidEmail($address)) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Pressing it twice, or from two messages by the same sender, must not
        // make two contacts.
        $contactId = $this->addressBook->contactId($address);

        if (null === $contactId) {
            $name    = mb_substr(AddressHelper::name((string) $request->request->get('name')), 0, 200);
            $contact = $this->manager->create($user, [
                'displayName' => '' !== $name ? $name : $address,
                'source'      => 'mail',
            ]);
            $this->manager->addEmail($contact, $address, 'other', true);
            $contactId = $contact->id;
        }

        return $this->renderTurboStream('contacts/_from_mail.stream.html.twig', [
            'address'   => $address,
            'name'      => (string) $request->request->get('name'),
            'contactId' => $contactId,
        ]);
    }
}
