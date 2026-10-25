<?php

declare(strict_types=1);

namespace App\Jmap\Method\Keys;

use App\Domain\Exception\DecryptedMailRefused;
use App\Entity\Mail\Message;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Blob\BlobId;
use App\Jmap\Blob\BlobResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Crypto\DecryptedMail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "DecryptedMail/store" — plMail extension, `urn:plmail:params:jmap:keys`.
 *
 * Hands over decrypted messages for an account that keeps decrypted mail:
 * `emails` maps an Email id to the blobId of its decrypted inner MIME
 * message, uploaded first to the upload URL. The server parses and sanitises
 * it itself — DecryptedMail, exactly as for the browser.
 *
 * Answers `stored` (the Email ids taken, including ones that already had
 * their decrypted body) and `notStored` (id → SetError: notFound for an
 * Email or blob that is not this account's, forbidden when the account does
 * not keep decrypted mail or the Email is not encrypted, invalidProperties
 * when the blob is not a MIME message).
 */
final class DecryptedMailStoreMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver        $accounts,
        private readonly BlobResolver           $blobs,
        private readonly DecryptedMail          $decryptedMail,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'DecryptedMail/store';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);
        $emails  = $arguments['emails'] ?? null;

        if (false === is_array($emails) || count($emails) > 50) {
            throw new MethodException('invalidArguments', '"emails" must map at most 50 Email ids to blob ids.');
        }

        $stored    = [];
        $notStored = [];

        foreach ($emails as $emailId => $blobId) {
            $emailId = (string) $emailId;
            $message = ctype_digit($emailId) ? $this->entityManager->find(Message::class, (int) $emailId) : null;
            $parsed  = is_string($blobId) ? BlobId::parse($blobId) : null;
            $blob    = null === $parsed ? null : $this->blobs->resolve($account, $parsed);

            if (null === $message || $message->account->id !== $account->id || null === $blob) {
                $notStored[$emailId] = ['type' => 'notFound'];

                continue;
            }

            $mime = true === $blob->isFile() ? (string) file_get_contents((string) $blob->path) : (string) $blob->content;

            try {
                $this->decryptedMail->accept($message, $mime);
                $stored[] = $emailId;
            } catch (DecryptedMailRefused $refused) {
                $notStored[$emailId] = [
                    'type'        => str_contains($refused->getMessage(), 'MIME') || str_contains($refused->getMessage(), 'large') ? 'invalidProperties' : 'forbidden',
                    'description' => $refused->getMessage(),
                ];
            }
        }

        return [
            'accountId' => (string) $account->id,
            'stored'    => $stored,
            'notStored' => [] === $notStored ? new \stdClass() : $notStored,
        ];
    }
}
