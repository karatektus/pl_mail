<?php

declare(strict_types=1);

namespace App\Tests\Service\User\Libravatar;

use App\Domain\Enum\User\LibravatarDnsStatus;
use App\Service\User\Libravatar\LibravatarDnsCheck;
use App\Tests\Support\Mail\FakeDns;
use PHPUnit\Framework\TestCase;

/**
 * What "Check DNS" tells an administrator, per domain (#642).
 *
 * Each answer is an instruction — add the record, fix its host, fix its
 * port, or leave a mail provider's domain alone — so each is pinned against
 * the DNS that should produce it, including the trailing dot DNS tools print
 * and a host name in other capitals, which are the same host.
 */
final class LibravatarDnsCheckTest extends TestCase
{
    private const string HOST = 'mail.own.example';

    public function testEachRecordGivesTheAnswerThatSaysWhatToDo(): void
    {
        $check = new LibravatarDnsCheck(new FakeDns([], [
            '_avatars-sec._tcp.right.example'  => [['target' => 'Mail.Own.Example.', 'port' => 443, 'priority' => 0, 'weight' => 0]],
            '_avatars-sec._tcp.other.example'  => [['target' => 'avatars.other.example', 'port' => 443, 'priority' => 0, 'weight' => 0]],
            '_avatars-sec._tcp.port.example'   => [['target' => self::HOST, 'port' => 8443, 'priority' => 0, 'weight' => 0]],
        ]));

        self::assertSame(LibravatarDnsStatus::Ok, $check->check('right.example', self::HOST)['status']);
        self::assertSame(LibravatarDnsStatus::Missing, $check->check('none.example', self::HOST)['status']);
        self::assertSame(LibravatarDnsStatus::Elsewhere, $check->check('other.example', self::HOST)['status']);
        self::assertSame('avatars.other.example:443', $check->check('other.example', self::HOST)['found']);
        self::assertSame(LibravatarDnsStatus::WrongPort, $check->check('port.example', self::HOST)['status']);
    }

    public function testAMailProvidersDomainIsNotAskedAbout(): void
    {
        $dns = new FakeDns();

        self::assertSame(LibravatarDnsStatus::NotYours, new LibravatarDnsCheck($dns)->check('gmail.com', self::HOST)['status']);
        self::assertSame([], $dns->asked);
    }

    public function testWithoutAPublicAddressNoRecordCanBeRight(): void
    {
        $check = new LibravatarDnsCheck(new FakeDns([], [
            '_avatars-sec._tcp.right.example' => [['target' => self::HOST, 'port' => 443, 'priority' => 0, 'weight' => 0]],
        ]));

        self::assertSame(LibravatarDnsStatus::Elsewhere, $check->check('right.example', null)['status']);
    }
}
