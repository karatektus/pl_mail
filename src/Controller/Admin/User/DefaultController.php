<?php

declare(strict_types=1);

namespace App\Controller\Admin\User;

use App\Entity\User\User;
use App\Form\Admin\UserFormType;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Adding and removing the people who can sign in.
 *
 * plMail's data model has been multi-user throughout — accounts hang off a
 * user, the Gmail webhook already reasons about two users connecting the same
 * address — but there was no way to create the second one. InstallController
 * makes the first administrator and then InstallGuard 404s it forever;
 * `app:setup` does the same from a terminal and returns early once any user
 * exists. This is the missing half.
 *
 * Three things an administrator deliberately cannot do here, all for the same
 * reason CONTRIBUTING.md gives for keeping 2FA removal out of the web UI — an
 * admin session should not be a second way into every mailbox on the install:
 *
 *   - change an existing user's password (see UserFormType),
 *   - remove anyone's second factor (console only, `app:user:2fa-disable`),
 *   - read anyone's mail; nothing here touches Account or Message at all.
 *
 * Removal is a soft delete. The rows that hang off a user — accounts, messages,
 * labels, app passwords — are the user's mail, and a cascade from a misclick in
 * an admin panel is not a recoverable mistake. `deletedAt` takes the account out
 * of every query that matters (UserRepository scopes on it) and leaves the data
 * where an administrator with database access can still get at it.
 */
#[Route('/admin/users', name: 'app_admin_user_default_')]
#[IsGranted('ROLE_ADMIN')]
final class DefaultController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly EntityManagerInterface      $entityManager,
        private readonly PaginatorInterface          $paginator,
        private readonly UserRepository              $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    /**
     * The user list, as a Turbo Frame in the admin dashboard's "users" section.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->get('search');

        $pagination = $this->paginator->paginate(
            $this->userRepository->createSearchQueryBuilder(
                is_string($search) && '' !== trim($search) ? trim($search) : null,
            ),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render('admin/user/_users_frame.html.twig', [
            'pagination'   => $pagination,
            'table_search' => $search,
            'adminCount'   => $this->adminCount(),
        ]);
    }

    #[Route('/create', name: 'form_create', methods: ['GET', 'POST'])]
    #[Route('/{id}/edit', name: 'form_edit', methods: ['GET', 'POST'])]
    public function form(Request $request, ?User $user = null): Response
    {
        $isNew = null === $user;

        if (true === $isNew) {
            $user = new User();
        }

        if (true === $user->isDeleted()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(UserFormType::class, $user, [
            'is_new' => $isNew,
            'action' => true === $isNew
                ? $this->generateUrl('app_admin_user_default_form_create')
                : $this->generateUrl('app_admin_user_default_form_edit', ['id' => $user->id]),
        ]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $now = new DateTimeImmutable();

            if (true === $isNew) {
                $user->password = $this->passwordHasher->hashPassword(
                    $user,
                    (string) $form->get('plainPassword')->getData(),
                );
                $user->createdAt = $now;
            }

            $this->applyAdminRole($user, true === $form->get('isAdmin')->getData());

            $user->updatedAt = $now;

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $this->render('admin/user/_saved.stream.html.twig', [
                'pagination'   => $this->paginator->paginate(
                    $this->userRepository->createSearchQueryBuilder(null),
                    1,
                    self::PER_PAGE,
                ),
                'table_search' => null,
                'adminCount'   => $this->adminCount(),
            ], new Response(null, Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html']));
        }

        return $this->render('admin/user/_form.html.twig', [
            'user'  => $user,
            'isNew' => $isNew,
            'form'  => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, User $user): Response
    {
        if (false === $this->isCsrfTokenValid('admin-user-delete-' . $user->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->assertRemovable($user);

        $now = new DateTimeImmutable();

        // The address and the name are freed rather than kept. email is unique,
        // so leaving it in place would stop the same person ever being added
        // back — and a deleted user's real name has no reason to sit in a table
        // an administrator browses.
        $tombstone = sprintf('deleted-%d@invalid', $user->id);

        $user->email = $tombstone;
        $user->nameFirst = 'Deleted';
        $user->nameLast = sprintf('User %d', $user->id);
        // Not null: the column is not nullable, and an empty string would be a
        // hash no password can produce — which is the intent, since this is
        // what makes the row unable to authenticate.
        $user->password = '';
        $user->deletedAt = $now;
        $user->updatedAt = $now;

        $this->entityManager->flush();

        return $this->render('admin/user/_saved.stream.html.twig', [
            'pagination'   => $this->paginator->paginate(
                $this->userRepository->createSearchQueryBuilder(null),
                1,
                self::PER_PAGE,
            ),
            'table_search' => null,
            'adminCount'   => $this->adminCount(),
        ], new Response(null, Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Two ways to lock everyone out of the admin area, both refused.
     *
     * Deleting yourself is the obvious one. Deleting the last administrator is
     * the one that looks fine at the time: an admin removes a colleague's
     * account, and nobody notices until the next time someone needs the panel —
     * by which point the only way back in is a console command.
     */
    private function assertRemovable(User $user): void
    {
        if ($user === $this->getUser()) {
            throw $this->createAccessDeniedException('An administrator cannot remove their own account.');
        }

        if (true === $user->isDeleted()) {
            throw $this->createNotFoundException();
        }

        if (true === in_array(User::ROLE_ADMIN, $user->getRoles(), true) && 1 >= $this->adminCount()) {
            throw $this->createAccessDeniedException('The last administrator cannot be removed.');
        }
    }

    /**
     * An administrator demoting themselves is refused for the same reason as
     * deleting themselves: it is one click from having nobody who can undo it.
     */
    private function applyAdminRole(User $user, bool $shouldBeAdmin): void
    {
        $isAdmin = in_array(User::ROLE_ADMIN, $user->getRoles(), true);

        if ($isAdmin === $shouldBeAdmin) {
            return;
        }

        if (false === $shouldBeAdmin
            && (true === ($user === $this->getUser()) || 1 >= $this->adminCount())
        ) {
            return;
        }

        true === $shouldBeAdmin
            ? $user->addRole(User::ROLE_ADMIN)
            : $user->removeRole(User::ROLE_ADMIN);
    }

    private function adminCount(): int
    {
        return $this->userRepository->countAdmins();
    }
}
