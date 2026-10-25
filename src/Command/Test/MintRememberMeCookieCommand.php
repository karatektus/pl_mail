<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Repository\User\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

/**
 * Prints a "keep me signed in" cookie for a test user, made by the `main`
 * firewall's own remember-me handler — the token row and the cookie exactly as
 * a real sign-in leaves them.
 *
 * For the browser specs of phase 6e of issue #28, which need sessions the
 * password form did not open. A single sign-on sign-in is one (it is always
 * remembered, see App\Security\OidcAuthenticator), and an account that has no
 * password cannot be signed into through the form at all — so a spec stands
 * one in with this cookie instead of a provider the test stack does not have.
 *
 * Only in the test environment, and stricter than the other app:test commands
 * there, which refuse prod alone: this one signs anybody in without a
 * credential, which is not something a development install should offer
 * either.
 */
#[AsCommand(
    name: 'app:test:remember-me',
    description: 'Print a remember-me cookie value for a test user (test environment only)',
)]
final class MintRememberMeCookieCommand extends Command
{
    use TargetsTestUser;

    public function __construct(
        private readonly UserRepository             $users,
        private readonly RequestStack               $requests,
        #[Autowire(service: 'security.authenticator.remember_me_handler.main')]
        private readonly RememberMeHandlerInterface $rememberMe,
        #[Autowire('%kernel.environment%')]
        private readonly string                     $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('test' !== $this->environment) {
            $io->error('app:test:remember-me runs in the test environment only.');

            return Command::FAILURE;
        }

        $email = $this->resolveUserEmail($input);
        $user  = $this->users->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('No user found for email "%s".', $email));

            return Command::FAILURE;
        }

        // The handler reads the main request and leaves the cookie on it for
        // the response listener to send; here there is no response, so it is
        // read off the request instead.
        $request = Request::create('/');
        $this->requests->push($request);

        try {
            $this->rememberMe->createRememberMeCookie($user);
        } finally {
            $this->requests->pop();
        }

        $cookie = $request->attributes->get(ResponseListener::COOKIE_ATTR_NAME);

        if (!$cookie instanceof Cookie) {
            $io->error('The firewall made no remember-me cookie.');

            return Command::FAILURE;
        }

        $output->writeln((string) $cookie->getValue());

        return Command::SUCCESS;
    }
}
