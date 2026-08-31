<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\User\User;
use App\Form\Factory\ChangePasswordFormFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Changing your own password.
 *
 * The half of user management that had no home anywhere: an administrator may
 * not set somebody else's password, `app:user:password` needs a shell on the
 * host, and there is no reset-by-mail flow — so an account created for someone
 * kept whatever password the administrator typed for it, forever.
 *
 * **Whose password this changes is not a parameter.** There is no id in the
 * route, no id in the form, and the user comes from #[CurrentUser]. That is not
 * economy; it is the reason this endpoint cannot be turned into the thing
 * UserFormType refuses to be. Proof of the current password is the second lock
 * and lives on the form — see ChangePasswordType, which explains why an
 * already-signed-in session is not enough on its own.
 *
 * Every session but this one is ended, and that is the point of a password
 * change rather than a side effect of it: the firewall's remember-me tokens are
 * signature-based over the password hash, so the sixty-day cookies stop
 * verifying the moment the hash changes. Somebody changing their password
 * because a laptop went missing has actually withdrawn it.
 */
#[Route('/settings/password', name: 'app_settings_password_')]
#[IsGranted('ROLE_USER')]
final class PasswordController extends AbstractController
{
    public function __construct(
        private readonly ChangePasswordFormFactory   $forms,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface      $entityManager,
    ) {}

    #[Route('', name: 'change', methods: ['POST'])]
    public function change(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->forms->create();
        $form->handleRequest($request);

        // Covers a wrong current password, a mismatch, a password under the
        // floor and a stale CSRF token alike: all four mean "do not write",
        // and the form carries the message for whichever it was.
        if (false === $form->isSubmitted() || false === $form->isValid()) {
            return $this->streamResponse($request, $form->createView(), 'settings.password.flash.refused', 'error');
        }

        $user->password = $this->passwordHasher->hashPassword(
            $user,
            (string) $form->get('plainPassword')->getData(),
        );

        $this->entityManager->flush();

        // A fresh, unsubmitted form: the stream replaces the card, and three
        // filled-in password boxes left sitting on screen after a successful
        // change are three things to clear by hand — and one of them is now the
        // password itself, in a field the browser is being asked to remember.
        return $this->streamResponse($request, $this->forms->create()->createView(), 'settings.password.flash.changed', 'success');
    }

    /**
     * The card, redrawn, with something to say about it.
     *
     * Shaped like AliasController::streamResponse() and for the same reasons:
     * the toast is the only report a user gets, and the non-stream branch is
     * what a browser with JavaScript disabled — or a request that arrived
     * without Turbo's Accept header — falls back to. That branch loses the
     * message, which is the trade the whole settings page already makes.
     */
    private function streamResponse(Request $request, FormView $formView, string $toastMessage, string $toastType): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('settings/_password.stream.html.twig', [
                'passwordForm' => $formView,
                'toastMessage' => $toastMessage,
                'toastType'    => $toastType,
            ]);
        }

        return $this->redirectToRoute('app_settings_index', ['section' => 'security']);
    }
}
