<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Domain\Exception\DecryptedMailRefused;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Crypto\DecryptedMail;
use App\Service\Crypto\DecryptedMailPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where a browser hands over decrypted mail for an account that keeps it on
 * the server, and finds out which messages are still waiting.
 *
 * The browser does the decrypting — it is the only thing with the key — in
 * the background, whenever the keyring is open
 * (assets/controllers/crypto/decrypted_mail_controller.js), and straight away
 * when a message is read (mail--encrypted-message). See DecryptedMail for what
 * the server does with it.
 */
#[IsGranted('ROLE_USER')]
final class DecryptedMailController extends AbstractController
{
    use ChecksCsrf;

    /** Enough to keep a tab busy for a while; it asks again when done. */
    private const int PENDING_BATCH = 25;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DecryptedMail          $decryptedMail,
        private readonly DecryptedMailPolicy    $policy,
        private readonly LoggerInterface        $logger,
    ) {
    }

    /** Encrypted mail still without its decrypted body, newest first. */
    #[Route('/mail/decrypted/pending', name: 'app_mail_decrypted_pending', methods: ['GET'])]
    public function pending(#[CurrentUser] User $user): JsonResponse
    {
        $accounts = array_values(array_filter(
            $this->entityManager->getRepository(Account::class)->findBy(['usr' => $user, 'isActive' => true]),
            fn (Account $account): bool => $this->policy->keeps($account),
        ));

        if ([] === $accounts) {
            return new JsonResponse(['messages' => []], headers: ['Cache-Control' => 'private, no-store']);
        }

        /** @var list<Message> $messages */
        $messages = $this->entityManager->createQuery(
            'SELECT m FROM ' . Message::class . ' m
              WHERE m.account IN (:accounts) AND m.crypto IN (:crypto)
                AND m.plaintextSource = :none AND m.sealedDraft = false
              ORDER BY m.receivedAt DESC',
        )
            ->setParameter('accounts', $accounts)
            ->setParameter('crypto', [MessageCrypto::PgpEncrypted, MessageCrypto::PgpInlineEncrypted, MessageCrypto::SmimeEncrypted])
            ->setParameter('none', PlaintextSource::None)
            ->setMaxResults(self::PENDING_BATCH)
            ->getResult();

        return new JsonResponse(['messages' => array_map(fn (Message $message): array => [
            'id'     => $message->id,
            'raw'    => $this->generateUrl('app_mail_message_raw', ['id' => $message->id]),
            'upload' => $this->generateUrl('app_mail_decrypted_upload', ['id' => $message->id]),
        ], $messages)], headers: ['Cache-Control' => 'private, no-store']);
    }

    /**
     * One decrypted message: the inner MIME message, as the request body.
     * 204 when it is kept (or already was), 422 when it may not or cannot be.
     */
    #[Route('/mail/message/{id}/plaintext', name: 'app_mail_decrypted_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function upload(Request $request, Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        $this->assertCsrf($request, 'ajax');

        try {
            $this->decryptedMail->accept($message, $request->getContent());
        } catch (DecryptedMailRefused $refused) {
            $this->logger->info('Decrypted mail refused: {reason}', ['reason' => $refused->getMessage(), 'message' => $message->id]);

            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
