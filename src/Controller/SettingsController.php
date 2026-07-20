<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AccountRepository;
use App\Repository\LabelRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings', name: 'app_settings_')]
#[IsGranted('ROLE_USER')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly LabelRepository   $labelRepository,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $manageableAccounts = $this->accountRepository->findForUserOrderedByName($this->getUser());

        $labelsByAccount = [];

        foreach ($manageableAccounts as $account) {
            $labelsByAccount[(int) $account->getId()] = $this->labelRepository->findForAccountTreeOrdered($account);
        }

        return $this->render('settings/index.html.twig', [
            'manageableAccounts' => $manageableAccounts,
            'labelsByAccount'    => $labelsByAccount,
        ]);
    }
}
