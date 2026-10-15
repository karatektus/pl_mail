<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User\User;
use App\Service\Oidc\OidcClient;
use App\Service\Oidc\OidcIdentity;
use App\Service\Oidc\OidcLoginFailed;
use App\Service\Oidc\OidcSettings;
use App\Service\Oidc\OidcUserResolver;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Signs somebody in from the provider's callback.
 *
 * The second authenticator on the `main` firewall, beside the password form,
 * and it ends in the same place: a PostAuthenticationToken for a User, which
 * the rest of the firewall treats exactly as it treats a password sign-in.
 * That is the point — the user checker still refuses suspended accounts, and
 * two-factor still asks an account that has TOTP switched on for its code,
 * because scheb_2fa.yaml lists PostAuthenticationToken. A provider that
 * already asked for a second factor will be asked again here; switching TOTP
 * off for an account that only signs in through the provider is the
 * account holder's call, not this class's.
 *
 * Self-validating, because there is no credential left to check by the time
 * the passport exists: the code, the state, the nonce and the ID token have
 * all been verified by OidcClient while building it. The user is loaded
 * lazily through the badge so that the firewall's login throttle runs before
 * an account is linked or created.
 *
 * Signed in this way is remembered, as "Keep me signed in" does on the password
 * form. There is no checkbox here to tick, and without the badge the only thing
 * holding the person in is the PHP session: a cookie that ends with the browser,
 * kept in a file that is cleaned up after 24 minutes without a request and that
 * every deploy, which replaces the container, throws away with the rest of its
 * filesystem. The remember-me token is stored in the database and outlives all
 * three. The badge carries the parameter the firewall looks for, because the
 * callback has no form to carry it: set `always_remember_me` instead and the
 * password form's unticked checkbox would stop meaning anything.
 */
final class OidcAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly OidcSettings $settings,
        private readonly OidcClient $client,
        private readonly OidcUserResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        // The route first: this is asked of every request the firewall sees,
        // and whether single sign-on is on is a read of the database that only
        // the callback has any use for.
        return OidcClient::CALLBACK_ROUTE === $request->attributes->get('_route')
            && $request->isMethod(Request::METHOD_GET)
            && true === $this->settings->current()->isEnabled();
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $identity = $this->client->complete($request);
        } catch (OidcLoginFailed $e) {
            throw $this->refusal($e);
        }

        return $this->passportFor($identity);
    }

    /**
     * Split from authenticate() so that what the passport carries can be
     * looked at without a provider to complete a sign-in against.
     */
    public function passportFor(OidcIdentity $identity): Passport
    {
        // The badge's identifier is what the login throttle keys its
        // per-username limits on. The subject, not the address: it is what
        // identifies the person at the provider, and the address may not
        // have been sent.
        return new SelfValidatingPassport(
            new UserBadge(
                'oidc:' . $identity->subject,
                function () use ($identity): User {
                    try {
                        return $this->resolver->resolve($identity);
                    } catch (OidcLoginFailed $e) {
                        throw $this->refusal($e);
                    }
                },
            ),
            // `_remember_me` is the firewall's default remember_me_parameter,
            // the name the password form's checkbox has.
            [new RememberMeBadge(['_remember_me' => true])],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();

        if ($user instanceof User) {
            $user->lastLogin = new DateTime();
            $this->entityManager->flush();
        }

        $target = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($target ?? $this->urlGenerator->generate('app_default_index'));
    }

    /**
     * Back to the login form, with the reason where the form looks for it.
     *
     * The form renders the session's last authentication error, so the
     * refusal reads exactly like a wrong password does — and the user checker's
     * "your account is switched off" reaches the person the same way.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urlGenerator->generate(LoginFormAuthenticator::LOGIN_ROUTE));
    }

    /**
     * The person sees the translated reason, the log gets the provider's own
     * words — which may name an address or a subject, and so are not for the
     * login form.
     */
    private function refusal(OidcLoginFailed $e): CustomUserMessageAuthenticationException
    {
        $this->logger->warning('Single sign-on refused: ' . $e->getMessage(), ['exception' => $e]);

        return new CustomUserMessageAuthenticationException($e->messageKey, [], 0, $e);
    }
}
