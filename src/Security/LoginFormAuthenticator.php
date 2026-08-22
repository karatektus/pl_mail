<?php

namespace App\Security;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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

    private CsrfTokenManagerInterface $csrfTokenManager;
    private UrlGeneratorInterface $urlGenerator;
    private UserPasswordHasherInterface $userPasswordHasher;

    private UserRepository $userRepository;

    public function __construct(
        EntityManagerInterface $documentManager,
        CsrfTokenManagerInterface $csrfTokenManager,
        UrlGeneratorInterface $urlGenerator,
        UserPasswordHasherInterface $userPasswordHasher,
        UserRepository $userRepository
    ) {
        $this->documentManager = $documentManager;

        $this->csrfTokenManager = $csrfTokenManager;
        $this->urlGenerator = $urlGenerator;
        $this->userPasswordHasher = $userPasswordHasher;

        $this->userRepository = $userRepository;
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

    public function checkCredentials($credentials, User $user): bool
    {
        return $this->userPasswordHasher->isPasswordValid($user, $credentials['password']);
    }

    #[ArrayShape(['email' => "mixed", 'password' => "mixed", 'csrf_token' => "mixed"])] public function getCredentials(Request $request): array
    {
        $credentials = [
            'email'      => $request->request->get('email'),
            'password'   => $request->request->get('password'),
            'csrf_token' => $request->request->get('_csrf_token'),
        ];

        $request->getSession()->set(
            SecurityRequestAttributes::LAST_USERNAME,
            $credentials['email']
        );

        return $credentials;
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    public function getPassword($credentials): ?string
    {
        return $credentials['password'];
    }

    public function getUser(array $credentials): ?User
    {
        $token = new CsrfToken('authenticate', $credentials['csrf_token']);
        if (false === $this->csrfTokenManager->isTokenValid($token)) {
            throw new InvalidCsrfTokenException();
        }

        $user = $this->userRepository->findOneByEmail($credentials['email']);

        if (null === $user) {
            // fail authentication with a custom error
            throw new CustomUserMessageAuthenticationException('Email could not be found.');
        }

        return $user;
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
     * The test is deliberately two-sided rather than a list of file
     * extensions. `Sec-Fetch-Dest: document` is what a browser says about a
     * real navigation, and every browser that has shipped in years sends it;
     * an Accept header mentioning text/html covers a Turbo visit (which is a
     * fetch, and says `Sec-Fetch-Dest: empty`) and anything older that sends no
     * Sec-Fetch headers at all. An image, a stylesheet, a script or a JSON
     * fetch matches neither.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (false === $this->isDocumentNavigation($request) && true === $request->hasSession()) {
            $this->removeTargetPath($request->getSession(), 'main');
        }

        return parent::start($request, $authException);
    }

    /**
     * Something a person could be looking at, as opposed to something the page
     * they were already looking at went and fetched.
     */
    private function isDocumentNavigation(Request $request): bool
    {
        if ('document' === $request->headers->get('Sec-Fetch-Dest')) {
            return true;
        }

        return str_contains((string) $request->headers->get('Accept'), 'text/html');
    }


}
