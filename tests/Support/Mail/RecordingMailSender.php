<?php

declare(strict_types=1);

namespace App\Tests\Support\Mail;

use App\Command\Test\FinishSendCommand;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use Symfony\Component\Mime\Email;

/**
 * Sending, as the browser suite sees it: nothing leaves, and what would have
 * left is written down.
 *
 * Wired in config/services.yaml under `when@test`, above every real sender,
 * and it claims only the accounts the browser suite seeds (`imap.e2e.test`,
 * see SeedTestEmailCommand). Every PHPUnit test that sends brings its own
 * sender or its own account, so none of them is claimed by this one.
 *
 * Until it existed the suite had no send at all: no worker takes the send
 * queue, so every spec stopped at "the browser handed the message over", and
 * what happens once the worker has sent it — the draft becoming the Sent copy,
 * the conversation and the sidebar hearing about it — was never seen by a
 * browser. app:test:finish-send runs the real send job for one message, and
 * this is the provider it hands the mail to.
 *
 * The bytes go to `var/test-sent/<Message-ID>.eml`, which the command prints,
 * so a spec can read what a recipient's program would have got.
 *
 * filesSentCopy() is true for the reason DemoMailSender's is: the seeded
 * account has no IMAP server to append a Sent copy to.
 */
final readonly class RecordingMailSender implements MailSenderInterface
{
    /** The host SeedTestEmailCommand gives every account it makes. */
    public const string E2E_IMAP_HOST = 'imap.e2e.test';

    public function __construct(
        private string $directory,
    ) {
    }

    public function supports(Account $account): bool
    {
        return self::E2E_IMAP_HOST === $account->imapHost;
    }

    public function filesSentCopy(): bool
    {
        return true;
    }

    public function send(Email $email, Account $account): bool
    {
        if (false === is_dir($this->directory)) {
            mkdir($this->directory, 0o775, true);
        }

        file_put_contents(FinishSendCommand::sentPath($this->directory, (string) $email->getHeaders()->get('Message-ID')?->getBodyAsString()), $email->toString());

        return true;
    }
}
