<?php

namespace App\Controller;

use App\Entity\User;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    private AuthorizationCheckerInterface $authorizationChecker;
    private RouterInterface $router;

    public function __construct(AuthorizationCheckerInterface $authorizationChecker, RouterInterface $router)
    {
        $this->authorizationChecker = $authorizationChecker;
        $this->router = $router;
    }


    #[Route('/login', 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if (true === $this->authorizationChecker->isGranted(User::ROLE_USER)) {
            return new RedirectResponse($this->router->generate('app_default_index'));
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        $errorMessage = null;

        if (null !== $error) {
            if ($error instanceof BadCredentialsException) {
                $errorMessage = 'login_bad_credentials';
            } else {
                /* TODO: logging */
                $errorMessage = 'login_unknown_error';
            }
        }


        return $this->render('security/login.html.twig', ['last_username' => $lastUsername, 'error' => $error]);
    }

    #[Route('/logout', 'app_logout')]
    public function logout()
    {
        throw new LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
