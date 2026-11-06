<?php

declare(strict_types=1);

namespace App\Service\Integration\BrowserLogin;

use App\Domain\DTO\Integration\BrowserLoginPoll;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Infrastructure\Encryption\Encryptor;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Integration\IntegrationConnector;
use App\Service\Integration\IntegrationUrlValidator;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Connecting by approving in the browser, for any provider with a
 * BrowserLoginFlowInterface (#235).
 *
 * The provider-specific half is the flow; everything around it is here, once:
 * which address to start from, the in-flight state, which connection the
 * result lands on, and saving it through IntegrationConnector so it gets the
 * same probe and the same success/failure recording as the manual form.
 *
 * **In-flight state lives in cache.app, not the session or the user's settings
 * bag.** Keyed by a random id, so a second tab starting a second approval does
 * not overwrite the first, and gone after 20 minutes — Nextcloud's own limit —
 * without anything to sweep, the way DevicePairingService keeps its codes. The
 * flow's state (a Nextcloud poll token, which is exchanged for an app password
 * by whoever presents it first) is encrypted with the key the stored
 * credentials use, so a cache dump is not a way in either. The id is bound to
 * the user who started it, and only that user's poll can collect it.
 *
 * **Which connection.** Editing one updates it — how a revoked app password is
 * replaced. Connecting makes a new one, and the name is checked for a clash at
 * the start rather than after the person has gone through the approval. The
 * setup wizard passes the row it would have edited, so it never makes a second.
 *
 * **Which address.** The admin's pin when there is one; otherwise the one the
 * person typed, after IntegrationUrlValidator has allowed it. Never the address
 * a server names in its answer: see NextcloudLoginFlow.
 */
final readonly class BrowserLoginService
{
    /** Nextcloud's own lifetime for a login flow. */
    private const int TTL_SECONDS = 1200;

    private const int ID_BYTES = 24;

    private const string PREFIX = 'integration_browser_login_';

    public function __construct(
        private BrowserLoginFlowRegistry $flows,
        private IntegrationConnector $connector,
        private IntegrationRepository $integrations,
        private IntegrationUrlValidator $urlValidator,
        private CacheItemPoolInterface $cache,
        private Encryptor $encryptor,
    ) {
    }

    public function supports(Provider $provider): bool
    {
        return $this->flows->supports($provider);
    }

    /**
     * Begin an approval.
     *
     * @param Integration|null $existing the connection being edited, already
     *                                   checked to be the user's own
     * @param string|null      $baseUrl  what the person typed, when the address
     *                                   is theirs to choose
     *
     * @return array{id:string,loginUrl:string}
     *
     * @throws NotFoundHttpException for a provider that cannot be connected
     *                               this way, or not at all
     * @throws IntegrationException  with a message fit to show the person
     */
    public function start(User $user, Provider $provider, ?Integration $existing, string $name, ?string $baseUrl): array
    {
        $config = $this->connector->requireConnectable($provider);
        $flow = $this->flows->for($provider) ?? throw new NotFoundHttpException();

        if (null !== $existing && $existing->provider !== $provider) {
            throw new NotFoundHttpException();
        }

        $ownUrl = $this->connector->isUrlEditable($provider, $config);

        if (true === $ownUrl) {
            $address = rtrim(trim((string) ($baseUrl ?? $existing?->baseUrl)), '/');

            if ('' === $address) {
                throw new IntegrationException('Enter the server address first.');
            }

            $this->urlValidator->assertAllowed($address);
        } else {
            $address = rtrim((string) $config->baseUrl, '/');
        }

        // Normalised the way the entity will store it, so "" and "  " clash
        // with the default name exactly as the save would.
        $name = (new Integration($user, $provider, $name))->name;

        if (null === $existing && $this->nameTaken($user, $provider, $name)) {
            throw new IntegrationException('You already have a connection with this name. Choose another name first.');
        }

        $started = $flow->start($address);

        $id = rtrim(strtr(base64_encode(random_bytes(self::ID_BYTES)), '+/', '-_'), '=');

        $item = $this->cache->getItem($this->key($id));
        $item->set([
            'user'        => (int) $user->id,
            'provider'    => $provider->value,
            'integration' => $existing?->id,
            'name'        => $name,
            'baseUrl'     => $address,
            'ownUrl'      => $ownUrl,
            'state'       => $this->encryptor->encrypt(json_encode($started->state, JSON_THROW_ON_ERROR)),
        ]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return ['id' => $id, 'loginUrl' => $started->loginUrl];
    }

    /**
     * Ask whether the person has approved, and save the connection once they
     * have. The state is dropped the moment the flow answers for good, either
     * way, so a result is collected once.
     */
    public function poll(User $user, string $id): BrowserLoginPoll
    {
        $key = $this->key($id);
        $item = $this->cache->getItem($key);

        // An unknown id, an expired one and another user's all read the same:
        // telling them apart would confirm which ids had once been real.
        $expired = BrowserLoginPoll::failed('This approval has expired. Start again.');

        if (false === $item->isHit()) {
            return $expired;
        }

        /** @var array{user:int,provider:string,integration:?int,name:string,baseUrl:string,ownUrl:bool,state:string} $stored */
        $stored = $item->get();

        if ((int) $user->id !== $stored['user']) {
            return $expired;
        }

        $provider = Provider::tryFrom($stored['provider']);
        $flow = null === $provider ? null : $this->flows->for($provider);

        if (null === $provider || null === $flow) {
            $this->cache->deleteItem($key);

            return $expired;
        }

        /** @var array<string,string> $state */
        $state = json_decode($this->encryptor->decrypt($stored['state']), true, 4, JSON_THROW_ON_ERROR);

        try {
            $credential = $flow->poll($stored['baseUrl'], $state);
        } catch (IntegrationException $e) {
            $this->cache->deleteItem($key);

            return BrowserLoginPoll::failed($e->getMessage());
        }

        if (null === $credential) {
            return BrowserLoginPoll::pending();
        }

        $this->cache->deleteItem($key);

        $integration = $this->target($user, $provider, $stored['integration'], $stored['name']);

        if (null === $integration) {
            // Deleted while the person was approving, or the name was taken in
            // another tab meanwhile. The app password exists in Nextcloud now
            // and is simply unused; saying so lets them revoke it.
            return BrowserLoginPoll::failed('The approval worked, but the connection could not be saved here. Start again, and revoke the unused device in the service.');
        }

        if (true === $stored['ownUrl']) {
            $integration->baseUrl = $stored['baseUrl'];
        }

        $integration->username = $credential->username;

        return BrowserLoginPoll::connected(
            $integration,
            $this->connector->saveObtained($integration, $credential->secret),
        );
    }

    private function target(User $user, Provider $provider, ?int $integrationId, string $name): ?Integration
    {
        if (null !== $integrationId) {
            $integration = $this->integrations->find($integrationId);

            return null !== $integration && $integration->usr->id === $user->id && $integration->provider === $provider
                ? $integration
                : null;
        }

        if ($this->nameTaken($user, $provider, $name)) {
            return null;
        }

        return new Integration($user, $provider, $name);
    }

    private function nameTaken(User $user, Provider $provider, string $name): bool
    {
        return null !== $this->integrations->findOneBy(['usr' => $user, 'provider' => $provider, 'name' => $name]);
    }

    private function key(string $id): string
    {
        return self::PREFIX.hash('sha256', $id);
    }
}
