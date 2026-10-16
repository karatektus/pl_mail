<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Exception\ContactKeyRefused;
use App\Entity\User\User;
use App\Service\Crypto\WkdDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Encryption → Web Key Directory: per address, whether plMail
 * publishes the person's key, and a check that asks for it from outside.
 *
 * Plain forms that redirect back, like the decrypted-mail card beside it. An
 * address that is not the person's, or is on none of the installation's
 * domains, is a 404 for both actions — WkdDirectory::entryFor() is the
 * ownership check, since an address is not an entity the voter knows.
 */
#[IsGranted('ROLE_USER')]
final class WkdSettingsController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly WkdDirectory        $directory,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/settings/encryption/wkd/publish', name: 'app_settings_wkd_publish', methods: ['POST'])]
    public function publish(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-wkd');

        if (false === $this->directory->setPublished($user, $request->request->getString('email'), '1' === $request->request->getString('publish'))) {
            throw $this->createNotFoundException();
        }

        return $this->back();
    }

    /**
     * Through WkdLookup, so with every rule that has for asking a domain — and
     * the same per-person limit, since this too makes the server knock on a
     * door. Each method is asked on its own (WkdDirectory::check()), and the
     * answer is both verdicts on the card, not a page of its own.
     */
    #[Route('/settings/encryption/wkd/check', name: 'app_settings_wkd_check', methods: ['POST'])]
    public function check(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-wkd');

        $email = strtolower(trim($request->request->getString('email')));

        try {
            $result = $this->directory->check($user, $email);
        } catch (ContactKeyRefused $refused) {
            $this->addFlash('wkd', $this->translator->trans($refused->translationKey));

            return $this->back();
        }

        if (null === $result) {
            throw $this->createNotFoundException();
        }

        // Both methods' verdicts, drawn beside the address they were asked
        // for (settings/_wkd.html.twig) rather than as one sentence: either
        // method can be the broken one.
        $this->addFlash('wkd_check', $result->toArray());

        return $this->back();
    }

    private function back(): Response
    {
        return $this->redirect($this->generateUrl('app_settings_index', ['section' => 'encryption']) . '#wkd', Response::HTTP_SEE_OTHER);
    }
}
