<?php

namespace App\Controller;

use App\Entity\Account;
use App\Form\AccountType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

#[Route('/account', name: 'app_account_')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    )
    {
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request): Response
    {
        $account = new Account();
        $form = $this->createForm(AccountType::class, $account, ['action' => $this->generateUrl('app_account_new')]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $account
                ->setAuthType('password')
                ->setIsActive(true)
                ->setUsr($this->getUser());
            $this->entityManager->persist($account);
            $this->entityManager->flush();

            if ($request->getPreferredFormat() === TurboBundle::STREAM_FORMAT) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
                return $this->render('account/_form_success.stream.html.twig', [
                    'account' => $account,
                ]);
            }
        }
        return $this->render('account/_form_modal.html.twig', [
            'title' => 'New Account',
            'form' => $form,
        ]);
    }

}
