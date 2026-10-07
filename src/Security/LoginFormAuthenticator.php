<?php

namespace App\Security;

use App\Entity\User\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Class LoginFormAuthenticator
 *
 * @package App\Security
 */
class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    private EntityManagerInterface $documentManager;

    private UrlGeneratorInterface $urlGenerator;

    public function __construct(
        EntityManagerInterface $documentManager,
        UrlGeneratorInterface $urlGenerator,
    ) {
        $this->documentManager = $documentManager;
        $this->urlGenerator = $urlGenerator;
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email', '');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($request->request->get('password', '')),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
                // Required for the firewall's remember_me to issue a cookie;
                // it only does so when the login form ticked _remember_me.
                new RememberMeBadge(),
            ]
        );
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    /**
     * @param Request        $request
     * @param TokenInterface $token
     * @param string         $firewallName
     *
     * @return RedirectResponse
     *
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): RedirectResponse
    {
        $user = $token->getUser();
        if ($user instanceof User) {
            $user->lastLogin = new DateTime();
            $this->documentManager->flush();
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_default_index'));
    }

    public function supports(Request $request): bool
    {
        return (self::LOGIN_ROUTE === $request->attributes->get('_route') && $request->isMethod(Request::METHOD_POST));
    }

    /**
     * Send an unauthenticated caller to the login form — and only remember
     * where they were going if they were going anywhere a person can look at.
     *
     * Symfony saves the target path in ExceptionListener::setTargetPath(),
     * whose test is `isMethodSafe() && !isXmlHttpRequest()`. An `<img
     * src="/settings/avatar/…">` passes both: it is a GET, and it is not an
     * XMLHttpRequest. So the last unauthenticated subresource the browser
     * happened to request became the place login sent you.
     *
     * Reported as landing on your own profile picture after signing in, and
     * that is exactly the shape of it: the session ends, the page is still on
     * screen, the browser re-requests the avatar, that 302 saves the avatar's
     * URL, and the next successful login redirects to an image. Whichever
     * subresource lost the race decided where you went, which is why it looked
     * intermittent.
     *
     * The entry point is the right place to undo it because it runs after the
     * save — ExceptionListener calls setTargetPath() and then start(). Anything
     * that is not a document navigation gets the saved path removed again, so
     * the login falls back to the default.
     *
     * What counts as a navigation is DocumentNavigation's to say; the code
     * form between the password and the code asks it the same question.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (false === DocumentNavigation::is($request) && true === $request->hasSession()) {
            $this->removeTargetPath($request->getSession(), 'main');
        }

        return parent::start($request, $authException);
    }
}
