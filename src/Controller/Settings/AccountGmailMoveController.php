<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\Mail\Account;
use App\Security\Voter\OwnershipVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Move to offers labels", on or off, for one Gmail account (#221 step 3).
 *
 * Gmail has no folders, so its "Move to" offers the inbox, spam and trash
 * only; with this on it offers the account's labels too, and a move there is
 * Gmail's own (Account::$gmailMoveOffersLabels, LabelKinds). The categories
 * switch's shape: a button in its own frame that answers with itself. Off by
 * default (CONTRIBUTING: a setting that changes behaviour starts off).
 */
#[IsGranted('ROLE_USER')]
final class AccountGmailMoveController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/settings/accounts/{id}/gmail-move', name: 'settings_account_gmail_move_toggle', methods: ['POST'])]
    public function toggle(Request $request, Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        if (false === $account->isGmail()) {
            throw $this->createNotFoundException('Only a Gmail account moves to labels.');
        }

        $this->assertCsrf($request, 'account_gmail_move_' . $account->id);

        $account->gmailMoveOffersLabels = false === $account->gmailMoveOffersLabels;

        $this->em->flush();

        return $this->render('settings/accounts/_gmail_move_control.html.twig', ['account' => $account]);
    }
}
