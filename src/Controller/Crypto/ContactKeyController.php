<?php

declare(strict_types=1);

namespace App\Controller\Crypto;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\DTO\Crypto\ContactKeyLookup;
use App\Domain\Enum\Crypto\ContactKeyOrigin;
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
use App\Service\Crypto\KeyServerLookup;
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
 * The person's switch for the public key servers (KeyServerLookup) sits in
 * the same frame, beside the lookup it widens.
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
        private readonly KeyServerLookup              $keyServers,
        private readonly PublicKeyReader              $reader,
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
            'keyServers'          => $this->keyServers->hosts(),
            'keyServersOn'        => $user->lookUpKeyServers,
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
            $data = trim($request->request->getString('key'));

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
            $this->sayOutcome($user, $this->contactKeys->lookUp($user, $request->request->getString('email')));
        });
    }

    /**
     * The person's "Also look on public key servers" (User::$lookUpKeyServers).
     * Read only when posted, like the sending switches: a form that did not
     * mention it must not move it.
     */
    #[Route('/key-servers', name: 'key_servers', methods: ['POST'])]
    public function keyServers(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->write($request, 'contact-keys', function () use ($request, $user): void {
            if (true === $request->request->has('key_servers')) {
                $user->lookUpKeyServers = '1' === $request->request->getString('key_servers');
                $this->entityManager->flush();
            }
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
                RecipientPolicy::tryFrom($request->request->getString('encrypt')) ?? RecipientPolicy::Auto,
                RecipientPolicy::tryFrom($request->request->getString('sign')) ?? RecipientPolicy::Auto,
                PgpScheme::tryFrom($request->request->getString('scheme')) ?? PgpScheme::Mime,
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
     * Looks the sender's key up — their domain's WKD, then the key servers.
     * Asked from the banner of a signature plMail has no key for, or a
     * changed one; the answer says where the key was found.
     */
    #[Route('/message/{id}/lookup', name: 'message_lookup', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function messageLookup(Request $request, #[CurrentUser] User $user, Message $message): Response
    {
        return $this->fromBanner($request, $message, function () use ($user, $message): array {
            $notice = $this->lookupNotice($user, $this->contactKeys->lookUp($user, (string) $message->fromAddress));

            return [$notice['key'], $notice['parameters']];
        });
    }

    /**
     * Keeps the key that made an Untrusted signature and pins it: the person
     * compared the fingerprint the banner shows and believes it.
     */
    #[Route('/message/{id}/trust', name: 'message_trust', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function messageTrust(Request $request, #[CurrentUser] User $user, Message $message): Response
    {
        return $this->fromBanner($request, $message, function () use ($user, $message): array {
            $this->contactKeys->pin($user, (int) $this->signerKey($user, $message)->id);

            return ['contact_keys.pinned', []];
        });
    }

    /**
     * Uses the key that made a KeyChanged signature from now on, in place of
     * the one plMail knew.
     */
    #[Route('/message/{id}/accept', name: 'message_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function messageAccept(Request $request, #[CurrentUser] User $user, Message $message): Response
    {
        return $this->fromBanner($request, $message, function () use ($user, $message): array {
            $this->contactKeys->accept($user, (int) $this->signerKey($user, $message)->id);

            return ['contact_keys.accepted', []];
        });
    }

    /**
     * The sender's own key, attached to their message (phase 6g): kept for
     * them ("keep"), or — when it waits beside a different known key — used
     * from now on ("accept"). The banner offers this only when plMail does
     * not use that key yet.
     *
     * From the stored message for mail the server can read: the attachment
     * is collected the way ContactKeyCollector does at ingest. For encrypted
     * mail the key is inside the encryption, so the browser posts it (`key`);
     * it is held to the same rule — only a key with a user ID for the From
     * address is taken for the sender, as a message can carry anybody's key.
     */
    #[Route('/message/{id}/attached-key', name: 'message_attached_key', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function messageAttachedKey(Request $request, #[CurrentUser] User $user, Message $message): Response
    {
        return $this->fromBanner($request, $message, function () use ($request, $user, $message): array {
            $from        = strtolower(trim((string) $message->fromAddress));
            $armored     = $request->request->getString('key');
            $fingerprint = strtolower($request->request->getString('fingerprint'));

            if ('' !== $armored && strlen($armored) <= PublicKeyReader::MAX_BYTES) {
                foreach ($this->reader->read($armored) as $key) {
                    if (true === $key->usableFor($from)) {
                        $this->contactKeys->record($user, $from, $key, ContactKeyOrigin::Attached);
                        $fingerprint = $key->fingerprint;
                    }
                }
            } else {
                $this->collector->collect($message);
            }

            $row = null;

            foreach ($this->repository->findForAddress($user, $from) as $candidate) {
                $row = $fingerprint === $candidate->fingerprint ? $candidate : $row;
            }

            if (null === $row) {
                throw new ContactKeyRefused('message.crypto.attached_key.unusable');
            }

            if ('accept' === $request->request->getString('action') && false === $row->isCurrent()) {
                $this->contactKeys->accept($user, (int) $row->id);

                return ['contact_keys.accepted', []];
            }

            return [true === $row->isCurrent() ? 'message.crypto.attached_key.kept' : 'message.crypto.attached_key.kept_changed', []];
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
     * @param Closure(): array{string, array<string, string>} $act returns the
     *        translation key of what happened, and its parameters
     */
    private function fromBanner(Request $request, Message $message, Closure $act): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);
        $this->assertCsrf($request, 'message-key-' . $message->id);
        $this->refuseInDemo();

        try {
            [$notice, $parameters] = $act();
            $tone = 'success';
        } catch (ContactKeyRefused $refused) {
            $notice     = $refused->translationKey;
            $parameters = [];
            $tone       = 'error';
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
                'message'    => $message,
                'notice'     => $notice,
                'parameters' => $parameters,
                'tone'       => $tone,
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

    private function sayOutcome(User $user, ?ContactKeyLookup $lookup): void
    {
        $notice = $this->lookupNotice($user, $lookup);

        $this->say($notice['tone'], $notice['key'], $notice['parameters']);
    }

    /**
     * What a lookup found, in words that say where: the domain's directory,
     * or the key server by name — the first thing to know about how far the
     * key deserves belief. "Nothing" names the key servers too when they were
     * asked, so a person who switched them off is not told they were.
     *
     * @return array{tone: string, key: string, parameters: array<string, string>}
     */
    private function lookupNotice(User $user, ?ContactKeyLookup $lookup): array
    {
        if (null === $lookup) {
            return ['tone' => 'error', 'key' => true === $this->keyServers->isEnabledFor($user) ? 'contact_keys.lookup.nothing_keyservers' : 'contact_keys.lookup.nothing', 'parameters' => []];
        }

        $fromKeyServer = ContactKeyOrigin::KeyServer === $lookup->origin;
        $parameters    = true === $fromKeyServer ? ['%host%' => (string) $lookup->host] : [];

        if (ContactKeyOutcome::Changed === $lookup->outcome) {
            return ['tone' => 'warning', 'key' => true === $fromKeyServer ? 'contact_keys.lookup.changed_keyserver' : 'contact_keys.lookup.changed', 'parameters' => $parameters];
        }

        return ['tone' => 'success', 'key' => true === $fromKeyServer ? 'contact_keys.lookup.found_keyserver' : 'contact_keys.lookup.found', 'parameters' => $parameters];
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
