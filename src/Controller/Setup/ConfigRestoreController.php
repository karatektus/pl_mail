<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Domain\DTO\Backup\ConfigBackupPlan;
use App\Domain\Enum\AppLocale;
use App\Domain\Enum\Backup\ConfigBackupFailure;
use App\Domain\Exception\ConfigBackupException;
use App\Domain\Exception\InvalidFirebaseCredentialsException;
use App\Service\Backup\ConfigBackupImporter;
use App\Service\Setup\InstallGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The other way to start an install: restore a config backup before creating
 * the first account.
 *
 * **A second entry point to ConfigBackupImporter, not a second importer.** The
 * classification of what can be applied and what has to be pasted by hand is
 * the substance of this feature, and having it decided twice — once for admins
 * and once for setup — is how the two would come to disagree about a file. Only
 * the guard and the surrounding page differ, which is exactly what a second
 * controller should be.
 *
 * **Before the account, not after**, and the flow decides that rather than
 * taste. /install exists only while the install has no users and 404s the
 * moment one appears, so the restore has to happen while that is still true or
 * it would need a different door. And it pays off immediately downstream: the
 * setup wizard's two admin steps — the mail OAuth registration and the
 * integration providers — ask "is anything configured yet?" to decide whether
 * they apply, so an install restored first walks the new administrator past
 * both instead of asking them for credentials the file already carried.
 *
 * **Unauthenticated, and guarded by the one thing that makes that safe.**
 * InstallGuard, on every action including the POSTs — the same predicate
 * InstallController leans on. Anyone who can reach this page can already make
 * themselves an administrator of this install by filling in the form next to
 * it, so restoring configuration into it grants nothing further; the moment
 * that stops being true, the guard closes this route with a 404 rather than a
 * redirect, which is the same refusal /install gives and says nothing about
 * what is behind it.
 *
 * **A failure leaves the setup restartable.** Nothing here writes users,
 * nothing marks the install as started, and every refusal renders the same page
 * again with the form on it. The worst case for a wrong password is a page with
 * a red line on it and an install still waiting for its first account.
 */
final class ConfigRestoreController extends AbstractController
{
    /** The same ceiling the admin page applies; see ConfigBackupController. */
    private const int MAX_UPLOAD_BYTES = 1048576;

    public function __construct(
        private readonly InstallGuard         $guard,
        private readonly ConfigBackupImporter $importer,
        private readonly LocaleSwitcher       $localeSwitcher,
    ) {}

    #[Route('/install/restore', name: 'app_install_restore', methods: ['GET', 'POST'])]
    public function restore(Request $request): Response
    {
        $this->guard->assertAvailable();
        $this->honourTheLanguageSelector($request);

        if (false === $request->isMethod('POST')) {
            return $this->renderPage();
        }

        $this->validateCsrf($request, 'install_restore');

        return $this->openAndShow($request, $this->envelopeFromUpload($request), apply: false);
    }

    /**
     * Its own route rather than a flag on the one above, so that a review can
     * never become an apply by a resubmission the browser decided to repeat.
     */
    #[Route('/install/restore/apply', name: 'app_install_restore_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        $this->guard->assertAvailable();
        $this->honourTheLanguageSelector($request);
        $this->validateCsrf($request, 'install_restore_apply');

        return $this->openAndShow($request, $this->envelopeFromField($request), apply: true);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function openAndShow(Request $request, ?string $envelope, bool $apply): Response
    {
        if (null === $envelope) {
            return $this->renderPage(error: 'admin.config_backup.import.error.no_file');
        }

        $password = (string) $request->request->get('password', '');

        if ('' === $password) {
            return $this->renderPage(error: 'admin.config_backup.import.error.no_password', envelope: $envelope);
        }

        try {
            $document = $this->importer->open($envelope, $password);

            $plan = $apply ? $this->importer->apply($document) : $this->importer->plan($document);
        } catch (ConfigBackupException $e) {
            return $this->renderPage(
                error: $e->failure->transKey(),
                envelope: ConfigBackupFailure::WrongPassword === $e->failure ? $envelope : null,
            );
        } catch (InvalidFirebaseCredentialsException $e) {
            return $this->renderPage(errorLiteral: $e->getMessage(), envelope: $envelope);
        }

        return $this->renderPage(plan: $plan, envelope: $apply ? null : $envelope);
    }

    /**
     * Nobody is signed in, so UserLocaleSubscriber has no user to read a
     * language from — the same note InstallController makes, and the same fix.
     *
     * Through LocaleSwitcher rather than by calling setLocale() on the
     * translator, which is what InstallController does and what put a line in
     * the PHPStan baseline: TranslatorInterface does not declare that method,
     * and the implementation only happens to have it.
     */
    private function honourTheLanguageSelector(Request $request): void
    {
        $locale = AppLocale::tryFrom((string) $request->query->get('_locale', ''));

        if (null !== $locale) {
            $request->setLocale($locale->value);
            $this->localeSwitcher->setLocale($locale->value);
        }
    }

    private function envelopeFromUpload(Request $request): ?string
    {
        $file = $request->files->get('backup');

        if (false === $file instanceof UploadedFile || false === $file->isValid() || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return null;
        }

        $contents = $file->getContent();

        return '' === $contents ? null : $contents;
    }

    private function envelopeFromField(Request $request): ?string
    {
        $envelope = (string) $request->request->get('envelope', '');

        return '' === $envelope || strlen($envelope) > self::MAX_UPLOAD_BYTES ? null : $envelope;
    }

    private function renderPage(
        ?ConfigBackupPlan $plan = null,
        ?string $error = null,
        ?string $errorLiteral = null,
        ?string $envelope = null,
    ): Response {
        return $this->render('setup/restore.html.twig', [
            'plan'         => $plan,
            'error'        => $error,
            'errorLiteral' => $errorLiteral,
            'envelope'     => $envelope,
        ]);
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        if (false === $this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
