<?php

declare(strict_types=1);

namespace App\Jmap\Method\Keys;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Entity\Mail\Message;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Crypto\ContactCertificates;
use App\Service\Demo\DemoMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "ContactKey/smimeSigner" — plMail extension, `urn:plmail:params:jmap:keys`
 * (#320).
 *
 * What a signature inside an S/MIME-encrypted message means: the app's half
 * of the web's `POST /contact-keys/message/{id}/smime-signer`, which an app
 * password cannot reach (session firewall, CSRF token). The client decrypted
 * the message and checked the signature against the certificate it carried;
 * it sends that certificate and the rest of the chain, and the server — which
 * has the trust store and the person's own and contacts' certificates —
 * answers `valid`, `untrusted`, `key_changed` or `invalid`, and keeps the
 * certificate for the sender unless it is the person's own or does not name
 * the sender (ContactCertificates::signerState(), the same call the web
 * makes). That second half is the point: without it somebody who reads
 * encrypted S/MIME mail only on the phone never becomes reachable encrypted.
 *
 * The sender is the message's, never the client's: `emailId` names the
 * message the signature was found in, and From is read off the server's row.
 * Only an S/MIME-encrypted message is accepted — that is the one case where
 * the server cannot see the signature itself (signed mail it checks on
 * arrival, SignatureVerifier), so it is the one case this exists for, and it
 * keeps the call from recording certificates against arbitrary mail.
 *
 * Arguments: accountId, emailId, certificate (PEM), chain (PEM, optional).
 * Answers: {state, signer} — signer is the certificate's SHA-256 fingerprint,
 * or null when the certificate could not be read.
 */
final class ContactKeySmimeSignerMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver        $accounts,
        private readonly ContactCertificates    $certificates,
        private readonly EntityManagerInterface $entityManager,
        private readonly DemoMode               $demoMode,
    ) {
    }

    public function name(): string
    {
        return 'ContactKey/smimeSigner';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account     = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);
        $emailId     = $context->resolveId((string) ($arguments['emailId'] ?? '')) ?? (string) ($arguments['emailId'] ?? '');
        $certificate = $arguments['certificate'] ?? null;
        $chain       = $arguments['chain'] ?? '';

        if (false === is_string($certificate) || '' === trim($certificate)) {
            throw new MethodException('invalidArguments', '"certificate" must be the signer\'s certificate, PEM-encoded.');
        }

        if (false === is_string($chain)) {
            throw new MethodException('invalidArguments', '"chain" must be PEM-encoded certificates, or left out.');
        }

        $message = ctype_digit($emailId) ? $this->entityManager->find(Message::class, (int) $emailId) : null;

        // Another account's message, or another person's, is not found rather
        // than forbidden: whether an id exists is not this caller's business.
        if (null === $message || $message->account->id !== $account->id) {
            throw new MethodException('notFound', sprintf('No Email "%s" in this account.', $emailId));
        }

        if (MessageCrypto::SmimeEncrypted !== $message->crypto) {
            throw new MethodException('forbidden', 'Only a signature found inside an S/MIME-encrypted message is judged here; the server checks signed mail itself.');
        }

        if (true === $this->demoMode->isEnabled()) {
            throw new MethodException('forbidden', 'A demo mailbox keeps no contacts\' keys.');
        }

        $result = $this->certificates->signerState($context->user, (string) $message->fromAddress, $certificate, $chain);

        return [
            'accountId' => (string) $account->id,
            'emailId'   => (string) $message->id,
            'state'     => $result['state']->value,
            'signer'    => $result['signer'],
        ];
    }
}
