<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Mail\Account;
use App\Form\AccountType;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\AccountCreator;
use App\Service\Mail\AliasSeeder;
use App\Service\Mail\GmailApiClient;
use App\Service\Push\PushSubscriptionRegistry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;
use Throwable;
use App\Domain\DTO\ConnectionTestResult;
use App\Service\Mail\ConnectionTester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use App\Service\Graph\GraphSubscriptionManager;

#[Route('/account', name: 'app_account_')]
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository      $accountRepository,
        private readonly LoggerInterface        $logger,
        private readonly GraphSubscriptionManager $graphSubscriptionManager,
        private readonly PushSubscriptionRegistry $subscriptionRegistry,
        private readonly AliasSeeder $aliasSeeder,
        private readonly AccountCreator $accountCreator,
    ) {
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request): Response
    {
        $account = $this->accountCreator->blank();

        $form = $this->createForm(AccountType::class, $account, ['action' => $this->generateUrl('app_account_new')]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->accountCreator->create($account, $this->getUser());

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
        if ('password' !== $account->authType) {
            throw $this->createAccessDeniedException();
        }

        $existingPassword = $account->password;

        $form = $this->createForm(AccountType::class, $account, [
            'action'           => $this->generateUrl('app_account_edit', ['id' => $account->id]),
            'require_password' => false,
        ]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $submittedPassword = $form->get('password')->getData();

            // Blank password field means "keep the stored one".
            if (null === $submittedPassword || '' === $submittedPassword) {
                $account->password = $existingPassword;
            }

            $this->entityManager->flush();

            return $this->streamAccountList($request, 'account.updated');
        }

        return $this->render('account/_edit_modal.html.twig', [
            'form'    => $form,
            'account' => $account,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request, Account $account): Response
    {
        $this->denyUnlessOwner($account);

        if (false === $this->isCsrfTokenValid('toggle' . $account->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $newActive = false === $account->isActive;

        $account->isActive = $newActive;
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

        if (false === $this->isCsrfTokenValid('delete' . $account->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Best-effort: stop any Gmail push watch so we don't leave a dangling
        // registration pointing at an account that no longer exists.
        $manager = $this->subscriptionRegistry->resolve($account);

        if (null !== $manager) {
            $manager->unsubscribe($account);
        }

        $this->entityManager->remove($account);
        $this->entityManager->flush();

        $this->resequence($this->accountRepository->findForUserOrdered($this->getUser()));
        $this->entityManager->flush();

        return $this->streamAccountList($request, 'account.removed');
    }
    #[Route('/{id}/reorder', name: 'reorder', methods: ['PATCH'])]
    public function reorder(Request $request, Account $account): Response
    {
        $this->denyUnlessOwner($account);

        // No CSRF token here: @stimulus-components/sortable owns the request body
        // (it only sends account[position]). The action is authenticated,
        // same-origin, and non-destructive. Say the word if you want parity with
        // toggle/delete and I'll wire an X-CSRF-Token header + meta tag.
        $position = (int) ($request->getPayload()->all('account')['position'] ?? 0);

        $ordered = $this->accountRepository->findForUserOrdered($this->getUser());

        // Pull the dragged account out, re-insert at the target index.
        $ordered = array_values(array_filter($ordered, static function (Account $candidate) use ($account): bool {
            return $candidate->id !== $account->id;
        }));

        $targetIndex = max(0, min(count($ordered), $position - 1));
        array_splice($ordered, $targetIndex, 0, [$account]);

        $this->resequence($ordered);
        $this->entityManager->flush();

        return $this->render('account/_reorder.stream.html.twig', [
            'manageableAccounts' => $this->accountRepository->findForUserOrdered($this->getUser()),
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    #[Route('/test-connection', name: 'test_connection', methods: ['POST'])]
    public function testConnection(Request $request, ConnectionTester $tester): JsonResponse
    {
        if (false === $this->isCsrfTokenValid('account_test', (string) $request->headers->get('X-CSRF-Token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $payload = json_decode($request->getContent(), true);

        if (false === is_array($payload)) {
            throw new BadRequestHttpException('Malformed payload.');
        }

        $account = new Account();
        $account->authType = 'password';
        $account->username = (string) ($payload['username'] ?? '');
        $account->password = (string) ($payload['password'] ?? '');
        $account->imapHost = (string) ($payload['imapHost'] ?? '');
        $account->imapPort = (int) ($payload['imapPort'] ?? 993);
        $account->imapEncryption = (string) ($payload['imapEncryption'] ?? 'ssl');
        $account->smtpHost = (string) ($payload['smtpHost'] ?? '');
        $account->smtpPort = (int) ($payload['smtpPort'] ?? 587);
        $account->smtpEncryption = (string) ($payload['smtpEncryption'] ?? 'starttls');

        // Blank password on the edit form means "keep the stored one".
        if ('' === $account->password && null !== ($payload['accountId'] ?? null)) {
            $existing = $this->accountRepository->find((int) $payload['accountId']);

            if (null === $existing) {
                throw $this->createNotFoundException();
            }

            $this->denyUnlessOwner($existing);
            $account->password = $existing->password;
        }

        if ('' === $account->username || '' === $account->imapHost) {
            return $this->json(new ConnectionTestResult(
                false,
                'Enter at least an email address and an IMAP host first.',
                '',
                null,
                '',
                '',
            )->toArray());
        }

        if ('' === $account->password) {
            return $this->json(new ConnectionTestResult(
                false,
                'No password available to test with. Enter one above — on the edit form a blank field means "keep the stored password", which the tester can only resolve once the account id reaches it.',
                '',
                null,
                '',
                '',
            )->toArray());
        }

        return $this->json($tester->test($account)->toArray());
    }

    /**
     * Writes sequential sortOrder and re-derives the single primary (first row).
     * Caller is responsible for flushing.
     *
     * @param Account[] $orderedAccounts
     */
    /**
     * @param iterable<Account> $orderedAccounts
     */
    private function resequence(iterable $orderedAccounts): void
    {
        $this->accountCreator->resequence($orderedAccounts);
    }

    private function denyUnlessOwner(Account $account): void
    {
        if ($account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function streamAccountList(Request $request, string $toastMessage): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        // The list this streams back replaces the one on screen, so it has to
        // come back in the arrangement the user made — re-sorting it by name
        // here would silently undo a drag on the next toggle or delete.
        return $this->render('account/_mutation.stream.html.twig', [
            'toastMessage'       => $toastMessage,
            'manageableAccounts' => $this->accountRepository->findForUserOrdered($this->getUser()),
        ]);
    }
}
