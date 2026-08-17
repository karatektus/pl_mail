<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Domain\DTO\Backup\ConfigBackupPlan;
use App\Domain\Enum\Backup\ConfigBackupFailure;
use App\Domain\Exception\ConfigBackupException;
use App\Domain\Exception\InvalidFirebaseCredentialsException;
use App\Infrastructure\Backup\ConfigBackupCipher;
use App\Service\Backup\ConfigBackupExporter;
use App\Service\Backup\ConfigBackupImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Config backup: carrying one installation's setup to another, as a
 * single password-encrypted file.
 *
 * **Four actions and no GET that does anything.** The panel renders; export,
 * import and apply are POST-only and CSRF-checked, because each of them either
 * hands out every credential the install owns or replaces them. A GET that
 * downloaded the file would be a URL that could be embedded in an image tag on
 * another site and fetched with the admin's own cookies.
 *
 * **Import is two requests on purpose**, and the second one carries the
 * envelope back rather than the server remembering it. Nothing about the upload
 * is stored between them — not in a temporary file, not in the session — which
 * is the promise the page makes and the only one worth making about a file that
 * decrypts to every secret in the install. What travels in the hidden field is
 * the ciphertext exactly as uploaded; it is no more readable in the page than
 * it was on the admin's disk, and the password has to be typed again to open
 * it. That retype is not friction for its own sake: it is what makes "apply"
 * a second deliberate act rather than a button next to a list.
 *
 * **Errors re-render the panel rather than redirecting.** A wrong password has
 * to come back with the import form open and nothing changed, and a redirect
 * would need somewhere to carry the reason — a query string, which is where an
 * admin's failed attempt would end up in a proxy log.
 */
#[Route('/admin/config-backup', name: 'app_admin_config_backup_')]
#[IsGranted('ROLE_ADMIN')]
final class ConfigBackupController extends AbstractController
{
    use ChecksCsrf;

    /**
     * Largest upload accepted, before anything is decoded.
     *
     * A real backup is a few kilobytes: a couple of PEMs, a Firebase key and
     * two dozen environment lines. One megabyte is three orders of magnitude of
     * headroom and still small enough that a mistaken upload of a mailbox
     * archive is refused as the mistake it is rather than parsed as JSON.
     */
    private const int MAX_UPLOAD_BYTES = 1048576;

    public function __construct(
        private readonly ConfigBackupExporter $exporter,
        private readonly ConfigBackupImporter $importer,
    ) {}

    /**
     * Sent back on the redirect when the export form was filled in wrongly.
     *
     * On the query string rather than in the flash bag, the way the reset panel
     * does it: this frame is loaded by a request of its own and would not see a
     * flash set for the page around it.
     */
    public const string ERROR_PASSWORD_TOO_SHORT = 'password-too-short';

    public const string ERROR_PASSWORD_MISMATCH = 'password-mismatch';

    /** @var array<string, string> */
    private const array EXPORT_ERRORS = [
        self::ERROR_PASSWORD_TOO_SHORT => 'admin.config_backup.export.error.too_short',
        self::ERROR_PASSWORD_MISMATCH  => 'admin.config_backup.export.error.mismatch',
    ];

    #[Route('', name: 'panel', methods: ['GET'])]
    public function panel(Request $request): Response
    {
        return $this->renderPanel(
            error: self::EXPORT_ERRORS[(string) $request->query->get('error', '')] ?? null,
        );
    }

    /**
     * Build the file and send it, without it ever existing anywhere but in this
     * response.
     *
     * The password is typed twice here and nowhere else. There is no recovery
     * for a mistyped one — the file is sealed with what was sent, and nothing
     * remembers it — so the confirmation is the only thing standing between an
     * admin and a backup they will discover is unopenable on the day they need
     * it.
     *
     * The refusals redirect rather than re-rendering the frame, which is the
     * opposite of what every other action here does and is forced by the
     * download. Turbo cannot render an octet-stream, so this form carries
     * data-turbo="false" and the browser submits it as a plain navigation —
     * which means a 200 carrying frame markup would be displayed as a bare
     * <turbo-frame> on a blank page. A redirect lands back on the admin
     * section, where the frame reloads itself and reads the reason off the
     * query string.
     */
    #[Route('/export', name: 'export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $this->assertCsrf($request, 'config_backup_export');

        $password = (string) $request->request->get('password', '');
        $repeated = (string) $request->request->get('password_repeat', '');

        if (strlen($password) < ConfigBackupCipher::MINIMUM_PASSWORD_LENGTH) {
            return $this->redirectToSection(self::ERROR_PASSWORD_TOO_SHORT);
        }

