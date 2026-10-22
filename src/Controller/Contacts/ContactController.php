<?php

declare(strict_types=1);

namespace App\Controller\Contacts;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Contacts\ContactsPaneMode;
use App\Domain\Exception\ContactReadOnlyException;
use App\Domain\Helper\AddressHelper;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactMergeRecord;
use App\Entity\Contacts\ContactRelation;
use App\Entity\User\User;
use App\Repository\AddressBook\AddressBookRepository;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\ContactManager;
use App\Service\Contacts\ContactMerger;
use App\Service\Contacts\ContactPhotoStore;
use App\Service\Contacts\CsvExporter;
use App\Service\Contacts\CsvImporter;
use App\Service\Contacts\DuplicateFinder;
use App\Service\Contacts\ImportRunner;
use App\Service\Contacts\ContactFields;
use App\Service\Contacts\ParsedContact;
use App\Service\Contacts\ReferenceEmailFinder;
use App\Service\Contacts\VCardExporter;
use App\Service\Contacts\VCardImporter;
use App\Service\Contacts\VCardMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The address book: web UI over the same Contact backend the JMAP API and
 * the Android app use.
 *
 * Everything is looked up through the signed-in user (ownContact), never by
 * bare id, so another user's contact is a 404 rather than a 403 that confirms
 * it exists. Same rule as DriveController.
 *
 * A contact can live in plMail alone or in a synced address book (Nextcloud,
 * any CardDAV server). The pages treat both the same; ContactSyncTracker sends
 * an edit to a synced one on to its server. A contact in a read-only book is
 * shown and never changed here (editableContact()).
 *
 * A save is one flush. The form's rows are turned into a ParsedContact and
 * applied by VCardMapper, the code a sync uses, so rows that are still there
 * keep their ids and a synced contact is never pushed half-saved — which a
 * delete-everything-then-re-add over several flushes would do, if a sync ran
 * between them.
 *
 * CSRF: every mutating form posts `_token` (what ChecksCsrf reads).
 */
#[Route('/contacts', name: 'app_contacts_')]
#[IsGranted('ROLE_USER')]
final class ContactController extends AbstractController
{
    use ChecksCsrf;

    private const PAGE_SIZE = 50;
    private const PREVIEW_ROWS = 25;

    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly ContactManager $manager,
        private readonly ContactPhotoStore $photos,
        private readonly VCardImporter $vcardImporter,
        private readonly CsvImporter $csvImporter,
        private readonly ImportRunner $importRunner,
        private readonly VCardExporter $vcardExporter,
        private readonly CsvExporter $csvExporter,
        private readonly DuplicateFinder $duplicateFinder,
        private readonly ContactMerger $merger,
        private readonly ReferenceEmailFinder $referenceEmails,
        private readonly AddressBookRepository $addressBooks,
        private readonly VCardMapper $mapper,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    // ── The docked pane ───────────────────────────────────────────────────

    /**
     * Remembers whether the address book is docked beside the mail, and how
     * wide. The calendar's pane-state for the contacts pane — see
     * CalendarController::paneState(), whose reasons all apply: written on
     * release, never re-rendering, either field optional, clamped here.
     */
    #[Route('/pane-state', name: 'pane_state', methods: ['POST'])]
    public function paneState(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'contacts_pane_state');

        /** @var User $user */
        $user = $this->getUser();

        // `mode` is the switch's position (ContactsPaneMode); `open` the
        // older yes/no, still what the pane's × and a reveal send. A mode
        // that is not one of the three is not a choice and is left out.
        $mode = ContactsPaneMode::tryFrom((string) $request->request->get('mode'));

        if (null !== $mode) {
            $user->contactsPaneMode = $mode;
        } elseif (true === $request->request->has('open')) {
            $user->contactsPaneOpen = $request->request->getBoolean('open');
        }

        if (true === $request->request->has('width')) {
            $user->setSetting(User::SETTING_CONTACTS_PANE_WIDTH, max(
                User::CONTACTS_PANE_MIN_WIDTH,
                min(User::CONTACTS_PANE_MAX_WIDTH, $request->request->getInt('width')),
            ));
        }

        $this->em->flush();

