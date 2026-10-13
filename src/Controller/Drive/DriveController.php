<?php

declare(strict_types=1);

namespace App\Controller\Drive;

use App\Controller\ChecksCsrf;
use App\Entity\Drive\DriveFile;
use App\Entity\Drive\DriveFolder;
use App\Entity\Drive\DriveShare;
use App\Entity\User\User;
use App\Repository\Drive\DriveFileRepository;
use App\Repository\Drive\DriveFolderRepository;
use App\Repository\Drive\DriveShareRepository;
use App\Service\Drive\DrivePolicy;
use App\Service\Drive\DriveStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The user's personal Drive: folders, upload, download, rename, delete, share.
 *
 * Everything is looked up through the signed-in user (findOneForUser), never
 * by bare id, so another user's file is a 404 rather than a 403 that confirms
 * it exists. Files live on disk via DriveStorage; share links are public URLs
 * (/s/<token>) served by ShareController.
 *
 * CSRF: forms post `_token` (what ChecksCsrf reads). The compose picker and
 * the drag-and-drop uploader are fetch() callers and send the page's `ajax`
 * token in X-CSRF-Token instead.
 */
#[Route('/drive', name: 'app_drive_')]
#[IsGranted('ROLE_USER')]
final class DriveController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DriveFileRepository $files,
        private readonly DriveFolderRepository $folders,
        private readonly DriveShareRepository $shares,
        private readonly DriveStorage $storage,
        private readonly TranslatorInterface $translator,
        // Quotas, file-size limit and "Drive off" per user.
        private readonly DrivePolicy $policy,
    ) {}

    // ── Browsing ─────────────────────────────────────────────────────────────

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->renderFolder(null);
    }

    #[Route('/folder/{id<\d+>}', name: 'folder', methods: ['GET'])]
    public function folder(int $id): Response
    {
        return $this->renderFolder($this->ownFolder($id));
    }

    private function renderFolder(?DriveFolder $folder): Response
    {
        $user = $this->user();

        if ($this->policy->isDisabled($user)) {
            return $this->render('drive/disabled.html.twig', [], new Response(status: Response::HTTP_FORBIDDEN));
        }

        return $this->render('drive/list.html.twig', [
            'files'         => $this->files->findForUser($user, $folder),
            'folders'       => $this->folders->findForUser($user, $folder),
            'currentFolder' => $folder,
            'breadcrumbs'   => null !== $folder ? $this->folders->breadcrumbs($folder) : [],
            'usedBytes'     => $this->files->totalSizeForUser($user),
            'quotaBytes'    => $this->policy->quotaBytes($user),
            'shareCounts'   => $this->shares->activeCountsForUser($user),
            'maxUpload'     => $this->policy->maxFileBytes(),
        ]);
    }

    // ── Upload ───────────────────────────────────────────────────────────────

    /**
     * One or many files. The drag-and-drop uploader sends one file per request
     * (so a 200 MB batch never trips post_max_size) and wants JSON back; the
     * plain form fallback sends `files[]` and gets a redirect.
     */
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $user = $this->user();
        $wantsJson = str_contains((string) $request->headers->get('Accept'), 'application/json');

        $this->assertCsrf($request, $wantsJson ? 'ajax' : 'drive_upload');

        $folder = null;
        $folderId = (int) $request->request->get('folder_id', 0);
        if ($folderId > 0) {
            $folder = $this->ownFolder($folderId);
        }

        /** @var list<UploadedFile> $uploads */
        $uploads = array_values(array_filter(
            array_merge((array) ($request->files->all('files') ?: []), [$request->files->get('file')]),
            static fn($f) => $f instanceof UploadedFile,
        ));

        $error = null;
        $stored = 0;
        $storedFiles = [];

        if ($this->policy->isDisabled($user)) {
            $uploads = [];
            $error = 'drive.disabled_notice';
        }

        $quota = $this->policy->quotaBytes($user);
        $maxFile = $this->policy->maxFileBytes();

        // An empty $_FILES on a POST that clearly carried a body means PHP
        // threw the whole request away for exceeding post_max_size.
        if ([] === $uploads && null === $error) {
            $error = $request->server->getInt('CONTENT_LENGTH') > 0 ? 'drive.upload_too_large' : 'drive.upload_invalid';
        }

        $used = $this->files->totalSizeForUser($user);

        foreach ($uploads as $uploaded) {
            if (false === $uploaded->isValid()) {
                $error = \in_array($uploaded->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'drive.upload_too_large'
                    : 'drive.upload_invalid';
                continue;
            }

            $size = (int) $uploaded->getSize();
            if ($size > $maxFile) {
                $error = 'drive.upload_too_large';
                continue;
            }
            if (null !== $quota && $used + $size > $quota) {
                $error = 'drive.quota_exceeded';
                break;
            }

            $file = DriveFile::create(
                $user,
                '' !== trim($uploaded->getClientOriginalName()) ? $uploaded->getClientOriginalName() : 'upload',
                substr($uploaded->getMimeType() ?? 'application/octet-stream', 0, 100),
                $size,
                $folder,
            );
            $this->em->persist($file);
            $this->em->flush();

            try {
                $this->storage->store($uploaded, $file);
            } catch (\Throwable) {
                // No row without its bytes: a listed file that 404s on
                // download is worse than an upload that says it failed.
                $this->em->remove($file);
                $this->em->flush();
                $error = 'drive.upload_failed';
                continue;
            }

            $used += $size;
            ++$stored;
            $storedFiles[] = ['id' => $file->id, 'filename' => $file->filename, 'size' => $file->sizeBytes];
        }

        if ($wantsJson) {
            return $this->json(
                ['stored' => $stored, 'files' => $storedFiles, 'error' => null !== $error ? $this->trans($error) : null],
                null !== $error && 0 === $stored ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
            );
        }

        if ($stored > 0) {
            $this->addFlash('success', 1 === $stored ? 'drive.uploaded' : 'drive.uploaded_many');
        }
        if (null !== $error) {
            $this->addFlash('error', $error);
        }

        return $this->backTo($folder);
    }

    // ── Folders ──────────────────────────────────────────────────────────────

    #[Route('/folder/new', name: 'folder_new', methods: ['GET', 'POST'])]
    public function folderNew(Request $request): Response
    {
        $user = $this->user();
        $parentId = (int) ($request->request->get('parent_id') ?? $request->query->get('parent', 0));
        $parent = $parentId > 0 ? $this->ownFolder($parentId) : null;

        if ($request->isMethod('GET')) {
            return $this->render('drive/_name_modal.html.twig', [
                'title'   => 'drive.new_folder',
                'action'  => $this->generateUrl('app_drive_folder_new'),
                'tokenId' => 'drive_folder_new',
                'value'   => '',
                'hidden'  => null !== $parent ? ['parent_id' => $parent->id] : [],
                'submit'  => 'drive.create',
                'label'   => 'drive.folder_name',
            ]);
        }

        $this->assertCsrf($request, 'drive_folder_new');

        $name = trim((string) $request->request->get('name', ''));
        if ('' !== $name) {
            $this->em->persist(DriveFolder::create($user, $name, $parent));
            $this->em->flush();
            $this->addFlash('success', 'drive.folder_created');
        }

        return $this->backTo($parent);
    }

    #[Route('/folder/{id<\d+>}/rename', name: 'folder_rename', methods: ['GET', 'POST'])]
    public function folderRename(Request $request, int $id): Response
    {
        $folder = $this->ownFolder($id);

        if ($request->isMethod('GET')) {
            return $this->render('drive/_name_modal.html.twig', [
                'title'   => 'drive.rename_folder',
                'action'  => $this->generateUrl('app_drive_folder_rename', ['id' => $folder->id]),
                'tokenId' => 'drive_folder_rename_' . $folder->id,
                'value'   => $folder->name,
                'hidden'  => [],
                'submit'  => 'drive.rename',
                'label'   => 'drive.folder_name',
            ]);
        }

        $this->assertCsrf($request, 'drive_folder_rename_' . $folder->id);

        $name = trim((string) $request->request->get('name', ''));
        if ('' !== $name) {
            $folder->rename($name);
            $this->em->flush();
        }

        return $this->backTo($folder->parent);
    }

    /**
     * Deletes the folder, everything under it, and the bytes on disk.
     *
     * The foreign keys alone would cascade the folders but only SET NULL the
     * files, which would have dropped every file in a "deleted" folder back
     * into the root — the user asked for them gone.
     */
    #[Route('/folder/{id<\d+>}/delete', name: 'folder_delete', methods: ['POST'])]
    public function folderDelete(Request $request, int $id): Response
    {
        $user = $this->user();
        $folder = $this->ownFolder($id);

        $this->assertCsrf($request, 'drive_folder_delete_' . $folder->id);

        $parent = $folder->parent;
        foreach ($this->files->findInFolders($user, $this->folders->subtreeIds($folder)) as $file) {
            $this->storage->delete($file);
            $this->em->remove($file);
        }
        $this->em->remove($folder);
        $this->em->flush();

        $this->addFlash('success', 'drive.folder_deleted');

        return $this->backTo($parent);
    }

    // ── Files ────────────────────────────────────────────────────────────────

    #[Route('/{id<\d+>}/download', name: 'download', methods: ['GET'])]
    public function download(Request $request, int $id): Response
    {
        $file = $this->ownFile($id);

        if (false === $this->storage->exists($file)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($this->storage->path($file));
        $response->headers->set('Content-Type', $file->mimeType);
        // Inline only for types a browser shows without running anything of
        // the file's own; everything else is a download.
        $inline = $request->query->getBoolean('preview') && $this->isSafeInline($file->mimeType);
        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->filename,
            $this->asciiFallback($file->filename),
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/{id<\d+>}/rename', name: 'rename', methods: ['GET', 'POST'])]
    public function rename(Request $request, int $id): Response
    {
        $file = $this->ownFile($id);

        if ($request->isMethod('GET')) {
            return $this->render('drive/_name_modal.html.twig', [
                'title'   => 'drive.rename_file',
                'action'  => $this->generateUrl('app_drive_rename', ['id' => $file->id]),
                'tokenId' => 'drive_rename_' . $file->id,
                'value'   => $file->filename,
                'hidden'  => [],
                'submit'  => 'drive.rename',
                'label'   => 'drive.name',
            ]);
        }

        $this->assertCsrf($request, 'drive_rename_' . $file->id);

        $name = trim((string) $request->request->get('name', ''));
        if ('' !== $name) {
            $file->rename($name);
            $this->em->flush();
        }

        return $this->backTo($file->folder);
    }

    #[Route('/{id<\d+>}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $file = $this->ownFile($id);

        $this->assertCsrf($request, 'drive_delete_' . $file->id);

        $folder = $file->folder;
        $this->storage->delete($file);
        $this->em->remove($file);
        $this->em->flush();

        $this->addFlash('success', 'drive.deleted');

        return $this->backTo($folder);
    }

    // ── Sharing ──────────────────────────────────────────────────────────────

    /**
     * The share dialog: existing links (with revoke) and a form for a new one.
     * POST creates a link and answers with the same dialog, now showing it —
     * the form carries data-ui--modal-keep-open so the modal stays put.
     */
    #[Route('/{id<\d+>}/share', name: 'share', methods: ['GET', 'POST'])]
    public function share(Request $request, int $id): Response
    {
        $file = $this->ownFile($id);
        $created = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'drive_share_' . $file->id);

            if ($this->policy->isDisabled($this->user())) {
                return $this->renderShareDialog($file, null, 'drive.disabled_notice');
            }

            $expiresAt = null;
            $expires = (string) $request->request->get('expires', '');
            if ('' !== $expires) {
                $expiresAt = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires) ?: null;
                // End of the chosen day: "expires on the 12th" means the 12th still works.
                $expiresAt = $expiresAt?->setTime(23, 59, 59);
                if (null === $expiresAt || $expiresAt < new \DateTimeImmutable()) {
                    $error = 'drive.share_bad_date';
                }
            }

            if (null === $error) {
                $password = (string) $request->request->get('password', '');
                $created = DriveShare::create($file, '' !== $password ? $password : null, $expiresAt);
                $this->em->persist($created);
                $this->em->flush();
            }
        }

        return $this->renderShareDialog($file, $created, $error);
    }

    #[Route('/share/{id<\d+>}/revoke', name: 'share_revoke', methods: ['POST'])]
    public function shareRevoke(Request $request, int $id): Response
    {
        $share = $this->shares->find($id);
        if (null === $share || $share->file->usr->id !== $this->user()->id) {
            throw $this->createNotFoundException();
        }

        $this->assertCsrf($request, 'drive_share_revoke_' . $share->id);

        $file = $share->file;
        $this->em->remove($share);
        $this->em->flush();

        return $this->renderShareDialog($file, null, null);
    }

    private function renderShareDialog(DriveFile $file, ?DriveShare $created, ?string $error): Response
    {
        return $this->render('drive/_share_modal.html.twig', [
            'file'    => $file,
            'links'   => $this->shares->findActiveForFile($file),
            'created' => $created,
            'error'   => $error,
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    // ── Compose picker ───────────────────────────────────────────────────────

    #[Route('/files.json', name: 'files_json', methods: ['GET'])]
    public function filesJson(): JsonResponse
    {
        $files = [];

        foreach ($this->files->findAllForUser($this->user()) as $file) {
            $files[] = [
                'id'       => $file->id,
                'filename' => $file->filename,
                'size'     => $file->sizeBytes,
                'folder'   => $file->folder?->name,
            ];
        }

        return $this->json(['files' => $files, 'driveUrl' => $this->generateUrl('app_drive_list')]);
    }

    /**
     * A link the recipient can actually open. The picker used to paste the
     * owner's own /drive/<id>/download URL into the mail, which sends anyone
     * who clicks it to a login page.
     */
    #[Route('/{id<\d+>}/link.json', name: 'link_json', methods: ['POST'])]
    public function linkJson(Request $request, int $id): JsonResponse
    {
        $this->assertCsrf($request, 'ajax');

        $file = $this->ownFile($id);
        if ($this->policy->isDisabled($this->user())) {
            return $this->json(['error' => $this->trans('drive.disabled_notice')], Response::HTTP_FORBIDDEN);
        }
        $share = DriveShare::create($file, null, null);
        $this->em->persist($share);
        $this->em->flush();

        return $this->json([
            'url'      => $this->generateUrl('app_share_access', ['token' => $share->token], UrlGeneratorInterface::ABSOLUTE_URL),
            'filename' => $file->filename,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function ownFile(int $id): DriveFile
    {
        return $this->files->findOneForUser($this->user(), $id) ?? throw $this->createNotFoundException();
    }

    private function ownFolder(int $id): DriveFolder
    {
        return $this->folders->findOneForUser($this->user(), $id) ?? throw $this->createNotFoundException();
    }

    private function backTo(?DriveFolder $folder): Response
    {
        return null !== $folder
            ? $this->redirectToRoute('app_drive_folder', ['id' => $folder->id], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_drive_list', [], Response::HTTP_SEE_OTHER);
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key);
    }

    private function isSafeInline(string $mime): bool
    {
        return \in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'video/mp4', 'audio/mpeg'], true);
    }

    private function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $name) ?? 'download';

        return str_replace(['/', '\\', '%'], '_', $ascii);
    }

    private function maxUploadBytes(): int
    {
        $toBytes = static function (string $v): int {
            $v = trim($v);
            $n = (int) $v;

            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => $n,
            };
        };

        $limits = array_filter([
            $toBytes((string) \ini_get('upload_max_filesize')),
            $toBytes((string) \ini_get('post_max_size')),
        ]);

        return [] === $limits ? 0 : min($limits);
    }
}
