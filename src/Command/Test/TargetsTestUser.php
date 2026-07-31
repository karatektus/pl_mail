<?php

declare(strict_types=1);

namespace App\Command\Test;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Which user a fixture command acts on.
 *
 * Shared by every `app:test:*` command that seeds or clears data belonging to
 * somebody, so there is one answer to "whose mailbox is this?" rather than four
 * copies of the same fallback drifting apart.
 *
 * The `--email` option exists for the parallel Playwright suite. Each worker
 * owns a dedicated user (`e2e-w0@plmail.test`, `e2e-w1@…`) so that workers
 * cannot wipe each other's threads — `app:test:seed-mail` deletes every thread
 * on the account it seeds, which is safe per-user and catastrophic shared.
 * Passing the address explicitly beats exporting APP_DEV_USER_EMAIL per call:
 * it reads the same in CI, where the console is plain `php bin/console`, and
 * under Docker, where it would otherwise have to be threaded through
 * `docker compose exec -e`.
 *
 * With no option given the behaviour is exactly what it always was, so the
 * stack's own boot-time seeding and a developer running these by hand are
 * unaffected.
 */
trait TargetsTestUser
{
    private const string DEFAULT_TEST_USER_EMAIL = 'e2e@plmail.test';

    protected function configureUserOption(): void
    {
        $this->addOption(
            'email',
            null,
            InputOption::VALUE_REQUIRED,
            'The user to act on; defaults to APP_DEV_USER_EMAIL, then '.self::DEFAULT_TEST_USER_EMAIL,
        );
    }

    protected function resolveUserEmail(InputInterface $input): string
    {
        $email = $input->getOption('email');

        if (is_string($email) && '' !== $email) {
            return $email;
        }

        $fromEnvironment = $_SERVER['APP_DEV_USER_EMAIL'] ?? null;

        return is_string($fromEnvironment) && '' !== $fromEnvironment
            ? $fromEnvironment
            : self::DEFAULT_TEST_USER_EMAIL;
    }
}
