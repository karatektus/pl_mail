<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Domain\Enum\AppLocale;
use App\Entity\User\User;
use App\Form\Setup\FirstAdminType;
use App\Service\Setup\FirstAdminInstaller;
use App\Service\Setup\InstallGuard;
use App\Service\Setup\PublicUrlSetting;
use App\Security\LoginFormAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creating the first user, in a browser, on an install that has none.
 *
 * Deliberately unauthenticated — there is nobody to authenticate — and
 * therefore guarded by exactly one thing: the install having no users at all.
 * That is checked when the page is rendered, when the form is submitted, and a
 * third time inside the locked write, because between the second check and the
 * insert is precisely where a second request would fit.
 *
 * `app:setup` still exists for headless installs and takes the same path.
 */
final class InstallController extends AbstractController
{
    #[Route('/install', name: 'app_install', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        InstallGuard $guard,
        TranslatorInterface $translator,
        FirstAdminInstaller $installer,
        PublicUrlSetting $publicUrl,
        Security $security,
    ): Response {
        $guard->assertAvailable();

        // Nobody is signed in, so UserLocaleSubscriber has no user to read a
        // language from — this page has to honour the one the selector asks
        // for itself.
        $locale = AppLocale::tryFrom((string) $request->query->get('_locale', ''));

        if (null !== $locale) {
            $request->setLocale($locale->value);
            $translator->setLocale($locale->value);
        }

        $user = new User();
        $user->locale = ($locale ?? AppLocale::tryFromRequest($request->getLocale()) ?? AppLocale::English)->value;

        // Switching language is a real navigation, so whatever had been typed
        // comes back through the query string rather than being thrown away.
        $carried = $request->query->all('first_admin');

        // Written out rather than looped over a map of setters, because a
        // property write is not a first-class callable. Blank stays blank: the
        // user is new, so its fields are null either way.
        $carry = static fn (mixed $value): ?string => is_string($value) && '' !== $value ? $value : null;

        $user->nameFirst = $carry($carried['nameFirst'] ?? null);
        $user->nameLast  = $carry($carried['nameLast'] ?? null);
        $user->email     = $carry($carried['email'] ?? null);

        $form = $this->createForm(FirstAdminType::class, $user, [
            'action'           => $this->generateUrl('app_install'),
            'public_url_guess' => $carried['publicUrl'] ?? $publicUrl->guessFrom($request->getSchemeAndHttpHost()),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $guard->assertAvailable();

            if (false === $installer->install($user, (string) $form->get('plainPassword')->getData())) {
                // Someone else finished the form first. There is nothing to
                // recover to: the page is closed for good now.
                return $this->redirectToRoute('app_login');
            }

            // After the account, not before: a failed install must not leave a
            // public URL behind pointing at a plMail nobody owns.
            $publicUrl->save((string) $form->get('publicUrl')->getData());

            // Named explicitly: the firewall gained a second authenticator when
            // 2FA was added, and Security::login() refuses to guess between
            // them. The two-factor one is never the right answer here — the
            // account was created seconds ago and cannot have a second factor
            // yet, and asking for one would lock the installer out of the
            // install they just performed.
            $security->login($user, LoginFormAuthenticator::class);

            return $this->redirectToRoute('app_default_index');
        }

        return $this->render('setup/install.html.twig', ['form' => $form]);
    }
}
