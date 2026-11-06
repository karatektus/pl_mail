<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Mail\Account;
use App\Form\AccountType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Adding an IMAP account asks where its labels are kept: only in plMail, or on
 * the server too, as IMAP keywords (#221, Adrian). The same switch as in the
 * account's folders, so the form reads and writes Account::$labelsAsKeywords.
 */
final class AccountLabelsAsKeywordsTest extends KernelTestCase
{
    private const array FIELDS = [
        'email'          => 'keywords@example.test',
        'username'       => 'keywords@example.test',
        'password'       => 'secret',
        'imapHost'       => 'imap.example.test',
        'imapPort'       => '993',
        'imapEncryption' => 'ssl',
        'smtpHost'       => 'smtp.example.test',
        'smtpPort'       => '465',
        'smtpEncryption' => 'ssl',
    ];

    public function testANewAccountKeepsLabelsInPlMailUnlessAsked(): void
    {
        $account = new Account();
        $form    = $this->form($account);

        self::assertSame('local', $form->get('labelsAsKeywords')->getData());

        $form->submit(self::FIELDS);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($account->labelsAsKeywords);
    }

    public function testKeywordsChosenWhenTheAccountIsAddedAreSwitchedOn(): void
    {
        $account = new Account();

        $this->form($account)->submit(self::FIELDS + ['labelsAsKeywords' => 'keywords']);

        self::assertTrue($account->labelsAsKeywords);
    }

    /** The edit form opens on what the account has, so saving it changes nothing. */
    public function testTheEditFormKeepsWhatTheFoldersSwitchSet(): void
    {
        $account                   = new Account();
        $account->labelsAsKeywords = true;
        $form                      = $this->form($account, false);

        self::assertSame('keywords', $form->get('labelsAsKeywords')->getData());

        $form->submit(self::FIELDS + ['labelsAsKeywords' => 'keywords']);

        self::assertTrue($account->labelsAsKeywords);
    }

    private function form(Account $account, bool $requirePassword = true): FormInterface
    {
        return static::getContainer()->get(FormFactoryInterface::class)->create(AccountType::class, $account, [
            'csrf_protection'  => false,
            'require_password' => $requirePassword,
        ]);
    }
}
