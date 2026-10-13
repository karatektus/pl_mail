<?php

declare(strict_types=1);

namespace App\Controller\Crypto;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Domain\Enum\Crypto\PgpScheme;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Domain\Exception\ContactKeyRefused;
use App\Entity\Crypto\ContactKey;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Crypto\ContactCertificateRepository;
use App\Repository\Crypto\ContactKeyRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Crypto\ContactCertificates;
use App\Service\Crypto\ContactKeyCollector;
use App\Service\Crypto\ContactKeys;
use App\Service\Crypto\PublicKeyReader;
use App\Service\Crypto\RecipientKeys;
use App\Service\Crypto\SignatureVerifier;
use App\Service\Demo\DemoMode;
use App\Service\Mail\SenderResolver;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Contacts' keys: the list in Settings → Encryption, and the two buttons the
 * reading banner offers for a signature plMail cannot vouch for yet.
 *
 * Server-rendered, unlike the keyring above it on the same page: nothing here
 * needs the keyring open — these are public keys — so the list is a Turbo
 * Frame of plain forms, and every action is a POST that redirects back to the
 * frame with a flash saying what happened. No script of its own beyond the
 * frame, which keeps it inside the CSP without a controller to maintain.
 *
 * The banner actions answer with a Turbo Stream that replaces the banner,
 * after checking that one message again on the spot — the person is looking
 * at it and wants to see the answer. The sender's other signed mail is
 * queued (SignatureRecheck), as it is for every change made in the list.
 *
 * Contacts' S/MIME certificates share the list and its rules (accept a
 * changed one, remove one); and the reading pane asks here what a signature
 * it found inside an S/MIME-encrypted message means (smimeSigner()).
 *
 * Every row is looked up through the signed-in person (ContactKeys::owned(),
 * the OwnershipVoter for messages): another person's key or message is a 404
 * or a 403, never a write.
 */
