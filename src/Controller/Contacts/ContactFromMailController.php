<?php

declare(strict_types=1);

namespace App\Controller\Contacts;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\Helper\AddressHelper;
use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\ContactManager;
use App\Twig\AddressBookExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Add to contacts" on an address in the message view: the sender, and every
 * address in the message's details.
 *
 * Deliberately a button, not something that happens on its own: most of what
 * arrives is newsletters and no-reply addresses, and an address book that
 * fills itself with those stops being one.
 *
 * The button opens a dialog (choose()) rather than saving at once. It used to
 * save at once, and every address became a contact of its own: somebody
 * writing from a second address became a second person in the address book,
 * with no way to say "that is Ada" short of merging the two afterwards. The
 * dialog asks which it is — a new contact, named here or in the full editor,
 * or one already in the address book, found by search.
 */
final class ContactFromMailController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    /** How many contacts the search in the dialog offers. */
    private const int MATCHES = 8;

    public function __construct(
        private readonly ContactManager $manager,
        private readonly ContactRepository $contacts,
        private readonly AddressBookExtension $addressBook,
    ) {
    }

    /**
     * The dialog, in the modal frame. Its search is a GET form back to this
     * route, so a search re-renders the dialog without closing it.
     *
     * The search starts out as the sender's name: when the person is already
     * in the address book under another address, that is usually how they
     * are found, and the dialog can offer them before anything is typed.
     */
    #[Route('/contacts/from-mail', name: 'app_contacts_from_mail_choose', methods: ['GET'])]
    public function choose(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $address = AddressHelper::email((string) $request->query->get('address'));

        if (false === AddressHelper::isValidEmail($address)) {
            throw $this->createNotFoundException();
        }

        $name = $this->name((string) $request->query->get('name'));
        $q    = trim((string) $request->query->get('q', $name));

        return $this->render('contacts/_from_mail_dialog.html.twig', [
            'address'   => $address,
            'name'      => $name,
            'q'         => $q,
            'matches'   => '' === $q ? [] : $this->matches($user, $q),
            'contactId' => $this->addressBook->contactId($address),
        ]);
    }

    /**
     * "New contact": the address as a contact of its own, under the name the
     * dialog was given.
     */
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
            $name    = $this->name((string) $request->request->get('name'));
            $contact = $this->manager->create($user, [
                'displayName' => '' !== $name ? $name : $address,
                'source'      => 'mail',
            ]);
            $this->manager->addEmail($contact, $address, 'other', true);
            $contactId = $contact->id;
        }

        return $this->badges($address, (string) $request->request->get('name'), $contactId);
    }

    /**
     * "Add to an existing contact": the address joins a contact already in
     * the address book, beside the addresses it has. Not as its primary one —
     * the contact's own address is the one that was chosen for it, and an
     * address that turned up in a mail has not earned that.
     *
     * When the address already belongs to some contact, that contact is what
     * the badges point at and nothing is added: one address, one person, or
     * autocomplete and the sender badge would have to pick between two.
     */
    #[Route('/contacts/from-mail/{id<\d+>}', name: 'app_contacts_from_mail_attach', methods: ['POST'])]
    public function attach(int $id, Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $this->assertCsrf($request, 'contacts_from_mail');

        $address = AddressHelper::email((string) $request->request->get('address'));

        if (false === AddressHelper::isValidEmail($address)) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $contact = $this->contacts->findOneBy(['id' => $id, 'usr' => $user, 'trashedAt' => null])
            ?? throw $this->createNotFoundException();

        // The dialog never offers these; a hand-made request is refused.
        if (false === $contact->isEditable()) {
            throw $this->createAccessDeniedException();
        }

        $contactId = $this->addressBook->contactId($address);

        if (null === $contactId) {
            $this->manager->addEmail($contact, $address);
            $contactId = $contact->id;
        }

        return $this->badges($address, (string) $request->request->get('name'), $contactId);
    }

    /**
     * Contacts the address could join: the search's hits, without the ones in
     * a read-only book. Those are filtered here rather than in the query, so
     * a few more are read than are shown, enough that a read-only book
     * matching first does not leave the list short.
     *
     * @return list<Contact>
     */
    private function matches(User $user, string $q): array
    {
        $found = $this->contacts->findForUser($user, $q, null, 'name', self::MATCHES * 2, 0);

        return \array_slice(
            array_values(array_filter($found, static fn (Contact $contact): bool => $contact->isEditable())),
            0,
            self::MATCHES,
        );
    }

    /** The display name a mail gave, as a contact's name may hold it. */
    private function name(string $raw): string
    {
        return mb_substr(AddressHelper::name($raw), 0, 200);
    }

    /** Every badge for this address, switched to "in your contacts". */
    private function badges(string $address, string $name, int $contactId): Response
    {
        return $this->renderTurboStream('contacts/_from_mail.stream.html.twig', [
            'address'   => $address,
            'name'      => $name,
            'contactId' => $contactId,
        ]);
    }
}
