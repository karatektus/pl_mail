<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Account;
use App\Form\AccountType;
use App\Repository\AccountRepository;
use App\Service\Mail\GmailApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;
use Throwable;

#[Route('/account', name: 'app_account_')]
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository      $accountRepository,
        private readonly GmailApiClient         $gmailApiClient,
        private readonly LoggerInterface        $logger,
    ) {
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request): Response
    {
        $account = new Account();
        $form = $this->createForm(AccountType::class, $account, ['action' => $this->generateUrl('app_account_new')]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $account
                ->setAuthType('password')
                ->setIsActive(true)
                ->setUsr($this->getUser());

            $this->entityManager->persist($account);
            $this->entityManager->flush();

            return $this->streamAccountList($request, 'account.added');
        }

        return $this->render('account/_form_modal.html.twig', [
            'title' => 'New Account',
            'form'  => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, Account $account): Response
    {
        $this->denyUnlessOwner($account);

        // OAuth accounts have no editable password/host — they are managed
        // through connect/disconnect, not this form.
        if ('password' !== $account->getAuthType()) {
            throw $this->createAccessDeniedException();
        }

        $existingPassword = $account->getPassword();

        $form = $this->createForm(AccountType::class, $account, [
            'action'           => $this->generateUrl('app_account_edit', ['id' => $account->getId()]),
            'require_password' => false,
        ]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $submittedPassword = $form->get('password')->getData();

            // Blank password field means "keep the stored one".
            if (null === $submittedPassword || '' === $submittedPassword) {
                $account->setPassword($existingPassword);
            }

            $account->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return $this->streamAccountList($request, 'account.updated');
        }

        return $this->render('account/_edit_modal.html.twig', [
            'account' => $account,
            'form'    => $form,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request, Account $account): Response
    {
        $this->denyUnlessOwner($account);

        if (false === $this->isCsrfTokenValid('toggle' . $account->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $newActive = false === $account->isActive();

        $account
            ->setIsActive($newActive)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        if (true === $newActive) {
            $toastMessage = 'account.enabled';
        } else {
            $toastMessage = 'account.disabled';
        }

        return $this->streamAccountList($request, $toastMessage);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Account $account): Response
    {
        $this->denyUnlessOwner($account);

        if (false === $this->isCsrfTokenValid('delete' . $account->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Best-effort: stop any Gmail push watch so we don't leave a dangling
        // registration pointing at an account that no longer exists.
        if (null !== $account->getGmailWatchResourceName()) {
            try {
                $this->gmailApiClient->stopWatch($account);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to stop Gmail watch during account delete', [
                    'accountId' => $account->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->remove($account);
        $this->entityManager->flush();

        return $this->streamAccountList($request, 'account.removed');
    }

    private function denyUnlessOwner(Account $account): void
    {
        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function streamAccountList(Request $request, string $toastMessage): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('account/_mutation.stream.html.twig', [
            'toastMessage'       => $toastMessage,
            'manageableAccounts' => $this->accountRepository->findForUserOrderedByName($this->getUser()),
        ]);
    }
}