#[Route('/contact-keys', name: 'app_contact_keys_')]
#[IsGranted('ROLE_USER')]
final class ContactKeyController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    private const string FLASH = 'contact_keys';

    public function __construct(
        private readonly ContactKeys                  $contactKeys,
        private readonly ContactKeyRepository         $repository,
        private readonly ContactKeyCollector          $collector,
        private readonly SignatureVerifier            $verifier,
        private readonly EntityManagerInterface       $entityManager,
        private readonly DemoMode                     $demoMode,
        private readonly SenderResolver               $senders,
        private readonly RecipientKeys                $recipientKeys,
        private readonly ContactCertificates          $certificates,
        private readonly ContactCertificateRepository $certificateRepository,
    ) {
    }

    /**
     * What the composer needs to know about the people a message is going to
     * (assets/controllers/compose/encryption_controller.js). See
     * RecipientKeys, which answers a native client's ContactKey/lookup too.
     *
     * POST, because a lookup can record a key; the shared `ajax` token, like
     * every other write the composer makes.
     */
    #[Route('/recipients', name: 'recipients', methods: ['POST'])]
    public function recipients(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->assertCsrf($request, 'ajax');

        $body    = json_decode($request->getContent(), true);
        $emails  = is_array($body['emails'] ?? null) ? array_values($body['emails']) : [];
        $account = $this->senders->accountFor($body['from'] ?? null, $user);

        return new JsonResponse([
            'recipients' => $this->recipientKeys->describe($user, $emails, true === ($body['lookup'] ?? false)),
            'sender'     => null === $account ? null : $this->recipientKeys->sender($user, $account, $this->senders->addressFor($body['from'] ?? null, $account, $user)),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('settings/_contact_keys.html.twig', [
            'contactKeys'         => $this->repository->findForUser($user),
            'contactCertificates' => $this->certificateRepository->findForUser($user),
            'policies'            => RecipientPolicy::cases(),
            'schemes'             => PgpScheme::cases(),
        ]);
    }

    /**
     * The keys plMail holds for one address, for the browser checking a
     * signature inside an encrypted message (assets/crypto/message.js).
     */
    #[Route('/sender', name: 'sender', methods: ['GET'])]
    public function sender(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $keys = $this->repository->findForAddress($user, (string) $request->query->get('email', ''));

        return new JsonResponse(array_map(static fn (ContactKey $key): array => [
            'fingerprint' => $key->fingerprint,
            'publicKey'   => $key->publicKey,
            'status'      => $key->status->value,
            'pinned'      => $key->pinned,
        ], $keys), headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->write($request, 'contact-keys', function () use ($request, $user): void {
            $file = $request->files->get('file');
            $data = trim((string) $request->request->get('key', ''));

            if ($file instanceof UploadedFile && true === $file->isValid() && $file->getSize() <= PublicKeyReader::MAX_BYTES) {
                $data = (string) file_get_contents($file->getPathname());
            }

            $counts = $this->contactKeys->import($user, $data);
            $added  = $counts[ContactKeyOutcome::Added->value] + $counts[ContactKeyOutcome::Seen->value];

            if (0 < $counts[ContactKeyOutcome::Changed->value]) {
                $this->say('warning', 'contact_keys.import.changed', ['%count%' => $counts[ContactKeyOutcome::Changed->value]]);
            } elseif (0 < $added) {
                $this->say('success', 'contact_keys.import.done', ['%count%' => $added]);
            } else {
                $this->say('error', 'contact_keys.import.unusable');
            }
        });
    }

    #[Route('/lookup', name: 'lookup', methods: ['POST'])]
    public function lookup(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->write($request, 'contact-keys', function () use ($request, $user): void {
            $this->sayOutcome($this->contactKeys->lookUp($user, (string) $request->request->get('email', '')));
        });
    }

    #[Route('/{id}/pin', name: 'pin', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pin(Request $request, #[CurrentUser] User $user, int $id): Response
    {
        return $this->write($request, 'contact-key-' . $id, function () use ($user, $id): void {
            $this->contactKeys->pin($user, $id);
            $this->say('success', 'contact_keys.pinned');
        });
    }

    #[Route('/{id}/unpin', name: 'unpin', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unpin(Request $request, #[CurrentUser] User $user, int $id): Response
    {
        return $this->write($request, 'contact-key-' . $id, function () use ($user, $id): void {
            $this->contactKeys->unpin($user, $id);
        });
    }

    #[Route('/{id}/accept', name: 'accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function accept(Request $request, #[CurrentUser] User $user, int $id): Response
    {
        return $this->write($request, 'contact-key-' . $id, function () use ($user, $id): void {
            $this->contactKeys->accept($user, $id);
            $this->say('success', 'contact_keys.accepted');
        });
    }

    #[Route('/{id}/remove', name: 'remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(Request $request, #[CurrentUser] User $user, int $id): Response
    {
        return $this->write($request, 'contact-key-' . $id, function () use ($user, $id): void {
            $this->contactKeys->remove($user, $id);
            $this->say('success', 'contact_keys.removed');
        });
    }

    #[Route('/{id}/preferences', name: 'preferences', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function preferences(Request $request, #[CurrentUser] User $user, int $id): Response
    {
        return $this->write($request, 'contact-key-' . $id, function () use ($request, $user, $id): void {
            $this->contactKeys->setPreferences(
                $user,
                $id,
                RecipientPolicy::tryFrom((string) $request->request->get('encrypt')) ?? RecipientPolicy::Auto,
                RecipientPolicy::tryFrom((string) $request->request->get('sign')) ?? RecipientPolicy::Auto,
                PgpScheme::tryFrom((string) $request->request->get('scheme')) ?? PgpScheme::Mime,
            );
            $this->say('success', 'contact_keys.preferences_saved');
        });
    }

    #[Route('/certificates/{id}/accept', name: 'certificate_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function acceptCertificate(Request $request, #[CurrentUser] User $user, int $id): Response
    {
        return $this->write($request, 'contact-certificate-' . $id, function () use ($user, $id): void {
            $this->certificates->accept($user, $id);
            $this->say('success', 'contact_keys.accepted');
        });
    }

    #[Route('/certificates/{id}/remove', name: 'certificate_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeCertificate(Request $request, #[CurrentUser] User $user, int $id): Response
    {
        return $this->write($request, 'contact-certificate-' . $id, function () use ($user, $id): void {
            $this->certificates->remove($user, $id);
            $this->say('success', 'contact_keys.certificates.removed');
        });
    }

    // ── From the reading banner ───────────────────────────────────────────────

    /**
     * Looks the sender's key up in their domain's WKD. Asked from the banner
     * of a signature plMail has no key for, or a changed one.
     */
    #[Route('/message/{id}/lookup', name: 'message_lookup', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function messageLookup(Request $request, #[CurrentUser] User $user, Message $message): Response
    {
        return $this->fromBanner($request, $message, function () use ($user, $message): string {
            return match ($this->contactKeys->lookUp($user, (string) $message->fromAddress)) {
                ContactKeyOutcome::Added, ContactKeyOutcome::Seen => 'contact_keys.lookup.found',
                ContactKeyOutcome::Changed                         => 'contact_keys.lookup.changed',
                default                                            => 'contact_keys.lookup.nothing',
            };
        });
    }

    /**
     * Keeps the key that made an Untrusted signature and pins it: the person
     * compared the fingerprint the banner shows and believes it.
     */
    #[Route('/message/{id}/trust', name: 'message_trust', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function messageTrust(Request $request, #[CurrentUser] User $user, Message $message): Response
    {
        return $this->fromBanner($request, $message, function () use ($user, $message): string {
            $this->contactKeys->pin($user, (int) $this->signerKey($user, $message)->id);

            return 'contact_keys.pinned';
        });
    }

    /**
     * Uses the key that made a KeyChanged signature from now on, in place of
     * the one plMail knew.
     */
    #[Route('/message/{id}/accept', name: 'message_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function messageAccept(Request $request, #[CurrentUser] User $user, Message $message): Response
    {
        return $this->fromBanner($request, $message, function () use ($user, $message): string {
            $this->contactKeys->accept($user, (int) $this->signerKey($user, $message)->id);

            return 'contact_keys.accepted';
        });
    }

    /**
     * What a signature inside an S/MIME-encrypted message means. The browser
     * decrypted the message and checked the signature against the
     * certificate it carried (assets/crypto/smime.js); it sends that
     * certificate and the rest of the signature's chain, and the server —
     * which has the system's authorities and the person's contacts — says
     * whether it is vouched for, and keeps it for the sender
     * (ContactCertificates::signerState()).
     *
     * POST with the shared `ajax` token, because it can record a certificate.
     * Nothing about the message itself is sent; the sender is the row's.
     */
    #[Route('/message/{id}/smime-signer', name: 'message_smime_signer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function smimeSigner(Request $request, #[CurrentUser] User $user, Message $message): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        $this->assertCsrf($request, 'ajax');
        $this->refuseInDemo();

        $body   = json_decode($request->getContent(), true);
        $result = $this->certificates->signerState(
            $user,
            (string) $message->fromAddress,
            is_string($body['certificate'] ?? null) ? $body['certificate'] : '',
            is_string($body['chain'] ?? null) ? $body['chain'] : '',
        );

        return new JsonResponse(['state' => $result['state']->value, 'signer' => $result['signer']], headers: ['Cache-Control' => 'private, no-store']);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param Closure(): void $write
     */
    private function write(Request $request, string $csrfId, Closure $write): Response
    {
        $this->assertCsrf($request, $csrfId);
        $this->refuseInDemo();

        try {
            $write();
        } catch (ContactKeyRefused $refused) {
            if ('contact_keys.not_found' === $refused->translationKey) {
                throw $this->createNotFoundException('No such contact key.');
            }

            $this->say('error', $refused->translationKey);
        }

        return $this->redirectToRoute('app_contact_keys_index', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param Closure(): string $act returns the translation key of what happened
     */
    private function fromBanner(Request $request, Message $message, Closure $act): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        $this->assertCsrf($request, 'message-key-' . $message->id);
        $this->refuseInDemo();

        try {
            $notice = $act();
            $tone   = 'success';
        } catch (ContactKeyRefused $refused) {
            $notice = $refused->translationKey;
            $tone   = 'error';
        }

        // This one now, while the person watches; ContactKeys has queued the
        // sender's others.
        $result = $this->verifier->verify($message);

        if (null !== $result) {
            $message->signatureState = $result['state'];
            $message->signer         = $result['signer'];
            $this->entityManager->flush();
        }

        if (true === in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            return new JsonResponse(['notice' => $notice, 'ok' => 'success' === $tone]);
        }

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return $this->renderTurboStream('mail/_crypto_banner.stream.html.twig', [
                'message' => $message,
                'notice'  => $notice,
                'tone'    => $tone,
            ]);
        }

        return $this->redirect((string) ($request->headers->get('referer') ?: $this->generateUrl('app_mail_inbox')));
    }

    /**
     * The contact key that made the message's signature, collecting it from
     * the message first if it came in an Autocrypt header or attachment that
     * has not been collected yet.
     */
    private function signerKey(User $user, Message $message): ContactKey
    {
        $find = function () use ($user, $message): ?ContactKey {
            foreach ($this->repository->findForAddress($user, (string) $message->fromAddress) as $key) {
                if (null !== $message->signer && str_ends_with($key->fingerprint, strtolower($message->signer))) {
                    return $key;
                }
            }

            return null;
        };

        $key = $find();

        if (null === $key) {
            $this->collector->collect($message);
            $key = $find();
        }

        return $key ?? throw new ContactKeyRefused('contact_keys.signer_unknown');
    }

    private function sayOutcome(?ContactKeyOutcome $outcome): void
    {
        match ($outcome) {
            ContactKeyOutcome::Added, ContactKeyOutcome::Seen => $this->say('success', 'contact_keys.lookup.found'),
            ContactKeyOutcome::Changed                         => $this->say('warning', 'contact_keys.lookup.changed'),
            default                                            => $this->say('error', 'contact_keys.lookup.nothing'),
        };
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function say(string $tone, string $key, array $parameters = []): void
    {
        $this->addFlash(self::FLASH, ['tone' => $tone, 'key' => $key, 'parameters' => $parameters]);
    }

    private function refuseInDemo(): void
    {
        if (true === $this->demoMode->isEnabled()) {
            throw $this->createAccessDeniedException('A demo mailbox keeps no contacts\' keys.');
        }
    }
}
