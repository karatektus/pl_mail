<?php

declare(strict_types=1);

namespace App\Controller\Contacts;

use App\Controller\ChecksCsrf;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactMergeRecord;
use App\Entity\Contacts\ContactRelation;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\ContactManager;
use App\Service\Contacts\ContactMerger;
use App\Service\Contacts\ContactPhotoStore;
use App\Service\Contacts\CsvExporter;
use App\Service\Contacts\CsvImporter;
use App\Service\Contacts\DuplicateFinder;
use App\Service\Contacts\ImportRunner;
use App\Service\Contacts\ReferenceEmailFinder;
use App\Service\Contacts\VCardExporter;
use App\Service\Contacts\VCardImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
        private readonly \App\Repository\Note\NoteRepository $notes,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    // ── List / show ───────────────────────────────────────────────────────

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $user = $this->user();
        $q = trim((string) $request->query->get('q', ''));
        $tag = trim((string) $request->query->get('tag', ''));
        $page = max(1, $request->query->getInt('page', 1));

        // One extra row tells us whether a next page exists, without a
        // separate count query.
        $rows = $this->contacts->findForUser(
            $user,
            '' === $q ? null : $q,
            '' === $tag ? null : $tag,
            'name',
            self::PAGE_SIZE + 1,
            ($page - 1) * self::PAGE_SIZE,
        );
        $hasNext = \count($rows) > self::PAGE_SIZE;

        return $this->render('contacts/list.html.twig', [
            'contacts' => \array_slice($rows, 0, self::PAGE_SIZE),
            'q'        => $q,
            'tag'      => $tag,
            'page'     => $page,
            'hasNext'  => $hasNext,
            'tags'     => $this->contacts->findAllTagsForUser($user),
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
            'notes'        => $this->notes->findLinkedTo($user, 'contact', (string) $contact->id),
        ]);
    }

    // ── Create / edit ─────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('contacts/form.html.twig', [
            'contact'  => null,
            'isNew'    => true,
            'postUrl'  => $this->generateUrl('app_contacts_create'),
            'tokenId'  => 'contacts_create',
        ]);
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

            return $this->render('contacts/form.html.twig', [
                'contact' => null,
                'isNew'   => true,
                'postUrl' => $this->generateUrl('app_contacts_create'),
                'tokenId' => 'contacts_create',
                'draft'   => $all,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $contact = $this->manager->create($user, [
            'displayName' => $displayName,
            'givenName'   => $all['givenName'] ?? null,
            'familyName'  => $all['familyName'] ?? null,
            'nickname'    => $all['nickname'] ?? null,
            'company'     => $all['company'] ?? null,
            'jobTitle'    => $all['jobTitle'] ?? null,
            'notes'       => $all['notes'] ?? null,
            'tags'        => $this->parseTags((string) ($all['tags'] ?? '')),
            'source'      => 'manual',
        ]);

        $this->applyChildRows($contact, $all);
        $this->addFlash('success', $this->trans('contacts.created'));

        return $this->redirectToRoute('app_contacts_show', ['id' => $contact->id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id<\d+>}/edit', name: 'edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $contact = $this->ownContact($id);

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
        $contact = $this->ownContact($id);
        $this->assertCsrf($request, 'contacts_update_' . $id);

        $all = $request->request->all();
        $displayName = trim((string) ($all['displayName'] ?? ''));

        if ('' === $displayName) {
            $this->addFlash('error', $this->trans('contacts.validation.name_required'));

            return $this->redirectToRoute('app_contacts_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        $this->manager->update($contact, [
            'displayName' => $displayName,
            'givenName'   => $all['givenName'] ?? null,
            'familyName'  => $all['familyName'] ?? null,
            'nickname'    => $all['nickname'] ?? null,
            'company'     => $all['company'] ?? null,
            'jobTitle'    => $all['jobTitle'] ?? null,
            'notes'       => $all['notes'] ?? null,
            'tags'        => $this->parseTags((string) ($all['tags'] ?? '')),
        ]);

        // Replace-all for the child rows: the form always submits the full
        // set, so whatever is not in it was deleted in the UI.
        foreach (['emails', 'phones', 'addresses'] as $collection) {
            foreach ($contact->{$collection} as $row) {
                $this->em->remove($row);
            }
        }
        $this->em->flush();
        $this->applyChildRows($contact, $all);

        $this->addFlash('success', $this->trans('contacts.updated'));

        return $this->redirectToRoute('app_contacts_show', ['id' => $contact->id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $contact = $this->ownContact($id);
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

        $absolute = $this->projectDir . '/var/' . ltrim($contact->photoPath, '/');

        if (false === is_file($absolute)) {
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
        $contact = $this->ownContact($id);
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
     * @param array<string, mixed> $all
     * @return list<array{value: string, label: string, primary: bool}>
     */
    private function labeledRows(array $all, string $key): array
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
                'label'   => \in_array($label, ['home', 'work', 'other'], true) ? $label : 'other',
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

    /**
     * Adds the form's email/phone/address rows. Invalid rows are skipped one
     * by one (same rule as the importers) so one typo cannot sink the save.
     *
     * @param array<string, mixed> $all
     */
    private function applyChildRows(Contact $contact, array $all): void
    {
        foreach ($this->labeledRows($all, 'emails') as $row) {
            try {
                $this->manager->addEmail($contact, $row['value'], $row['label'], $row['primary']);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        foreach ($this->labeledRows($all, 'phones') as $row) {
            try {
                $this->manager->addPhone($contact, $row['value'], $row['label'], $row['primary']);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        foreach ($this->addressRows($all) as $row) {
            $this->manager->addAddress($contact, $row);
        }
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