        return $this->json([
            'open'  => $user->contactsPaneOpen,
            'mode'  => $user->contactsPaneMode->value,
            'width' => $user->contactsPaneWidth,
        ]);
    }

    // ── List / show ───────────────────────────────────────────────────────

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $user = $this->user();
        $q = trim((string) $request->query->get('q', ''));
        $tag = trim((string) $request->query->get('tag', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $books = $this->addressBooks->findForUser($user);
        $book = $this->bookFilter((string) $request->query->get('book', ''), $books);

        // One extra row tells us whether a next page exists, without a
        // separate count query.
        $rows = $this->contacts->findForUser(
            $user,
            '' === $q ? null : $q,
            '' === $tag ? null : $tag,
            'name',
            self::PAGE_SIZE + 1,
            ($page - 1) * self::PAGE_SIZE,
            $book,
        );
        $hasNext = \count($rows) > self::PAGE_SIZE;

        return $this->render('contacts/list.html.twig', [
            'contacts' => \array_slice($rows, 0, self::PAGE_SIZE),
            'q'        => $q,
            'tag'      => $tag,
            'page'     => $page,
            'hasNext'  => $hasNext,
            'tags'     => $this->contacts->findAllTagsForUser($user),
            'books'    => $books,
            'book'     => null === $book ? '' : (string) $book,
            'total'    => $this->contacts->countForUser($user, '' === $q ? null : $q, $book),
        ]);
    }

    #[Route('/{id<\d+>}', name: 'show', methods: ['GET'])]
    public function show(int $id): Response
    {
        $user = $this->user();
        $contact = $this->ownContact($id);

        $relationRepo = $this->em->getRepository(ContactRelation::class);

        return $this->render('contacts/show.html.twig', [
            'contact'      => $contact,
            'outRelations' => $relationRepo->findBy(['ownerContact' => $contact]),
            'inRelations'  => $relationRepo->findBy(['relatedContact' => $contact]),
            'refEmails'    => $this->referenceEmails->findForContact($user, $contact, 50),
        ]);
    }

    // ── Create / edit ─────────────────────────────────────────────────────

    /**
     * A new contact starts as quick add: one box to type or paste into, read
     * as it is typed (ContactAssistController::parse()), and the rows found
     * shown to keep, fix or untick. It posts to create() like the full editor
     * does, with the same field names.
     */
    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('contacts/new.html.twig', [
            'books' => $this->addressBooks->findWritableForUser($this->user()),
        ]);
    }

    /**
     * The full editor for a new contact: empty from a link, or holding what
     * quick add found when its "All fields" button posts here. Nothing is
     * saved by this route — the editor's own Save posts to create().
     *
     * A GET may carry `name` and `email`: "More details" in the add-to-contacts
     * dialog (ContactFromMailController) is a link, and starts the editor with
     * the address it was opened on. A link rather than a post, because Turbo
     * wants a post answered with a redirect, and this renders.
     */
    #[Route('/new/full', name: 'new_full', methods: ['GET', 'POST'])]
    public function newFull(Request $request): Response
    {
        $all = $request->isMethod('POST') ? $request->request->all() : [];

        if ([] !== $all) {
            $this->assertCsrf($request, 'contacts_create');
        } elseif ($request->query->has('email')) {
            $all = [
                'displayName' => AddressHelper::name((string) $request->query->get('name')),
                'emails'      => [['value' => (string) $request->query->get('email'), 'label' => 'other', 'primary' => '1']],
            ];
        }

        return $this->newForm($this->draftContact($all), (string) ($all['addressBook'] ?? ''));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $user = $this->user();
        $this->assertCsrf($request, 'contacts_create');

        $all = $request->request->all();
        $displayName = trim((string) ($all['displayName'] ?? ''));

        if ('' === $displayName) {
            $this->addFlash('error', $this->trans('contacts.validation.name_required'));

            return $this->newForm(
                $this->draftContact($all),
                (string) ($all['addressBook'] ?? ''),
                new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
            );
        }

        $contact = Contact::create($user, $displayName);
        $contact->addressBook = $this->chosenBook((string) ($all['addressBook'] ?? ''));
        $this->mapper->apply($this->parsedFromForm($all), $contact);

        $this->em->persist($contact);
        $this->em->flush();

        $this->addFlash('success', $this->trans('contacts.created'));

        return $this->redirectToRoute('app_contacts_show', ['id' => $contact->id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $contact = $this->editableContact($id);

        return $this->render('contacts/form.html.twig', [
            'contact' => $contact,
            'isNew'   => false,
            'postUrl' => $this->generateUrl('app_contacts_update', ['id' => $contact->id]),
            'tokenId' => 'contacts_update_' . $contact->id,
        ]);
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $contact = $this->editableContact($id);
        $this->assertCsrf($request, 'contacts_update_' . $id);

        $all = $request->request->all();
        $displayName = trim((string) ($all['displayName'] ?? ''));

        if ('' === $displayName) {
            $this->addFlash('error', $this->trans('contacts.validation.name_required'));

            return $this->redirectToRoute('app_contacts_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        // The form always submits the full set of rows, so whatever is not in
        // it was deleted in the UI; apply() reconciles to exactly that.
        $this->mapper->apply($this->parsedFromForm($all), $contact);
        $this->em->flush();

        $this->addFlash('success', $this->trans('contacts.updated'));

        return $this->redirectToRoute('app_contacts_show', ['id' => $contact->id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $contact = $this->editableContact($id);
        $this->assertCsrf($request, 'contacts_delete_' . $id);

        $this->manager->softDelete($contact);
        $this->addFlash('success', $this->trans('contacts.trashed'));

        return $this->redirectToRoute('app_contacts_list', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * The contact's photo for the web pages, on the session the page already
     * has. The pages used to load it from the JMAP route, whose firewall is
     * stateless and answers a browser with a Basic challenge — so every page
     * showing a photo popped up a "Sign in" box nothing typed into could pass.
     * The Android app keeps using the JMAP route with its own token.
     */
    #[Route('/{id<\d+>}/photo', name: 'photo_show', methods: ['GET'])]
    public function photoShow(int $id): Response
    {
        $contact = $this->ownContact($id);

        if (null === $contact->photoPath || '' === $contact->photoPath) {
            throw $this->createNotFoundException();
        }

        $absolute = $this->photos->absolutePath($contact->photoPath);

        if (null === $absolute || false === is_file($absolute)) {
            throw $this->createNotFoundException();
        }

        $mime = match (strtolower((string) pathinfo($contact->photoPath, \PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            default       => 'application/octet-stream',
        };

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $mime);
        $response->setPrivate();

        return $response;
    }

    #[Route('/{id<\d+>}/photo', name: 'photo', methods: ['POST'])]
    public function photo(int $id, Request $request): Response
    {
        $user = $this->user();
        $contact = $this->editableContact($id);
        $this->assertCsrf($request, 'contacts_photo_' . $id);

        $file = $request->files->get('photo');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->trans('contacts.photo_invalid'));

            return $this->redirectToRoute('app_contacts_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        try {
            $newPath = $this->photos->store($file, (int) $user->id);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $this->trans('contacts.photo_invalid'));

            return $this->redirectToRoute('app_contacts_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        $oldPath = $contact->photoPath;
        $contact->photoPath = $newPath;
        $this->em->flush();

        if (null !== $oldPath && '' !== $oldPath) {
            $this->photos->delete($oldPath);
        }

        $this->addFlash('success', $this->trans('contacts.photo_uploaded'));

        return $this->redirectToRoute('app_contacts_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /**
     * Remove the photo. For a synced contact the removal goes to the server
     * like any edit (ContactSyncTracker); Outlook cannot remove one, see
     * GraphContactsDriver.
     */
    #[Route('/{id<\d+>}/photo/remove', name: 'photo_remove', methods: ['POST'])]
    public function photoRemove(int $id, Request $request): Response
    {
        $contact = $this->editableContact($id);
        $this->assertCsrf($request, 'contacts_photo_' . $id);

        $oldPath = $contact->photoPath;

        if (null !== $oldPath) {
            $contact->photoPath = null;
            $this->em->flush();

            if ('' !== $oldPath) {
                $this->photos->delete($oldPath);
            }

            $this->addFlash('success', $this->trans('contacts.photo_removed'));
        }

        return $this->redirectToRoute('app_contacts_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    // ── Import / export ───────────────────────────────────────────────────

    #[Route('/import', name: 'import', methods: ['GET'])]
    public function import(): Response
    {
        return $this->render('contacts/import.html.twig', [
            'preview'  => null,
            'tokenId'  => 'contacts_import',
        ]);
    }

    #[Route('/import', name: 'import_upload', methods: ['POST'])]
    public function importUpload(Request $request): Response
    {
        $user = $this->user();
        $this->assertCsrf($request, 'contacts_import');

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->trans('contacts.import_no_file'));

            return $this->redirectToRoute('app_contacts_import', [], Response::HTTP_SEE_OTHER);
        }

        $format = $this->detectImportFormat($file->getClientOriginalName());

        if (null === $format) {
            $this->addFlash('error', $this->trans('contacts.import_bad_format'));

            return $this->redirectToRoute('app_contacts_import', [], Response::HTTP_SEE_OTHER);
        }

        $dir = $this->stagingDir((int) $user->id);
        $this->pruneStaging($dir);

        $stored = bin2hex(random_bytes(8)) . '.' . $format;
        $file->move($dir, $stored);
        $request->getSession()->set('contacts_import', ['file' => $stored, 'format' => $format]);

        $dtos = $this->parseImportFile($dir . '/' . $stored, $format);

        return $this->render('contacts/import.html.twig', [
            'preview'      => \array_slice($dtos, 0, self::PREVIEW_ROWS),
            'previewTotal' => \count($dtos),
            'format'       => $format,
            'tokenId'      => 'contacts_import_confirm',
        ]);
    }

    #[Route('/import/confirm', name: 'import_confirm', methods: ['POST'])]
    public function importConfirm(Request $request): Response
    {
        $user = $this->user();
        $this->assertCsrf($request, 'contacts_import_confirm');

        $staged = $request->getSession()->get('contacts_import');

        if (!\is_array($staged) || !isset($staged['file'], $staged['format'])) {
            $this->addFlash('error', $this->trans('contacts.import_expired'));

            return $this->redirectToRoute('app_contacts_import', [], Response::HTTP_SEE_OTHER);
        }

        $path = $this->stagingDir((int) $user->id) . '/' . basename((string) $staged['file']);

        if (!is_file($path)) {
            $request->getSession()->remove('contacts_import');
            $this->addFlash('error', $this->trans('contacts.import_expired'));

            return $this->redirectToRoute('app_contacts_import', [], Response::HTTP_SEE_OTHER);
        }

        $source = 'import:' . $staged['format'];
        if (!\in_array($source, ['import:vcf', 'import:csv'], true)) {
            $request->getSession()->remove('contacts_import');
            $this->addFlash('error', $this->trans('contacts.import_bad_format'));

            return $this->redirectToRoute('app_contacts_import', [], Response::HTTP_SEE_OTHER);
        }

        $dtos = $this->parseImportFile($path, (string) $staged['format']);
        $result = $this->importRunner->run($user, $dtos, $source);

        @unlink($path);
        $request->getSession()->remove('contacts_import');

        $this->addFlash('success', $this->trans('contacts.imported', ['%count%' => \count($result['imported'])]));

        return $this->redirectToRoute('app_contacts_duplicates', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/export.vcf', name: 'export_vcf', methods: ['GET'])]
    public function exportVcf(): Response
    {
        $response = new Response($this->vcardExporter->exportAll($this->user()));
        $response->headers->set('Content-Type', 'text/vcard; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'contacts.vcf'));

        return $response;
    }

    #[Route('/export.csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(): Response
    {
        $response = new Response($this->csvExporter->exportAll($this->user()));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'contacts.csv'));

        return $response;
    }

    // ── Duplicates / merge ────────────────────────────────────────────────

    #[Route('/duplicates', name: 'duplicates', methods: ['GET'])]
    public function duplicates(): Response
    {
        $user = $this->user();

        return $this->render('contacts/duplicates.html.twig', [
            'pairs'   => $this->duplicateFinder->findCandidates($user),
            'merges'  => $this->em->getRepository(ContactMergeRecord::class)
                ->findBy(['usr' => $user], ['mergedAt' => 'DESC'], 10),
            'now'     => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/merge', name: 'merge', methods: ['POST'])]
    public function merge(Request $request): Response
    {
        $user = $this->user();
        $this->assertCsrf($request, 'contacts_merge');

        $all = $request->request->all();
        $keepId = (int) ($all['keep_id'] ?? 0);
        $mergeIds = array_map('intval', (array) ($all['merge_ids'] ?? []));

        if ($keepId <= 0 || [] === $mergeIds) {
            $this->addFlash('error', $this->trans('contacts.merge_pick_keep'));

            return $this->redirectToRoute('app_contacts_duplicates', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->merger->merge($keepId, $mergeIds, $user);
        } catch (ContactReadOnlyException) {
            $this->addFlash('error', $this->trans('contacts.merge_read_only'));

            return $this->redirectToRoute('app_contacts_duplicates', [], Response::HTTP_SEE_OTHER);
        } catch (\InvalidArgumentException $e) {
            throw $this->createNotFoundException();
        }

        $this->addFlash('success', $this->trans('contacts.merged'));

        return $this->redirectToRoute('app_contacts_duplicates', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/merge/{recordId<\d+>}/undo', name: 'merge_undo', methods: ['POST'])]
    public function mergeUndo(int $recordId, Request $request): Response
    {
        $user = $this->user();
        $this->assertCsrf($request, 'contacts_merge_undo_' . $recordId);

        try {
            $this->merger->undo($recordId, $user);
        } catch (\InvalidArgumentException $e) {
            throw $this->createNotFoundException();
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->trans('contacts.merge_undo_expired'));

            return $this->redirectToRoute('app_contacts_duplicates', [], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', $this->trans('contacts.merge_undone'));

        return $this->redirectToRoute('app_contacts_list', [], Response::HTTP_SEE_OTHER);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function ownContact(int $id): Contact
    {
        return $this->contacts->findOneBy(['id' => $id, 'usr' => $this->user(), 'trashedAt' => null])
            ?? throw $this->createNotFoundException();
    }

    /**
     * A contact the person may change: their own, and not in a read-only
     * book. The pages offer no edit for the others; a hand-made request gets
     * a 403.
     */
    private function editableContact(int $id): Contact
    {
        $contact = $this->ownContact($id);

        if (false === $contact->isEditable()) {
            throw $this->createAccessDeniedException();
        }

        return $contact;
    }

    /**
     * The list's book filter: 'local', or the id of one of the person's books.
     * Anything else shows everything.
     *
     * @param list<AddressBook> $books
     */
    private function bookFilter(string $raw, array $books): int|string|null
    {
        if ('local' === $raw) {
            return 'local';
        }

        foreach ($books as $book) {
            if ((string) $book->id === $raw) {
                return $book->id;
            }
        }

        return null;
    }

    /**
     * Where a new contact goes: plMail's own contacts (''), or one of the
     * person's writable books. A book that is not theirs, or is read-only,
     * is refused rather than quietly ignored.
     */
    private function chosenBook(string $raw): ?AddressBook
    {
        if ('' === $raw) {
            return null;
        }

        foreach ($this->addressBooks->findWritableForUser($this->user()) as $book) {
            if ((string) $book->id === $raw) {
                return $book;
            }
        }

        throw $this->createAccessDeniedException();
    }

    /**
     * The editor for a contact that does not exist yet.
     */
    private function newForm(Contact $draft, string $book, ?Response $response = null): Response
    {
        return $this->render('contacts/form.html.twig', [
            'contact' => $draft,
            'isNew'   => true,
            'postUrl' => $this->generateUrl('app_contacts_create'),
            'tokenId' => 'contacts_create',
            'books'   => $this->addressBooks->findWritableForUser($this->user()),
            'book'    => $book,
        ], $response);
    }

    /**
     * An unsaved contact holding what a form posted — quick add's rows, or a
     * create that was refused — so the editor has one shape to show, saved
     * or not. Never persisted: nothing managed points at it, so a flush
     * elsewhere in the request cannot pick it up. The name is the one typed,
     * even empty; VCardMapper would otherwise make one up from an address,
     * and the editor would hide the reason the save was refused.
     *
     * @param array<string, mixed> $all
     */
    private function draftContact(array $all): Contact
    {
        $contact = Contact::create($this->user(), '');

        if ([] !== $all) {
            $this->mapper->apply($this->parsedFromForm($all), $contact);
        }

        $contact->displayName = trim((string) ($all['displayName'] ?? ''));

        return $contact;
    }

    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }

    /**
     * @return list<string>
     */
    private function parseTags(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $tag) {
            $tag = trim($tag);
            if ('' !== $tag && !\in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    /**
     * The form, as the ParsedContact VCardMapper::apply() takes. Rows that
     * are not usable — an address that is not one, a phone with no digits, an
     * empty postal address — are dropped one by one (the importers' rule), so
     * one typo cannot sink the save. Only the first row ticked primary of
     * each kind is.
     *
     * @param array<string, mixed> $all
     */
    private function parsedFromForm(array $all): ParsedContact
    {
        $parsed = new ParsedContact();

        $parsed->displayName = trim((string) ($all['displayName'] ?? ''));

        foreach (ContactFields::SCALARS as $key) {
            $value = trim((string) ($all[$key] ?? ''));
            $parsed->{$key} = '' === $value ? null : $value;
        }

        $parsed->birthday = ContactFields::fromForm($all['birthday'] ?? null);

        // A date row comes as day, month and year; the cleaner wants the date.
        $all['dates'] = array_map(
            static fn (mixed $row): mixed => is_array($row) ? ['label' => $row['label'] ?? '', 'date' => (string) ContactFields::fromForm($row)] : $row,
            is_array($all['dates'] ?? null) ? $all['dates'] : [],
        );

        foreach (ContactFields::LISTS as $list) {
            $parsed->{$list} = ContactFields::clean($list, $all[$list] ?? []);
        }

        $parsed->tags = $this->parseTags((string) ($all['tags'] ?? ''));

        $primary = false;
        foreach ($this->labeledRows($all, 'emails', ['home', 'work', 'other']) as $row) {
            $email = AddressHelper::email($row['value']);
            if (false === AddressHelper::isValidEmail($email)) {
                continue;
            }
            $parsed->emails[] = ['email' => $email, 'label' => $row['label'], 'primary' => $row['primary'] && !$primary];
            $primary = $primary || $row['primary'];
        }

        $primary = false;
        foreach ($this->labeledRows($all, 'phones', ['mobile', 'home', 'work', 'other']) as $row) {
            if ('' === (preg_replace('/\D+/', '', $row['value']) ?? '')) {
                continue;
            }
            $parsed->phones[] = ['phone' => $row['value'], 'label' => $row['label'], 'primary' => $row['primary'] && !$primary];
            $primary = $primary || $row['primary'];
        }

        $parsed->addresses = $this->addressRows($all);

        return $parsed;
    }

    /**
     * @param array<string, mixed> $all
     * @param list<string>         $labels the labels this kind of row may carry
     * @return list<array{value: string, label: string, primary: bool}>
     */
    private function labeledRows(array $all, string $key, array $labels): array
    {
        $rows = $all[$key] ?? [];
        if (!\is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $value = trim((string) ($row['value'] ?? ''));
            if ('' === $value) {
                continue;
            }
            $label = (string) ($row['label'] ?? 'other');
            $out[] = [
                'value'   => $value,
                'label'   => \in_array($label, $labels, true) ? $label : 'other',
                'primary' => isset($row['primary']) && '1' === (string) $row['primary'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $all
     * @return list<array{label: string, street: ?string, city: ?string, region: ?string, postcode: ?string, country: ?string}>
     */
    private function addressRows(array $all): array
    {
        $rows = $all['addresses'] ?? [];
        if (!\is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $fields = [];
            foreach (['street', 'city', 'region', 'postcode', 'country'] as $key) {
                $v = trim((string) ($row[$key] ?? ''));
                $fields[$key] = '' === $v ? null : $v;
            }
            if ([] === array_filter($fields)) {
                continue;
            }
            $label = (string) ($row['label'] ?? 'other');
            $out[] = ['label' => \in_array($label, ['home', 'work', 'other'], true) ? $label : 'other'] + $fields;
        }

        return $out;
    }

    private function detectImportFormat(string $filename): ?string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'vcf', 'vcard' => 'vcf',
            'csv' => 'csv',
            default => null,
        };
    }

    private function stagingDir(int $userId): string
    {
        $dir = $this->projectDir . '/var/contacts/_staging/' . $userId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Old staged uploads are abandoned imports; sweep them so a forgotten
     * preview cannot be confirmed days later against a changed address book.
     */
    private function pruneStaging(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }

    /**
     * @return list<\App\Service\Contacts\ParsedContact>
     */
    private function parseImportFile(string $path, string $format): array
    {
        $content = (string) file_get_contents($path);

        return 'vcf' === $format
            ? $this->vcardImporter->import($content)
            : $this->csvImporter->import($content);
    }
}
