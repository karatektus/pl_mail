<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\KeyServerUpload;
use App\Domain\Enum\Crypto\AddressKeyStatus;
use App\Domain\Enum\Crypto\KeyPublicationState;
use App\Domain\Exception\ContactKeyRefused;
use App\Domain\Interface\KeyPublisherInterface;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\KeyPublication;
use App\Entity\User\User;
use App\Repository\Crypto\AddressKeyRepository;
use App\Repository\Crypto\KeyPublicationRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Publishes the person's own key to a public key server, so that contacts
 * outside the installation's own domains — which plMail's Web Key Directory
 * covers (WkdDirectory) — can find it by address (phase 6g of #28).
 *
 * The other direction of KeyServerLookup, and held to the same rules:
 *
 *   - **Which servers.** Only those APP_KEY_SERVERS lists that have an upload
 *     API plMail speaks (KeyPublisherInterface — today keys.openpgp.org), and
 *     only for a person whose "Also look on public key servers" is on: that
 *     switch is the person saying plMail may talk to key servers at all.
 *   - **How.** From the server, through KeyFetcher: HTTPS, no redirects,
 *     private addresses refused, the timeout and the size limit, and the
 *     same per-person budget as the lookups. Only public keys travel — the
 *     server never needs a private one, not even for a revocation.
 *   - **When.** Only when the person asks, after a confirmation in the page
 *     that says the address will be tied to the key in public. Refreshing
 *     asks again only for what was already published.
 *
 * Publishing is two requests: the upload, which tells the server the key and
 * answers each address's state, and the confirmation request for the
 * addresses not yet confirmed, which makes the server mail each a link.
 * Until the link is clicked, a lookup by the address finds nothing — the
 * server's own guard against somebody uploading a key in another's name.
 *
 * When a published key is marked compromised, the browser hands over the key
 * with its revocation (it has the key open at that moment), and revoke()
 * uploads it to every server the key went to, so they hand out the revoked
 * key. That goes out even if the person has since switched key servers off
 * — they told that server about this key, and leaving it to vouch for a
 * compromised one is the worse outcome — but never to a server the
 * installation no longer lists. A server that cannot be reached then is
 * asked again on the next refresh, from the copy kept on the address key.
 */
final readonly class KeyPublishing
{
    /** @var array<string, KeyPublisherInterface> every server with an upload API, by host */
    private array $publishers;

    /**
     * @param iterable<KeyPublisherInterface> $known
     */
    public function __construct(
        private KeyFetcher               $fetcher,
        private PublicKeyReader          $reader,
        private KeyServerLookup          $keyServers,
        private AddressKeyRepository     $addressKeys,
        private KeyPublicationRepository $publications,
        private EntityManagerInterface   $entityManager,
        private ClockInterface           $clock,
        private LoggerInterface          $logger,
        #[AutowireIterator('app.key_publisher')]
        iterable                         $known,
    ) {
        $byHost = [];

        foreach ($known as $publisher) {
            $byHost[$publisher->host()] = $publisher;
        }

        $this->publishers = $byHost;
    }

    /**
     * The servers this person can publish to, in the installation's order.
     *
     * @return list<string>
     */
    public function hostsFor(User $user): array
    {
        if (false === $this->keyServers->isEnabledFor($user)) {
            return [];
        }

        return $this->installed();
    }

    /**
     * Uploads the key and asks the server to mail every address of it not yet
     * confirmed a link. The person confirmed in the page before this was
     * called; the server is told nothing else.
     *
     * @return list<KeyPublication> the key's rows on that server, as it answered
     *
     * @throws ContactKeyRefused when it cannot be published — not offered,
     *         not a key in use, the server refused or could not be reached,
     *         or the person used up their budget
     */
    public function publish(User $user, int $addressKeyId, string $host, string $locale): array
    {
        $publisher = $this->publisherFor($user, $host);
        $key       = $this->owned($user, $addressKeyId);

        if (AddressKeyStatus::Active !== $key->status || [] === $key->addresses()) {
            throw new ContactKeyRefused('encryption.publish.not_active');
        }

        $this->fetcher->spend($user);
        $upload = $this->upload($publisher, $key, $key->publicKey);
        $ask    = array_values(array_filter(
            $key->addresses(),
            static fn (string $email): bool => true === ($upload->states[$email] ?? KeyPublicationState::Unpublished)->canAskToConfirm(),
        ));

        if ([] !== $ask && null !== $upload->token) {
            $answer = $this->fetcher->post($publisher->confirmUrl(), $publisher->confirmBody($upload->token, $ask, $locale));
            $upload = $publisher->read($answer->status, $answer->body) ?? $upload;
        }

        return $this->keep($key, $publisher->host(), $upload);
    }

    /**
     * Asks the server again what it holds, by uploading the same key —
     * which changes nothing there and mails nobody. Only for a key already
     * published there: refreshing must never be a way to publish. For a
     * compromised key it is the revocation that is uploaded, so one that did
     * not get through when the key was marked gets through now.
     *
     * @return list<KeyPublication>
     */
    public function refresh(User $user, int $addressKeyId, string $host): array
    {
        $key = $this->owned($user, $addressKeyId);

        if (null !== $key->revokedPublicKey) {
            $this->revokeOn($user, $key, strtolower($host));

            return $this->publications->findForKey($key);
        }

        $publisher = $this->publisherFor($user, $host);

        if ([] === $this->rowsOn($key, $publisher->host())) {
            throw new ContactKeyRefused('encryption.publish.not_published');
        }

        $this->fetcher->spend($user);

        return $this->keep($key, $publisher->host(), $this->upload($publisher, $key, $key->publicKey));
    }

    /**
     * The key, now marked compromised, with its revocation — as the browser
     * made it. Kept on the key, and uploaded to every server the key was
     * published to (see the class docblock for why the person's switch does
     * not stop this). A failure is logged and left for refresh(); marking
     * the key compromised does not wait on a key server.
     *
     * @throws ContactKeyRefused when $revoked is not this key, revoked
     */
    public function revoke(User $user, int $addressKeyId, string $revoked): void
    {
        $key        = $this->owned($user, $addressKeyId);
        $revokedKey = null;

        foreach ($this->reader->read($revoked) as $candidate) {
            if ($candidate->fingerprint === $key->fingerprint && true === $candidate->revoked) {
                $revokedKey = $candidate;
            }
        }

        if (null === $revokedKey) {
            throw new ContactKeyRefused('encryption.publish.not_a_revocation');
        }

        $key->keepRevocation($revokedKey->armored);
        $this->entityManager->flush();

        foreach (array_unique(array_map(static fn (KeyPublication $row): string => $row->host, $this->publications->findForKey($key))) as $host) {
            $this->revokeOn($user, $key, $host);
        }
    }

    /**
     * Every row of the person's, by address key id, for the keyring view.
     *
     * @return array<int, list<array{host: string, email: string, state: string, checkedAt: string}>>
     */
    public function view(User $user): array
    {
        $view = [];

        foreach ($this->publications->findForUserByKey($user) as $keyId => $rows) {
            $view[$keyId] = array_map(static fn (KeyPublication $row): array => [
                'host'      => $row->host,
                'email'     => $row->email,
                'state'     => $row->state->value,
                'checkedAt' => $row->checkedAt->format(DateTimeInterface::ATOM),
            ], $rows);
        }

        return $view;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The servers the installation lists that plMail can publish to.
     *
     * @return list<string>
     */
    private function installed(): array
    {
        return array_values(array_filter($this->keyServers->hosts(), fn (string $host): bool => isset($this->publishers[$host])));
    }

    private function publisherFor(User $user, string $host): KeyPublisherInterface
    {
        $host = strtolower($host);

        if (false === in_array($host, $this->hostsFor($user), true)) {
            throw new ContactKeyRefused('encryption.publish.unavailable');
        }

        return $this->publishers[$host];
    }

    private function owned(User $user, int $addressKeyId): AddressKey
    {
        $key = $this->addressKeys->find($addressKeyId);

        if (null === $key || $key->usr->id !== $user->id) {
            throw new ContactKeyRefused('encryption.publish.not_found');
        }

        return $key;
    }

    private function upload(KeyPublisherInterface $publisher, AddressKey $key, string $armored): KeyServerUpload
    {
        $answer = $this->fetcher->post($publisher->uploadUrl(), $publisher->uploadBody($armored));
        $upload = $publisher->read($answer->status, $answer->body);

        if (null === $upload || $upload->fingerprint !== $key->fingerprint) {
            $this->logger->info('Publishing key {fingerprint} to {host} failed: HTTP {status}', [
                'fingerprint' => $key->fingerprint,
                'host'        => $publisher->host(),
                'status'      => $answer->status,
            ]);

            throw new ContactKeyRefused('encryption.publish.failed');
        }

        return $upload;
    }

    /**
     * The revocation to one server, if the installation still lists it.
     * Failing quietly: the copy on the key is uploaded again on refresh.
     */
    private function revokeOn(User $user, AddressKey $key, string $host): void
    {
        if (null === $key->revokedPublicKey || false === in_array($host, $this->installed(), true) || [] === $this->rowsOn($key, $host)) {
            return;
        }

        try {
            $this->fetcher->spend($user);
            $this->keep($key, $host, $this->upload($this->publishers[$host], $key, $key->revokedPublicKey));
        } catch (ContactKeyRefused $refused) {
            $this->logger->warning('The revocation of key {fingerprint} did not reach {host} ({reason}); it is sent again on the next refresh.', [
                'fingerprint' => $key->fingerprint,
                'host'        => $host,
                'reason'      => $refused->translationKey,
            ]);
        }
    }

    /**
     * @return list<KeyPublication>
     */
    private function rowsOn(AddressKey $key, string $host): array
    {
        return array_values(array_filter($this->publications->findForKey($key), static fn (KeyPublication $row): bool => $host === $row->host));
    }

    /**
     * The server's answer, one row per address of the key. An address the
     * answer does not mention is one the server holds no user ID for — it
     * stripped it, or never saw it — which is "unpublished" in its words.
     *
     * @return list<KeyPublication>
     */
    private function keep(AddressKey $key, string $host, KeyServerUpload $upload): array
    {
        $now  = $this->clock->now();
        $rows = [];

        foreach ($this->rowsOn($key, $host) as $row) {
            $rows[$row->email] = $row;
        }

        foreach ($key->addresses() as $email) {
            $state = $upload->states[$email] ?? (null !== $key->revokedPublicKey ? KeyPublicationState::Revoked : KeyPublicationState::Unpublished);

            if (isset($rows[$email])) {
                $rows[$email]->update($state, $now);
            } else {
                $rows[$email] = new KeyPublication($key, $host, $email, $state, $now);
                $this->entityManager->persist($rows[$email]);
            }
        }

        $this->entityManager->flush();

        return array_values($rows);
    }
}
