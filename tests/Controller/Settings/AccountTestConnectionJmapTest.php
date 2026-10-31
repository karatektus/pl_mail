<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Repository\User\UserRepository;
use App\Tests\Support\Jmap\FakeJmapServer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test connection on the account form probes the protocol the form names.
 *
 * ConnectionTesterJmapTest covers the tester; this covers the endpoint in
 * front of it, which builds the account to test from the request. It used to
 * copy only the host, port and credentials, so a JMAP account was probed as
 * IMAP: the server on 443 "could not be reached" and sending was "disabled",
 * on an account that worked once saved. Found against a real Stalwart.
 */
final class AccountTestConnectionJmapTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The fake server is set on this kernel's container; a reboot between
        // the form and the probe would hand the probe a fresh one without it.
        $this->client->disableReboot();

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($user);
        static::getContainer()->set('app.http_client.integration', new FakeJmapServer()->client());
    }

    public function testAJmapAccountIsProbedOverJmap(): void
    {
        $result = $this->probe(['incomingProtocol' => 'jmap']);

        self::assertTrue($result['imap']['ok'], (string) $result['imap']['message']);
        self::assertTrue($result['smtp']['ok'], 'sending goes through submission, not a missing SMTP host');
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{imap: array{ok: bool|null, message: string}, smtp: array{ok: bool|null, message: string}}
     */
    private function probe(array $fields): array
    {
        $form = $this->client->request('GET', '/account/new');
        $csrf = $form->filter('[data-settings--connection-test-csrf-value]')->attr('data-settings--connection-test-csrf-value');

        $this->client->request('POST', '/account/test-connection', server: [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => (string) $csrf,
        ], content: (string) json_encode($fields + [
            'username'       => 'me@example.test',
            'password'       => 'app-password',
            'imapHost'       => 'jmap.example.test',
            'imapPort'       => '443',
            'imapEncryption' => 'ssl',
            'smtpHost'       => '',
        ]));

        self::assertResponseIsSuccessful();

        /** @var array{imap: array{ok: bool|null, message: string}, smtp: array{ok: bool|null, message: string}} */
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
