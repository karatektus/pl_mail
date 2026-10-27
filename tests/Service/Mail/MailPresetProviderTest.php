<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\DTO\MailPreset;
use App\Service\Mail\MailPresetProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shipped provider presets, read from config/mail_presets.yaml.
 *
 * Every preset is an IMAP server; POP3 and JMAP are there only for a provider
 * whose server could be confirmed. What can rot is the file itself — a block
 * missing its port, an encryption the form has no choice for — and that fails
 * in a browser, after somebody has picked the provider, rather than here.
 */
final class MailPresetProviderTest extends TestCase
{
    private const array ENCRYPTIONS = ['ssl', 'starttls', 'none'];

    private MailPresetProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MailPresetProvider(dirname(__DIR__, 3) . '/config/mail_presets.yaml');
    }

    public function testEveryPresetIsAnImapServerWithSmtp(): void
    {
        self::assertNotEmpty($this->provider->all());

        foreach ($this->provider->all() as $key => $preset) {
            self::assertNotSame('', $preset->imapHost, $key);
            self::assertContains($preset->imapEncryption, self::ENCRYPTIONS, $key);
            self::assertNotSame('', $preset->smtpHost, $key);
            self::assertContains($preset->smtpEncryption, self::ENCRYPTIONS, $key);
        }
    }

    /** A POP3 block is whole or absent: a host without its port is a form half filled in. */
    public function testAPop3ServerIsWholeWhereThereIsOne(): void
    {
        $withPop3 = array_filter($this->provider->all(), static fn (MailPreset $preset): bool => null !== $preset->pop3Host);

        self::assertNotEmpty($withPop3);

        foreach ($withPop3 as $key => $preset) {
            self::assertNotSame('', $preset->pop3Host, $key);
            self::assertGreaterThan(0, $preset->pop3Port, $key);
            self::assertContains($preset->pop3Encryption, self::ENCRYPTIONS, $key);
        }
    }

    /** iCloud has no POP3, and a preset that has none says so by its absence. */
    public function testAProviderWithoutPop3HasNoneInTheClientPayload(): void
    {
        $icloud = $this->provider->get('icloud');

        self::assertNotNull($icloud);
        self::assertNull($icloud->pop3Host);
        self::assertNull($icloud->toClientArray()['pop3']);
        self::assertNull($icloud->toClientArray()['jmap']);
    }

    /**
     * Fastmail's JMAP: its own host, a session that is not at the well-known
     * path, and an API token rather than an app password.
     */
    public function testFastmailCarriesItsJmapServer(): void
    {
        $fastmail = $this->provider->get('fastmail');

        self::assertNotNull($fastmail);

        $jmap = $fastmail->toClientArray()['jmap'];

        self::assertSame('api.fastmail.com', $jmap['host']);
        self::assertSame('https://api.fastmail.com/jmap/session', $jmap['sessionUrl']);
        self::assertTrue($jmap['tokenAuth']);
        self::assertNotNull($jmap['note']);
        self::assertSame('pop.fastmail.com', $fastmail->toClientArray()['pop3']['host']);
    }

    public function testAJmapSessionUrlIsHttps(): void
    {
        foreach ($this->provider->all() as $key => $preset) {
            if (null !== $preset->jmapSessionUrl) {
                self::assertStringStartsWith('https://', $preset->jmapSessionUrl, $key);
            }
        }
    }
}
