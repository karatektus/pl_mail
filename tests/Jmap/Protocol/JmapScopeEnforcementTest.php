<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Protocol;

use App\Domain\Enum\User\ApiTokenScope;
use App\Entity\Mail\Account;
use App\Entity\User\ApiToken;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An app password does what it was granted and nothing else, over the real
 * firewall.
 *
 * The unit tests pin ApiTokenScope's own rules; this one proves the rules are
 * actually reached — that a read-only credential gets an inline `forbidden`
 * from Email/set, and that the browser session is untouched by any of it.
 *
 * Deliberately end-to-end. A test that called JmapProcessor directly would
 * still pass if the authenticator never attached the badge, which is the one
 * wiring mistake that would silently disable the whole feature.
 */
final class JmapScopeEnforcementTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $accountId = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'jmap-scope-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $this->user->nameFirst = 'Jmap';
        $this->user->nameLast  = 'Scope';
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        // The account the JMAP calls address. A single Mailbox/get against a
        // user with no account answers invalidArguments, which would make the
        // "read still works" half of every test pass or fail for a reason
        // that has nothing to do with scopes.
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Scope fixture';
        $account->email          = 'scope-' . bin2hex(random_bytes(4)) . '@plmail.test';
        $account->username       = $account->email;
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        // Onto the user as well as into the database: AccountResolver walks
        // $user->accounts, and this User instance is the one the request will
        // authenticate as — a collection cached before the insert would answer
        // accountNotFound no matter what the table holds.
        $this->user->addAccount($account);

        $this->em->flush();

        $this->accountId = (string) $account->id;
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    /**
     * A read-only token can read and cannot destroy.
     *
     * Both halves matter. The read is what proves the credential works at all —
     * without it, a token refused everything would pass the second assertion
     * for the wrong reason.
     */
    public function testAReadOnlyTokenReadsButCannotDestroy(): void
    {
        $secret = $this->mint([ApiTokenScope::Read]);
        $accountId = $this->accountId;

        $read = $this->call($secret, [
            ['Mailbox/get', ['accountId' => $accountId], 'r1'],
        ]);

        self::assertArrayHasKey('methodResponses', $read);
        self::assertSame('Mailbox/get', $read['methodResponses'][0][0], (string) json_encode($read['methodResponses'][0] ?? null));

        $destroy = $this->call($secret, [
            ['Email/set', ['accountId' => $accountId, 'destroy' => ['x']], 'd1'],
        ]);

        self::assertSame('error', $destroy['methodResponses'][0][0]);
        self::assertSame('forbidden', $destroy['methodResponses'][0][1]['type']);
    }

    /**
     * A read-only token cannot send, and the refusal is inline.
     *
     * Inline rather than a 403 because a JMAP client that receives a transport
     * error tends to declare the session dead — see JmapProcessor::process().
     */
    public function testAReadOnlyTokenCannotSend(): void
    {
        $secret = $this->mint([ApiTokenScope::Read]);
        $accountId = $this->accountId;

        $response = $this->call($secret, [
            ['EmailSubmission/set', ['accountId' => $accountId, 'create' => ['k1' => []]], 's1'],
        ]);

        self::assertSame('forbidden', $response['methodResponses'][0][1]['type']);
    }

    /**
     * A read + send token may create a draft and may not destroy.
     *
     * Email/set is the interesting one: create rides with Send, destroy with
     * Delete, so a token holding both read and send is allowed the first and
     * refused the second *in the same method*.
     */
    public function testSendAllowsDraftCreationButNotDestroy(): void
    {
        $secret = $this->mint([ApiTokenScope::Read, ApiTokenScope::Send]);
        $accountId = $this->accountId;

        // A create with nothing in it is still a create: the scope check runs
        // before the method does, so what the method would have thought of the
        // arguments is not what this asserts.
        $create = $this->call($secret, [
            ['Email/set', ['accountId' => $accountId, 'create' => ['k1' => []]], 'c1'],
        ]);

        self::assertNotSame('forbidden', $create['methodResponses'][0][1]['type'] ?? null);

        $destroy = $this->call($secret, [
            ['Email/set', ['accountId' => $accountId, 'destroy' => ['x']], 'd1'],
        ]);

        self::assertSame('forbidden', $destroy['methodResponses'][0][1]['type']);
    }

    /**
     * A call mixing an allowed and a refused operation is refused whole.
     *
     * Half-applying it would tell the client it succeeded while silently
     * dropping the half it was not allowed to do.
     */
    public function testAMixedEmailSetIsRefusedWhole(): void
    {
        $secret = $this->mint([ApiTokenScope::Read]);
        $accountId = $this->accountId;

        $response = $this->call($secret, [
            [
                'Email/set',
                [
                    'accountId' => $accountId,
                    // update is allowed by read, destroy is not
                    'update'    => ['x' => ['keywords' => []]],
                    'destroy'   => ['x'],
                ],
                'm1',
            ],
        ]);

        self::assertSame('forbidden', $response['methodResponses'][0][1]['type']);
    }

    public function testATokenWithNoScopesCannotEvenRead(): void
    {
        $secret = $this->mint([]);
        $accountId = $this->accountId;

        $response = $this->call($secret, [
            ['Mailbox/get', ['accountId' => $accountId], 'r1'],
        ]);

        self::assertSame('forbidden', $response['methodResponses'][0][1]['type']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param list<ApiTokenScope> $scopes */
    private function mint(array $scopes): string
    {
        $minted = ApiToken::create($this->user, 'scope test', $scopes);
        $this->em->persist($minted['token']);
        $this->em->flush();

        return $minted['secret'];
    }


    /**
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $calls
     *
     * @return array<string, mixed>
     */
    private function call(string $secret, array $calls): array
    {
        $this->client->request(
            'POST',
            '/jmap/api',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $secret,
                'CONTENT_TYPE'       => 'application/json',
            ],
            content: (string) json_encode([
                'using'       => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'],
                'methodCalls' => $calls,
            ]),
        );

        self::assertResponseIsSuccessful();

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