        if (false === hash_equals($password, $repeated)) {
            return $this->redirectToSection(self::ERROR_PASSWORD_MISMATCH);
        }

        $response = new Response(
            $this->exporter->export($password),
            Response::HTTP_OK,
            ['Content-Type' => 'application/octet-stream'],
        );

        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition('attachment', $this->exporter->filename()),
        );

        // Every intermediary told, in both dialects: this is one instance's
        // entire credential set and it must not sit in a proxy cache.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /** Decrypt and show what would happen. Nothing is written by this action. */
    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $this->assertCsrf($request, 'config_backup_import');

        return $this->review($request, apply: false);
    }

    /** The second press: do the automatic half of what the review promised. */
    #[Route('/apply', name: 'apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        $this->assertCsrf($request, 'config_backup_apply');

        return $this->review($request, apply: true);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The shared half of import and apply: get the envelope, open it, and
     * either plan or execute.
     *
     * One method because the failure handling is the whole subject and must be
     * identical in both — an apply that reported a wrong password differently
     * from an import would be a way to learn something about a file by pressing
     * the other button.
     */
    private function review(Request $request, bool $apply): Response
    {
        $envelope = $apply ? $this->envelopeFromField($request) : $this->envelopeFromUpload($request);

        if (null === $envelope) {
            return $this->panelWithError('admin.config_backup.import.error.no_file');
        }

        $password = (string) $request->request->get('password', '');

        if ('' === $password) {
            return $this->panelWithError('admin.config_backup.import.error.no_password', $envelope);
        }

        try {
            $document = $this->importer->open($envelope, $password);

            $plan = $apply ? $this->importer->apply($document) : $this->importer->plan($document);
        } catch (ConfigBackupException $e) {
            // The enum's sentence, never the exception's message: that one is
            // for the log and says more about the file than a page should.
            return $this->panelWithError(
                $e->failure->transKey(),
                ConfigBackupFailure::WrongPassword === $e->failure ? $envelope : null,
            );
        } catch (InvalidFirebaseCredentialsException $e) {
            // The one refusal that comes from inside the document rather than
            // from the envelope, and the one whose message IS the instruction —
            // it names the two projects that disagree. The transaction in
            // ConfigBackupImporter::apply() has already rolled back.
            return $this->renderPanel(errorLiteral: $e->getMessage(), envelope: $envelope);
        }

        // The envelope is carried on so the review's own "apply" button has
        // something to send, and dropped once applied — there is nothing left
        // to do with it, and a page that keeps it invites a second apply.
        return $this->renderPanel(plan: $plan, envelope: $apply ? null : $envelope);
    }

    private function envelopeFromUpload(Request $request): ?string
    {
        $file = $request->files->get('backup');

        if (false === $file instanceof UploadedFile || false === $file->isValid()) {
            return null;
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return null;
        }

        // getContent() rather than move(): the file is read out of PHP's own
        // upload temp and never lands anywhere plMail chose, and PHP unlinks it
        // when the request ends.
        $contents = $file->getContent();

        return '' === $contents ? null : $contents;
    }

    private function envelopeFromField(Request $request): ?string
    {
        $envelope = (string) $request->request->get('envelope', '');

        return '' === $envelope || strlen($envelope) > self::MAX_UPLOAD_BYTES ? null : $envelope;
    }

    /**
     * $envelope is kept only for a wrong password: the admin has the right file
     * and is one retype away, and making them pick it again is a punishment for
     * a typo. Any other failure means the file itself is the problem, and
     * holding on to it would invite a third attempt at something that cannot
     * work.
     */
    private function panelWithError(string $messageKey, ?string $envelope = null): Response
    {
        return $this->renderPanel(error: $messageKey, envelope: $envelope);
    }

    /**
     * Every render of this frame goes through here, so the template's four
     * inputs are always all present. A Twig `default` filter standing in for a
     * variable one branch forgot is how a page comes to show an empty review
     * instead of an error.
     */
    private function renderPanel(
        ?ConfigBackupPlan $plan = null,
        ?string $error = null,
        ?string $errorLiteral = null,
        ?string $envelope = null,
    ): Response {
        return $this->render('admin/backup/_frame.html.twig', [
            'plan'              => $plan,
            'error'             => $error,
            'errorLiteral'      => $errorLiteral,
            'envelope'          => $envelope,
            'minPasswordLength' => ConfigBackupCipher::MINIMUM_PASSWORD_LENGTH,
        ]);
    }

    private function redirectToSection(string $error): Response
    {
        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'backup', 'error' => $error]);
    }

}
