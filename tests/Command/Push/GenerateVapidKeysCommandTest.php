<?php

declare(strict_types=1);

namespace App\Tests\Command\Push;

use App\Command\Push\GenerateVapidKeysCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Minting the Web Push keypair.
 *
 * The output *is* the deliverable: whatever is printed gets pasted into
 * .env.local or a compose file, and a subtly wrong line there fails later, at
 * subscription time, in a browser, with no connection back to this command. So
 * the tests check the shape of what was printed rather than that it printed.
 */
final class GenerateVapidKeysCommandTest extends TestCase
{
    public function testItPrintsEnvLinesForBothHalvesOfTheKeypair(): void
    {
        $tester = $this->run_();

        $display = $tester->getDisplay();

        self::assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=\S+$/m', $display);
        self::assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=\S+$/m', $display);
        self::assertStringContainsString('VAPID_SUBJECT=mailto:', $display);
    }

    /**
     * P-256 uncompressed point, base64url. A browser rejects the subscription
     * outright if the applicationServerKey is not exactly this, and the
     * rejection surfaces nowhere near here.
     */
    public function testThePublicKeyIsAUsableApplicationServerKey(): void
    {
        $key = $this->printedValue($this->run_()->getDisplay(), 'VAPID_PUBLIC_KEY');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $key, 'Must be base64url, not standard base64.');

        $decoded = base64_decode(strtr($key, '-_', '+/'), true);

        self::assertIsString($decoded);
        self::assertSame(65, strlen($decoded));
        self::assertSame("\x04", $decoded[0], 'An uncompressed EC point starts with 0x04.');
    }

    public function testEachRunMintsAFreshKeypair(): void
    {
        $first  = $this->printedValue($this->run_()->getDisplay(), 'VAPID_PUBLIC_KEY');
        $second = $this->printedValue($this->run_()->getDisplay(), 'VAPID_PUBLIC_KEY');

        self::assertNotSame($first, $second);
    }

    /**
     * Rotating unsubscribes every device that ever registered, and there is no
     * way to undo that from the app's side. The warning is the only thing
     * standing between an operator and finding out afterwards.
     */
    public function testItWarnsThatRotatingInvalidatesExistingSubscriptions(): void
    {
        self::assertStringContainsString('invalidates every existing PushSubscription', $this->run_()->getDisplay());
    }

    private function run_(): CommandTester
    {
        $tester = new CommandTester(new GenerateVapidKeysCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        return $tester;
    }

    private function printedValue(string $display, string $name): string
    {
        self::assertSame(
            1,
            preg_match('/^' . preg_quote($name, '/') . '=(\S+)$/m', $display, $matches),
            sprintf('%s was not printed exactly once.', $name),
        );

        return $matches[1];
    }
}
