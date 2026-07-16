<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\MailProvider;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Account;
use App\Enum\AuthType;
use App\Service\OAuth\OAuthTokenManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Future-proof send path for Google accounts: POST the RFC822 message to the
 * Gmail API. Covered by the https://mail.google.com/ scope we already hold, and
 * Gmail files the Sent copy itself — no SMTP, no manual IMAP append.
 */
class GmailApiSender implements MailSenderInterface
{
    private const SEND_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function __construct(
        private readonly OAuthTokenManager   $tokenManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
    ) {
    }

    public function supports(Account $account): bool
    {
        if (AuthType::OAuth2->value !== $account->getAuthType()) {
            return false;
        }

        if (MailProvider::Google->value !== $account->getOauthProvider()) {
            return false;
        }

        return true;
    }

    public function filesSentCopy(): bool
    {
        return true;
    }

    public function send(Email $email, Account $account): bool
    {
        $accessToken = $this->tokenManager->getValidAccessToken($account);
        $raw         = $this->toBase64Url($email->toString());

        try {
            $response = $this->httpClient->request('POST', self::SEND_ENDPOINT, [
                'auth_bearer' => $accessToken,
                'json'        => ['raw' => $raw],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('GmailApiSender: send failed', [
                    'status'  => $statusCode,
                    'account' => $account->getId(),
                    'body'    => $response->getContent(false),
                ]);

                return false;
            }
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('GmailApiSender: request error', [
                'account' => $account->getId(),
                'error'   => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Gmail requires URL-safe base64 (RFC 4648 §5) without padding.
     */
    private function toBase64Url(string $mime): string
    {
        $base64  = base64_encode($mime);
        $urlSafe = strtr($base64, '+/', '-_');

        return rtrim($urlSafe, '=');
    }
}
