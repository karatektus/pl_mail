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
 *   - change an existing user's password (console only, `app:user:password`;
 *     see UserFormType for the argument),
 *   - remove anyone's second factor (console only, `app:user:2fa-disable`),
 *   - read anyone's mail; nothing here touches Account or Message at all.
 *
 * Removal is a soft delete. The rows that hang off a user — accounts, messages,
 * labels, app passwords — are the user's mail, and a cascade from a misclick in
 * an admin panel is not a recoverable mistake. `deletedAt` takes the account out
 * of every query that matters (UserRepository scopes on it) and leaves the data
 * where an administrator with database access can still get at it.
 *
 * Suspending is the smaller answer beside it, and the one this panel had no way
 * to give: removal frees the address and overwrites the display name, so "stop
 * this person signing in until they are back" used to cost a decision that
 * cannot be taken back. `deactivatedAt` writes one column and nothing else —
 * see User::$deactivatedAt, and App\Security\UserChecker for what enforces it.
 *
 * RESTORING A REMOVED USER IS NOT OFFERED, and the reason is in the delete
 * action below rather than in a missing button. Removal deliberately overwrites
 * the address, the name and the hash in order to free the address for reuse;
 * there is nothing left to restore the account to, and the address it had may
 * by then belong to somebody else. See docs/features/admin.md, which says so on
 * the screen an administrator would look for the button on.
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
            if (true === $isNew) {
                $user->password = $this->passwordHasher->hashPassword(
                    $user,
                    (string) $form->get('plainPassword')->getData(),
                );
            }

            $refusal = $this->applyAdminRole($user, true === $form->get('isAdmin')->getData());

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // The rest of the form is saved either way, which is why the
            // refusal is a toast and not a rejected submission: an admin who
            // renamed somebody AND unticked the box in one go should get the
            // rename, and be told the one thing that did not happen.
            return $this->listResponse($refusal);
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

        $this->entityManager->flush();

        return $this->listResponse();
    }

    /**
     * Suspend an account, or let it back in. One route, because it is one
     * decision with two directions and a separate "reactivate" endpoint would
     * be a second copy of every guard below.
     *
     * Nothing is written except the timestamp. The accounts, the mail, the
     * labels, the app passwords and the second factor all stay exactly as they
     * were, so coming back is this same button — which is the whole point of
     * the state existing beside removal.
     */
    #[Route('/{id}/active', name: 'toggle_active', methods: ['POST'])]
    public function toggleActive(Request $request, User $user): Response
    {
        if (false === $this->isCsrfTokenValid('admin-user-active-' . $user->id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $reactivating = $user->isDeactivated;

        // Only the suspending direction is guarded. Letting somebody back in
        // cannot lock anybody out of anything, so the two refusals below would
        // be refusing a repair — including the one an administrator would reach
        // for after suspending the wrong person.
        if (false === $reactivating) {
            $this->assertSuspendable($user);
        }

        $user->deactivatedAt = true === $reactivating ? null : new DateTimeImmutable();

        $this->entityManager->flush();

        return $this->listResponse(
            true === $reactivating ? 'admin.users.flash.reactivated' : 'admin.users.flash.deactivated',
            'success',
        );
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
     * The two suspensions that would take the admin area away with them.
     *
     * The same pair assertRemovable() refuses, and for the same reasons: an
     * account that cannot sign in is as absent from the admin area as one that
     * has been removed, so suspending yourself or the last administrator leaves
     * the panel with nobody who can undo it — and unlike removal, there is not
     * even a console command that repairs this one.
     */
    private function assertSuspendable(User $user): void
    {
        if ($user === $this->getUser()) {
            throw $this->createAccessDeniedException('An administrator cannot switch off their own account.');
        }

        if (true === $user->isDeleted()) {
            throw $this->createNotFoundException();
        }

        if (true === in_array(User::ROLE_ADMIN, $user->getRoles(), true) && 1 >= $this->adminCount()) {
            throw $this->createAccessDeniedException('The last administrator cannot be switched off.');
        }
    }

    /**
     * Apply the "administrator" checkbox, and say so when it was refused.
     *
     * An administrator demoting themselves is refused for the same reason as
     * deleting themselves: it is one click from having nobody who can undo it.
     *
     * **The return value is the whole point of this method's shape.** It used
     * to return void and simply not act, so a refused demotion looked exactly
     * like a successful one — the modal closed, the list redrew, and the tick
     * was back the next time anyone opened the form. A refusal nobody is told
     * about is indistinguishable from a bug, and the obvious next move is to
     * report the checkbox as broken.
     *
     * @return string|null a message key naming the refusal, or null when the
     *                     checkbox was honoured
     */
    private function applyAdminRole(User $user, bool $shouldBeAdmin): ?string
    {
        $isAdmin = in_array(User::ROLE_ADMIN, $user->getRoles(), true);

        if ($isAdmin === $shouldBeAdmin) {
            return null;
        }

        if (false === $shouldBeAdmin && true === ($user === $this->getUser())) {
            return 'admin.users.flash.demote_self_refused';
        }

        // Separated from the clause above rather than sharing an `||`: they are
        // two different refusals and the admin needs to know which one they hit.
        // Somebody demoting a colleague and being told "you cannot demote
        // yourself" would read it as the panel confusing two accounts.
        if (false === $shouldBeAdmin && 1 >= $this->adminCount()) {
            return 'admin.users.flash.demote_last_refused';
        }

        true === $shouldBeAdmin
            ? $user->addRole(User::ROLE_ADMIN)
            : $user->removeRole(User::ROLE_ADMIN);

        return null;
    }

    private function adminCount(): int
    {
        return $this->userRepository->countAdmins();
    }

    /**
     * The user list as a Turbo Stream, optionally with something to say.
     *
     * One copy, because create, edit, remove and suspend all end the same way:
     * the whole frame is replaced (see _saved.stream.html.twig for why it is
     * the frame rather than one row) and the modal, if there was one, closes.
     *
     * The list is deliberately rebuilt UNFILTERED and on page one. The stream
     * is the reply to a modal submit, which carries no search term and no page
     * — rebuilding it from a term this request does not have would mean
     * inventing one, and an administrator who has just added somebody wants to
     * see them.
     */
    private function listResponse(?string $toastMessage = null, string $toastType = 'error'): Response
    {
        return $this->render('admin/user/_saved.stream.html.twig', [
            'pagination'   => $this->paginator->paginate(
                $this->userRepository->createSearchQueryBuilder(null),
                1,
                self::PER_PAGE,
            ),
            'table_search' => null,
            'adminCount'   => $this->adminCount(),
            'toastMessage' => $toastMessage,
            'toastType'    => $toastType,
        ], new Response(null, Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }
}
