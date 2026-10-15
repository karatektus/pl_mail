<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Mail\Account;
use App\Form\AccountType;
use App\Service\Mail\SmtpDsnFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * An account has one password unless somebody asks for a second, for sending.
 *
 * Some servers want a different password to send than to read — Zoho issues an
 * app password per protocol — and until there was somewhere to put it such an
 * account could be added, would sync, and failed every send on authentication
 * (#42). The form shows one field and a button; the button ticks a box and
 * reveals the second.
 *
 * What is pinned here is that the BOX decides and the field does not. A
 * password manager fills a password input whether or not it is on screen, and
 * a blank field on the edit form means "keep what is stored", so neither a
 * value nor a blank says whether the account is meant to have two passwords.
 *
 * Through the real form type and the real factory, because the rule lives in
 * the form's own listeners and a submitted array is the only honest input.
 */
final class AccountSendingPasswordTest extends KernelTestCase
{
    private const array FIELDS = [
        'email'          => 'someone@example.test',
        'username'       => 'someone@example.test',
        'password'       => 'the-reading-password',
        'imapHost'       => 'imap.example.test',
        'imapPort'       => '993',
        'imapEncryption' => 'ssl',
        'smtpHost'       => 'smtp.example.test',
        'smtpPort'       => '465',
        'smtpEncryption' => 'ssl',
    ];

    public function testWithoutTheBoxThereIsOnePasswordWhateverTheHiddenFieldWasSentWith(): void
    {
        $account = new Account();

        // A password manager's doing: the field is not on screen, and it was
        // filled anyway.
        $this->form($account)->submit(self::FIELDS + ['smtpPassword' => 'filled-in-by-a-password-manager']);

        self::assertNull($account->smtpPassword);
        self::assertSame('the-reading-password', $account->sendingPassword);
    }

    public function testWithTheBoxTheSecondPasswordIsStoredAndIsWhatSends(): void
    {
        $account = new Account();

        $this->form($account)->submit(self::FIELDS + [
            'separateSmtpPassword' => '1',
            'smtpPassword'         => 'the-sending-password',
        ]);

        self::assertSame('the-sending-password', $account->smtpPassword);
        self::assertSame('the-reading-password', $account->password, 'the first one is untouched');

        $dsn = static::getContainer()->get(SmtpDsnFactory::class)->forAccount($account);

        self::assertStringContainsString('the-sending-password', $dsn);
        self::assertStringNotContainsString('the-reading-password', $dsn);
    }

    /** The edit form's rule for the first password, kept for the second. */
    public function testOnEditABlankFieldWithTheBoxStillTickedKeepsTheStoredOne(): void
    {
        $account               = $this->saved();
        $account->smtpPassword = 'stored-sending-password';

        $form = $this->form($account, false);

        self::assertTrue($form->get('separateSmtpPassword')->getData(), 'an account that has one opens with the box ticked');

        $form->submit(self::FIELDS + ['separateSmtpPassword' => '1', 'smtpPassword' => '']);

        self::assertSame('stored-sending-password', $account->smtpPassword);
    }

    public function testOnEditClearingTheBoxGoesBackToOnePassword(): void
    {
        $account               = $this->saved();
        $account->smtpPassword = 'stored-sending-password';

        $this->form($account, false)->submit(self::FIELDS + ['smtpPassword' => '']);

        self::assertNull($account->smtpPassword);
        self::assertSame($account->password, $account->sendingPassword);
    }

    /** Presence for the two above: a new value with the box ticked replaces the stored one. */
    public function testOnEditANewValueReplacesTheStoredOne(): void
    {
        $account               = $this->saved();
        $account->smtpPassword = 'stored-sending-password';

        $this->form($account, false)->submit(self::FIELDS + [
            'separateSmtpPassword' => '1',
            'smtpPassword'         => 'a-new-sending-password',
        ]);

        self::assertSame('a-new-sending-password', $account->smtpPassword);
    }

    public function testAnAccountWithoutOneOpensWithTheBoxClear(): void
    {
        self::assertFalse($this->form($this->saved(), false)->get('separateSmtpPassword')->getData());
    }

    /** An error that quotes the address it failed on must not print either password. */
    public function testBothPasswordsAreKeptOutOfAnErrorThatQuotesThem(): void
    {
        $account               = $this->saved();
        $account->smtpPassword = 'the-sending-password';

        $redacted = static::getContainer()->get(SmtpDsnFactory::class)->redact(
            'smtp said no to the-sending-password and imap to the-reading-password',
            $account,
        );

        self::assertSame('smtp said no to *** and imap to ***', $redacted);
    }

    /** @return FormInterface<Account> */
    private function form(Account $account, bool $requirePassword = true): FormInterface
    {
        return static::getContainer()->get(FormFactoryInterface::class)->create(AccountType::class, $account, [
            'csrf_protection'  => false,
            'require_password' => $requirePassword,
        ]);
    }

    private function saved(): Account
    {
        $account = new Account();
        $account->authType       = 'password';
        $account->email          = self::FIELDS['email'];
        $account->username       = self::FIELDS['username'];
        $account->password       = self::FIELDS['password'];
        $account->imapHost       = self::FIELDS['imapHost'];
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = self::FIELDS['smtpHost'];
        $account->smtpPort       = 465;
        $account->smtpEncryption = 'ssl';

        return $account;
    }
}
