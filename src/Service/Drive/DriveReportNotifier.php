<?php

declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Drive\DriveShareReport;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Mail\MailSenderRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The two emails a Drive link report sends.
 *
 *  - To the person who reported it, if they left an address: "we got it".
 *  - To every administrator: what was reported, why, and where to act.
 *
 * Sent from an administrator's own mailbox, since plMail has no separate
 * system mailer. If no admin has a mailbox, nothing is sent and the report
 * still waits in Admin → Drive.
 */
final readonly class DriveReportNotifier
{
    public function __construct(
        private UserRepository $users,
        private MailSenderRegistry $senders,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function reportFiled(DriveShareReport $report): void
    {
        $admins = $this->users->findAdmins();
        $account = $this->senderAccount($admins);

        if (null === $account) {
            $this->logger->info('Drive report: no administrator mailbox to send notifications from');

            return;
        }

        $brand = 'plMail';
        $reason = ucfirst($report->reason);

        if (null !== $report->reporterEmail) {
            $this->send($account, $brand, $report->reporterEmail, sprintf('We received your report — %s', $brand), implode("\n", [
                'Hello,',
                '',
                sprintf('Thank you for reporting the shared file "%s" (%s).', $report->fileName, $reason),
                '',
                'It will be reviewed as quickly as possible, and action taken if it breaks the rules of this server.',
                'You do not need to do anything else.',
                '',
                sprintf('— %s', $brand),
            ]));
        }

        $adminLink = $this->urls->generate('app_admin_dashboard', ['section' => 'drive'], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($admins as $admin) {
            $to = $admin->getUserIdentifier();
            if (false === filter_var($to, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $this->send($account, $brand, $to, sprintf('[%s] Drive link reported: %s', $brand, $report->fileName), implode("\n", [
                sprintf('A shared Drive file was reported: %s', $report->fileName),
                '',
                sprintf('Reason: %s', $reason),
                sprintf('Details: %s', $report->details ?? '—'),
                sprintf('Reported by: %s', $report->reporterEmail ?? 'anonymous'),
                sprintf('From address: %s', $report->reporterIp ?? '—'),
                '',
                'Review it and revoke the link, switch off the owner\'s Drive, or dismiss it:',
                $adminLink,
            ]));
        }
    }

    /** @param list<User> $admins */
    private function senderAccount(array $admins): ?Account
    {
        foreach ($admins as $admin) {
            foreach ($admin->accounts as $account) {
                if (false !== filter_var((string) $account->displayAddress, FILTER_VALIDATE_EMAIL) && false !== $account->isActive) {
                    return $account;
                }
            }
        }

        return null;
    }

    private function send(Account $account, string $brand, string $to, string $subject, string $text): void
    {
        try {
            $email = new Email()
                ->from(new Address((string) $account->displayAddress, $brand))
                ->to($to)
                ->subject($subject)
                ->text($text);

            $this->senders->resolve($account)->send($email, $account);
        } catch (\Throwable $e) {
            $this->logger->warning('Drive report: could not send a notification', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
