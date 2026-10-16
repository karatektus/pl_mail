<?php

declare(strict_types=1);

namespace App\Controller\AddressBook;

use App\Controller\ChecksCsrf;
use App\Entity\AddressBook\AddressBook;
use App\Entity\AddressBook\ContactCard;
use App\Entity\User\User;
use App\Form\ContactCardType;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\AddressBook\ContactCardRepository;
use App\Service\AddressBook\ContactCardEditor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The contacts page: every card in every mirrored address book, searchable,
 * and editable where the server allows it.
 *
 * Edits go through ContactCardEditor — saved locally, then pushed by the
 * worker — so nothing on this page waits on the CardDAV server. The list
 * answers a search from inside its own Turbo Frame, and every mutation answers
 * with a stream that redraws it.
 *
 * Ownership is checked against the card's book on every route that takes an
 * id. A card id is a sequence number; without the check, guessing one is
 * reading somebody else's address book.
 */
#[Route('/contacts', name: 'app_contacts_')]
#[IsGranted('IS_AUTHENTICATED')]
final class AddressBookController extends AbstractController
{
    use ChecksCsrf;

    /** More than anyone scrolls; a search narrows it. */
    private const int PAGE = 300;

    public function __construct(
        private readonly AddressBookRepository $books,
        private readonly ContactCardRepository $cards,
        private readonly ContactCardEditor     $editor,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user  = $this->currentUser();
        $query = trim($request->query->getString('q'));

        return $this->render('contacts/index.html.twig', [
            'query'      => $query,
            'cards'      => $this->cards->search($user, $query, self::PAGE),
            'books'      => $this->books->findForUser($user),
            'canCreate'  => [] !== $this->books->findWritableForUser($user),
            'pageLimit'  => self::PAGE,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user     = $this->currentUser();
        $writable = $this->books->findWritableForUser($user);

        if ([] === $writable) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ContactCardType::class, null, [
            'action' => $this->generateUrl('app_contacts_new'),
            'books'  => $writable,
        ]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $book = $form->get('addressBook')->getData();

            // The choice list is the writable books already; this is the
            // server half of that, for a POST that did not come from it.
            if (false === $book instanceof AddressBook || $book->usr !== $user || true === $book->isReadOnly) {
                throw $this->createAccessDeniedException();
            }

            $this->editor->create($book, ...$this->submitted($form));

            return $this->listStream('contacts.toast.created');
        }

        return $this->renderForm($form, null);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $card = $this->ownedCard($id);

        if (true === $card->addressBook->isReadOnly) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(ContactCardType::class, [
            'fullName'     => $card->fullName,
            'emails'       => implode("\n", $card->emails),
            'phones'       => implode("\n", $card->phones),
            'organization' => $card->organization,
            'note'         => $card->note,
        ], [
            'action' => $this->generateUrl('app_contacts_edit', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->editor->update($card, ...$this->submitted($form));

            return $this->listStream('contacts.toast.saved');
        }

        return $this->renderForm($form, $card);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $card = $this->ownedCard($id);
        $this->assertCsrf($request, 'contact-delete' . $id);

        if (true === $card->addressBook->isReadOnly) {
            throw $this->createAccessDeniedException();
        }

        $this->editor->delete($card);

        return $this->listStream('contacts.toast.deleted');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function ownedCard(int $id): ContactCard
    {
        $card = $this->cards->find($id);

        if (null === $card || $card->addressBook->usr !== $this->currentUser()) {
            throw $this->createNotFoundException();
        }

        return $card;
    }

    /**
     * The submitted values, in the editor's argument order.
     *
     * @return array{fullName: string, emails: list<string>, phones: list<string>, organization: ?string, note: ?string}
     */
    private function submitted(FormInterface $form): array
    {
        return [
            'fullName'     => (string) $form->get('fullName')->getData(),
            'emails'       => ContactCardType::lines($form->get('emails')->getData()),
            'phones'       => ContactCardType::lines($form->get('phones')->getData()),
            'organization' => $this->nullable($form->get('organization')->getData()),
            'note'         => $this->nullable($form->get('note')->getData()),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    /**
     * 422 for a refused submit, so the modal stays open with the errors — see
     * CalendarSettingsController::renderForm().
     */
    private function renderForm(FormInterface $form, ?ContactCard $card): Response
    {
        $status = true === $form->isSubmitted() && false === $form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('contacts/_form.html.twig', [
            'form' => $form,
            'card' => $card,
        ], new Response(status: $status));
    }

    private function listStream(string $toastMessage): Response
    {
        $user = $this->currentUser();

        return $this->render('contacts/_list.stream.html.twig', [
            'toastMessage' => $toastMessage,
            'cards'        => $this->cards->search($user, '', self::PAGE),
            'query'        => '',
            'pageLimit'    => self::PAGE,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
