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
 * Follow Outlook categories, on or off, for one Microsoft account (#221 step 4).
 *
 * Beside the push control on the account's row, with the same shape: a button
 * in its own frame that answers with itself. Off by default (CONTRIBUTING: a
 * setting that changes behaviour starts off).
 *
 * Turning it on also hands every folder of the account back to the import,
 * the way Version20261014110000 did for read state: a folder is followed by a
 * delta link that carries the $select it began with, and the links stored so
 * far began without `categories`, so Exchange would not report a category
 * change on them at all. The import lists each folder again — what is stored
 * is recognised, not fetched — and ends with a link that asks for categories.
 * Turning it off leaves the links alone: hearing categories costs nothing, and
 * the syncer ignores them while the switch is off.
 */
#[IsGranted('ROLE_USER')]
final class AccountCategoriesController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/settings/accounts/{id}/categories', name: 'settings_account_categories_toggle', methods: ['POST'])]
    public function toggle(Request $request, Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        if (false === $account->isMicrosoft()) {
            throw $this->createNotFoundException('Only a Microsoft account has Outlook categories.');
        }

        $this->assertCsrf($request, 'account_categories_' . $account->id);

        $account->followsOutlookCategories = false === $account->followsOutlookCategories;

        if (true === $account->followsOutlookCategories) {
            $account->graphDeltaLinks = [];
        }

        $this->em->flush();

        return $this->render('settings/accounts/_categories_control.html.twig', ['account' => $account]);
    }
}
