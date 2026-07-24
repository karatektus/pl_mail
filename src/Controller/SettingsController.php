<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AccountRepository;
use App\Repository\LabelRepository;
use App\Service\Push\PushSubscriptionRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings', name: 'app_settings_')]
#[IsGranted('ROLE_USER')]
final class SettingsController extends AbstractController
{
    private const array SECTIONS = ['accounts', 'labels', 'appearance'];

    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly LabelRepository   $labelRepository,
        private readonly PushSubscriptionRegistry $pushSubscriptionRegistry,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $section = (string) $request->query->get('section', 'accounts');

        if (false === in_array($section, self::SECTIONS, true)) {
            $section = 'accounts';
        }

        $manageableAccounts = $this->accountRepository->findForUserOrderedByName($this->getUser());

        $labelsByAccount = [];

        foreach ($manageableAccounts as $account) {
            $labelsByAccount[$account->getId()] = $this->labelRepository->findForAccountTreeOrdered($account);
        }

        return $this->render('settings/index.html.twig', [
            'section'            => $section,
            'manageableAccounts' => $manageableAccounts,
            'labelsByAccount'    => $labelsByAccount,
            'isConfigured'       => 'false',
        ]);
    }
}
