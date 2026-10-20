<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\Exception\SendDeferredException;
use App\Domain\Exception\SendOutcomeUnknownException;
use App\Domain\Exception\SendRefusedException;
use App\Entity\Mail\Account;
use Symfony\Component\Mime\Email;

/**
 * A sender that says WHY a send did not go through (#125).
 *
 * MailSenderInterface::send() answers true or false, which is all an
 * invitation reply or a calendar alert needs. The user's own send needs more:
 * a rate limit is worth waiting out, a refusal is not, and a lost answer must
 * not be sent again without looking first. MessageSendService asks this
 * method when the sender has it, and send() when it does not (the demo
 * sender, and test doubles), where `false` is read as a refusal, as before.
 */
interface MailDeliveryInterface extends MailSenderInterface
{
    /**
     * Send a fully built MIME email, or throw what kind of failure it was.
     *
     * @throws SendDeferredException       the mail did not leave; a later attempt may work
     * @throws SendRefusedException        the mail did not leave; sending it unchanged will not work
     * @throws SendOutcomeUnknownException the request went out and the answer was lost
     */
    public function deliver(Email $email, Account $account): void;
}
