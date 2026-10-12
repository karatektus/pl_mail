<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User\User;
use App\Form\User\RequiredPasswordType;
use App\Service\User\PasswordChanger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The page somebody lands on when their password was chosen for them.
 *
 * App\Security\PasswordChangeRequiredListener sends them here and keeps them
 * here; this only has to render the form and store what comes back. See
 * User::$passwordChangeRequired for who gets the flag.
 *
 * For anyone without the flag the page does not exist as far as they can
 * tell: they are sent on to the inbox. Changing a password you chose yourself
 * belongs in Settings, where the current one is asked for.
 *
 * ROLE_USER and not IS_AUTHENTICATED_FULLY, although that would read as the
 * stricter choice: somebody who arrives through a remember-me cookie would be
 * refused, sent to /login, bounced from there to the inbox because they ARE
 * signed in, and sent back here by the listener — round and round.
 */
#[IsGranted('ROLE_USER')]
final class RequiredPasswordController extends AbstractController
{
    public function __construct(
        private readonly PasswordChanger $passwords,
    ) {
    }

    #[Route('/password/required', name: 'app_password_required', methods: ['GET', 'POST'])]
    public function required(Request $request, #[CurrentUser] User $user): Response
    {
        if (false === $user->passwordChangeRequired) {
            return $this->redirectToRoute('app_default_index');
        }

        $form = $this->createForm(RequiredPasswordType::class, null, [
            'action' => $this->generateUrl('app_password_required'),
        ]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            // Null: whatever keys hang off the password somebody else chose
            // are locked rather than carried over. In practice there are
            // none — an account an administrator made has never had a keyring,
            // and `app:user:password` locks one on the spot — but a password
            // this person did not choose must not be what their keys open with.
            $this->passwords->applyFromForm($user, $form->get('new'), null);

            return $this->redirectToRoute('app_default_index');
        }

        return $this->render('security/password_required.html.twig', [
            'form' => $form,
        ], new Response(status: true === $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
