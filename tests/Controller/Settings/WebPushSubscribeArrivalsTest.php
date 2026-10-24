<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Push\PushDeliveryRecorder;
use App\Jmap\Push\WebPushSender;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\VAPID;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The in-app browser/PWA registration is what narrows a row to new-mail pushes,
 * and it must do so on BOTH of its branches: a device seen for the first time,
 * and a device that re-subscribes with a rotated endpoint and keeps its id.
 *
 * The request is real. The only thing replaced is the Web Push sender, and only
 * its HTTP client: it is built with a throwaway VAPID pair and a MockHttpClient,
 * so the verification push the controller sends lands on the mock and never on
 * a push service. The endpoint is an IP literal because a hostname would be
 * resolved by PrivateNetwork, which is a DNS query.
 *
 * Each "narrowed" assertion has its absence companion in the same test: the row
 * a JMAP client creates through the factory, which PushSubscription/set uses, is
 * still on the full stream after the in-app device has been narrowed.
 *
 * Skips itself without the seeded admin, as the other controller tests do.
 */
final class WebPushSubscribeArrivalsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /** A public address written as a literal, so no name is resolved. */
    private const string ENDPOINT = 'https://93.184.215.14/wpush/v2/';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PushSubscriptionRepository $subscriptions;
    private User $user;

    /** @var list<string> the URLs the verification pushes were sent to */
    private array $pushes = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // The rows are staged inside a rolled-back transaction, which a kernel
        // reboot between requests would not be able to see. Same as
        // PushDeviceRemovalTest.
        $this->client->disableReboot();

        $container           = static::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->subscriptions = $container->get(PushSubscriptionRepository::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;

        $keys = VAPID::createVapidKeys();

        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $this->pushes[] = $url;

            return new MockResponse('', ['http_code' => 201]);
        });

        // Built by hand with the public constructor, which is what a test may do
        // with a final class. The controller takes whatever the container holds.
        $container->set(WebPushSender::class, new WebPushSender(
            $this->em,
            new PushDeliveryRecorder($this->em, new NullLogger()),
            new NullLogger(),
            'mailto:admin@example.test',
            $keys['publicKey'],
            $keys['privateKey'],
            $http,
        ));

        $this->client->loginUser($user);

        $container->get(EntityManagerInterface::class)->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        if (true === $connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testANewInAppDeviceIsNarrowedAndAJmapClientRowIsNot(): void
    {
        $jmapClient = PushSubscription::webPush($this->user, 'a-jmap-client', self::ENDPOINT . 'jmap');
        $this->em->persist($jmapClient);
        $this->em->flush();

        $this->subscribe('web-new-device', self::ENDPOINT . 'new');

        self::assertNotEmpty($this->pushes, 'the verification push went to the mock, not the network');

        $this->em->clear();

        self::assertTrue($this->stored('web-new-device')->arrivalsOnly, 'presence: the in-app device is narrowed');
        self::assertFalse($this->stored('a-jmap-client')->arrivalsOnly, 'absence: a JMAP client row keeps the full stream');
    }

    public function testAnExistingInAppDeviceIsNarrowedWhenItResubscribesWithARotatedEndpoint(): void
    {
        $existing = PushSubscription::webPush($this->user, 'web-existing-device', self::ENDPOINT . 'old');
        $this->em->persist($existing);
        $this->em->flush();

        self::assertFalse($existing->arrivalsOnly, 'the row starts on the full stream, so the test can see it change');

        $this->subscribe('web-existing-device', self::ENDPOINT . 'rotated');

        $this->em->clear();

        $row = $this->stored('web-existing-device');

        self::assertSame(self::ENDPOINT . 'rotated', $row->url, 'presence: the rotated endpoint was taken, so this is the re-subscribe branch');
        self::assertTrue($row->arrivalsOnly, 'presence: the re-subscribed device is narrowed');
    }

    private function subscribe(string $deviceClientId, string $endpoint): void
    {
        $body = [
            'endpoint'       => $endpoint,
            'keys'           => [
                'p256dh' => $this->p256dh(),
                'auth'   => $this->authSecret(),
            ],
            'deviceClientId' => $deviceClientId,
        ];

        $this->client->request(
            'POST',
            '/settings/push/subscribe',
            server: [
                'CONTENT_TYPE'   => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken('web-push'),
            ],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
    }

    private function stored(string $deviceClientId): PushSubscription
    {
        $subscription = $this->subscriptions->findOneByDeviceClientId($this->user, $deviceClientId);

        self::assertNotNull($subscription, sprintf('no row for %s', $deviceClientId));

        return $subscription;
    }

    /**
     * A real P-256 point, the shape a browser hands over: the library encrypts
     * the verification payload to it. VAPID's public key has that shape too.
     */
    private function p256dh(): string
    {
        return VAPID::createVapidKeys()['publicKey'];
    }

    private function authSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * The token manager reads the session off the current request, and there is
     * no current request between two client calls, so the page the form lives on
     * is fetched and its session pushed onto the stack. As PushDeviceRemovalTest
     * does it.
     */
    private function csrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/settings?section=notifications');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($this->client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken($tokenId)
                ->getValue();
        } finally {
            $this->client->getRequest()->getSession()->save();
            $stack->pop();
        }
    }
}
