<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\ReadingPaneMode;
use App\Domain\Enum\Theme\BackgroundKind;
use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Helper\AppIconStorage;
use App\Domain\Helper\CustomLogoStorage;
use App\Domain\Helper\SvgSanitizer;
use App\Entity\Embeddable\Appearance;
use App\Entity\User\User;
use App\Service\Appearance\LogoIcons;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/settings/appearance', name: 'app_appearance_')]
#[IsGranted('ROLE_USER')]
final class AppearanceController extends AbstractController
{
    use ChecksCsrf;

    private const array ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/var/uploads/backgrounds')]
        private readonly string                 $backgroundDirectory,
    ) {
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        // `ajax` is the layout's meta-tag token, which the Stimulus controller
        // sends as X-CSRF-Token. These four went without one on the grounds
        // that a forged request "only rewrites the user's own colours" — but
        // that includes a background upload and a whole theme import, and a
        // state-changing POST that anyone's page can fire is the thing
        // StateChangingPostsNeedCsrfTest exists to refuse.
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $payload = $request->toArray();

        $user->appearance->applyArray($payload);
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Remembers how wide the live preview pane is.
     *
     * Its own endpoint rather than a field on the appearance payload, for the
     * same reason the calendar's is its own: the drag handle writes on release
     * and this is a layout preference, not part of the theme — it must not turn
     * up in an exported theme file or be applied by importing somebody else's.
     *
     * Its own token id rather than the layout's `ajax` one, because the drag
     * handle posts a form body. The width itself is clamped server-side too —
     * the client's bounds are a convenience, and a stored 40000 would wedge the
     * settings page.
     */
    #[Route('/pane-state', name: 'pane_state', methods: ['POST'])]
    public function paneState(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'appearance_pane_state');

        /** @var User $user */
        $user = $this->getUser();

        if (true === $request->request->has('width')) {
            $user->setSetting(User::SETTING_APPEARANCE_PREVIEW_WIDTH, max(
                User::APPEARANCE_PREVIEW_MIN_WIDTH,
                min(User::APPEARANCE_PREVIEW_MAX_WIDTH, $request->request->getInt('width')),
            ));

            $this->entityManager->flush();
        }

        return $this->json(['width' => $user->appearancePreviewWidth]);
    }

    /**
     * Remembers whether the open message sits beside the list, and how much of
     * the mail card it takes.
     *
     * One endpoint for both, with either field optional, because the two arrive
     * from different places: the Settings control posts the mode, the drag
     * handle on the mail card posts the width, and neither knows the other's
     * current value. A field that is absent is left exactly as it is — writing
     * a default for the missing one would reset the share every time somebody
     * switched the pane off and on again.
     *
     * Its own token id for the reason pane-state's gives: the drag posts a form
     * body, and this is a layout preference, not part of the theme, so it must
     * not turn up in an exported theme file or be applied by importing one.
     *
     * Both values are clamped HERE. The handle bounds what it sends, which is a
     * convenience for the person dragging; a stored share of 4000 would draw a
     * list nobody can see. A mode this build does not know keeps the one the
     * user is already in rather than resetting it: it is posted by a controller,
     * so an unrecognised value is a bug or a forgery, and neither is a reason to
     * switch off a pane somebody chose.
     */
    #[Route('/reading-pane', name: 'reading_pane_state', methods: ['POST'])]
    public function readingPaneState(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'reading_pane_state');

        /** @var User $user */
        $user = $this->getUser();

        if (true === $request->request->has('mode')) {
            $user->readingPaneMode = ReadingPaneMode::fromSetting(
                $request->request->getString('mode'),
                $user->readingPaneMode,
            );
        }

        if (true === $request->request->has('width')) {
            $user->setSetting(User::SETTING_READING_PANE_WIDTH, max(
                User::READING_PANE_MIN_PCT,
                min(User::READING_PANE_MAX_PCT, $request->request->getInt('width')),
            ));
        }

        $this->entityManager->flush();

        return $this->json([
            'mode'  => $user->readingPaneMode->value,
            'width' => $user->readingPaneWidthPct,
        ]);
    }

    /**
     * Keeps the installed app's PNGs, as this user's browser drew them (#141).
     *
     * The appearance pane draws the chosen icon into four PNGs on a canvas
     * when a logo is picked, and any page does when the current choice's are
     * missing (see app_icons.js); this stores them, for this user only. Why
     * the browser draws them rather than the server is in AppIconStorage.
     *
     * Nothing the browser says is taken on trust: the choice has to be one
     * the routes can name, and each file a PNG of exactly its size and small
     * (AppIconStorage::isAcceptable()). A drawing version that is not this
     * server's means the page was loaded before a deploy changed the drawing:
     * 409, and the page's next load draws the current one.
     */
    #[Route('/app-icons', name: 'app_icons', methods: ['POST'])]
    public function appIcons(Request $request, AppIconStorage $storage, LogoIcons $icons): Response
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $choice = $this->appIconChoice($user, $request->request->getString('motif'), $request->request->getString('paint'));

        if (null === $choice) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($icons->version() !== $request->request->getString('version')) {
            return new Response(null, Response::HTTP_CONFLICT);
        }

        $pngs = [];

        foreach (array_keys(AppIconStorage::ICONS) as $name) {
            $file  = $request->files->get($name);
            $bytes = $file instanceof UploadedFile && true === $file->isValid() ? (string) file_get_contents($file->getPathname()) : '';

            if (false === $storage->isAcceptable($name, $bytes)) {
                return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $pngs[$name] = $bytes;
        }

        $storage->store((string) $user->id, $choice, $icons->version(), $pngs);

        // Which choice was kept: several uploads can be in flight (a pick, and
        // the layout drawing a missing one), and the answer says which is which.
        return $this->json(['choice' => $choice]);
    }

    /**
     * The user's own logo, as an SVG (#48).
     *
     * Kept only after SvgSanitizer has cut it down to a drawing, and worn at
     * once: uploading a logo is choosing it. The previous upload goes, since
     * only one is ever offered. The page reloads the pane afterwards, and the
     * layout then draws the installed app's PNGs from it the way it does for
     * any choice whose PNGs are missing.
     *
     * A refusal says why in a sentence (`message`) rather than a key: the pane
     * shows it as it is, and the three reasons ask for three different fixes.
     */
    #[Route('/logo', name: 'logo_upload', methods: ['POST'])]
    public function uploadLogo(Request $request, SvgSanitizer $sanitizer, CustomLogoStorage $logos, TranslatorInterface $translator): JsonResponse
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $file = $request->files->get('logo');

        if (false === $file instanceof UploadedFile || false === $file->isValid()) {
            return $this->json(['ok' => false, 'message' => $translator->trans('settings.appearance.logo.custom.error_missing')], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > SvgSanitizer::MAX_BYTES) {
            return $this->json(['ok' => false, 'message' => $translator->trans('settings.appearance.logo.custom.error_size')], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $svg = $sanitizer->sanitize((string) file_get_contents($file->getPathname()));

        if (null === $svg) {
            return $this->json(['ok' => false, 'message' => $translator->trans('settings.appearance.logo.custom.error_type')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->appearance->logoCustom       = $logos->store((string) $user->id, $svg);
        $user->appearance->logoCustomActive = true;

        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Forget the uploaded logo, and go back to the motif it was worn over,
     * which was kept underneath all along (Appearance::$logoCustom).
     */
    #[Route('/logo/remove', name: 'logo_remove', methods: ['POST'])]
    public function removeLogo(Request $request, CustomLogoStorage $logos): JsonResponse
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $logos->deleteAllFor((string) $user->id);

        $user->appearance->logoCustom       = null;
        $user->appearance->logoCustomActive = false;

        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/background', name: 'background_upload', methods: ['POST'])]
    public function uploadBackground(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $file = $request->files->get('background');

        if (false === $file instanceof UploadedFile) {
            return $this->json(['ok' => false, 'error' => 'appearance.background.missing'], Response::HTTP_BAD_REQUEST);
        }

        if (false === in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            return $this->json(['ok' => false, 'error' => 'appearance.background.type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $directory = $this->userDirectory((int) $user->id);

        if (false === is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $previous = $user->appearance->backgroundFile;

        if (null !== $previous && true === is_file($directory.'/'.$previous)) {
            unlink($directory.'/'.$previous);
        }

        $filename = sprintf('%s.%s', Uuid::v7()->toRfc4122(), $file->guessExtension() ?? 'jpg');
        $file->move($directory, $filename);

        $user->appearance->backgroundFile = $filename;
        $user->appearance->backgroundKind = BackgroundKind::Custom;

        $this->entityManager->flush();

        return $this->json([
            'ok'  => true,
            'url' => $this->generateUrl('app_appearance_background_show', ['filename' => $filename]),
        ]);
    }

    #[Route('/background/{filename}', name: 'background_show', requirements: ['filename' => '[0-9a-f\-]+\.[a-z]{3,4}'], methods: ['GET'])]
    public function showBackground(string $filename): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->appearance->backgroundFile !== $filename) {
            throw $this->createNotFoundException();
        }

        $path = $this->userDirectory((int) $user->id).'/'.$filename;

        if (false === is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPublic();
        $response->setMaxAge(31536000);

        return $response;
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $response = new Response(
            json_encode($user->appearance->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'plmail-theme.json'),
        );

        return $response;
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $payload = $request->toArray();

        if (1 !== ($payload['version'] ?? null)) {
            return $this->json(['ok' => false, 'error' => 'appearance.import.version'], Response::HTTP_BAD_REQUEST);
        }

        $user->appearance->applyArray($payload);
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/reset', name: 'reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'ajax');

        /** @var User $user */
        $user = $this->getUser();

        $user->appearance->applyArray(new Appearance()->toArray());
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    private function userDirectory(int $userId): string
    {
        return sprintf('%s/%d', $this->backgroundDirectory, $userId);
    }

    /**
     * The choice a PNG upload is for, as AppIconStorage keeps it, or null for
     * one the routes cannot name: a motif and a paint, or `custom` and the
     * hash of this user's own uploaded logo, and no other hash.
     */
    private function appIconChoice(User $user, string $motif, string $paint): ?string
    {
        if (Appearance::CUSTOM_LOGO === $motif) {
            return null !== $user->appearance->logoCustom && $paint === $user->appearance->logoCustom
                ? LogoIcons::customChoice($paint)
                : null;
        }

        $logoMotif = LogoMotif::tryFrom($motif);

        if (null === $logoMotif || false === in_array($paint, LogoMotif::paintWires(), true)) {
            return null;
        }

        return LogoIcons::choice($logoMotif, LogoStyle::tryFrom($paint));
    }
}
