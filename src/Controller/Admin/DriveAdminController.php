<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Entity\Drive\DriveFile;
use App\Entity\Drive\DriveShareReport;
use App\Entity\User\User;
use App\Repository\Drive\DriveShareReportRepository;
use App\Service\Drive\DrivePolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Drive.
 *
 *  - The default storage quota and the largest single file.
 *  - Who is using how much, with their own quota and whether Drive is off.
 *  - Links people have reported, with the three things to do about one:
 *    revoke the link, switch off the owner's Drive, or dismiss the report.
 *
 * Per-user quota and the on/off switch are also on each user's edit form.
 */
#[Route('/admin/drive', name: 'app_admin_drive_')]
#[IsGranted('ROLE_ADMIN')]
final class DriveAdminController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly DrivePolicy $policy,
        private readonly DriveShareReportRepository $reports,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderFrame($request->query->getString('saved'));
    }

    #[Route('/settings', name: 'settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_drive_settings');

        $this->policy->saveInstanceSettings(
            max(0, $request->request->getInt('defaultQuotaMb')),
            max(1, $request->request->getInt('maxFileMb', 1)),
        );

        return $this->renderFrame('settings');
    }

    #[Route('/user/{id<\d+>}', name: 'user', methods: ['POST'])]
    public function user(Request $request, User $user): Response
    {
        $this->assertCsrf($request, 'admin_drive_user_' . $user->id);

        $quota = trim((string) $request->request->get('quotaMb', ''));
        $this->policy->applyToUser(
            $user,
            '' === $quota ? null : max(0, (int) $quota),
            $request->request->getBoolean('disabled'),
        );
        $this->em->flush();

        return $this->renderFrame('user');
    }

    #[Route('/report/{id<\d+>}', name: 'report', methods: ['POST'])]
    public function report(Request $request, DriveShareReport $report): Response
    {
        $this->assertCsrf($request, 'admin_drive_report_' . $report->id);

        $action = (string) $request->request->get('action');

        switch ($action) {
            case 'revoke':
                // Every open report about the same link is settled with it.
                $share = $report->share;
                if (null !== $share) {
                    foreach ($this->reports->findOpenForShare((int) $share->id) as $sameLink) {
                        $sameLink->resolve('revoked');
                    }
                    $this->em->remove($share);
                }
                $report->resolve('revoked');
                break;

            case 'disable':
                $owner = null === $report->ownerId ? null : $this->em->find(User::class, $report->ownerId);
                if (null !== $owner) {
                    $this->policy->applyToUser($owner, $this->policy->quotaOverrideMb($owner), true);
                    foreach ($this->reports->findOpenForOwner((int) $owner->id) as $sameOwner) {
                        $sameOwner->resolve('disabled');
                    }
                }
                $report->resolve('disabled');
                break;

            default:
                $report->resolve('dismissed');
        }

        $this->em->flush();

        return $this->renderFrame('report');
    }

    private function renderFrame(string $saved = ''): Response
    {
        /** @var list<array{id:int, used:string|int, files:string|int}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(f.usr) AS id', 'SUM(f.sizeBytes) AS used', 'COUNT(f.id) AS files')
            ->from(DriveFile::class, 'f')
            ->groupBy('f.usr')
            ->orderBy('used', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getArrayResult();

        $usage = [];
        foreach ($rows as $row) {
            $usage[(int) $row['id']] = ['used' => (int) $row['used'], 'files' => (int) $row['files']];
        }

        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findBy([], ['id' => 'ASC'], 500);

        $people = [];
        foreach ($users as $user) {
            if (true === $user->isDeleted()) {
                continue;
            }
            $people[] = [
                'user'     => $user,
                'used'     => $usage[(int) $user->id]['used'] ?? 0,
                'files'    => $usage[(int) $user->id]['files'] ?? 0,
                'override' => $this->policy->quotaOverrideMb($user),
                'quota'    => $this->policy->quotaBytes($user),
                'disabled' => $this->policy->isDisabled($user),
            ];
        }
        usort($people, static fn (array $a, array $b): int => $b['used'] <=> $a['used']);

        $owners = [];
        $open = $this->reports->findOpen();
        foreach ($open as $report) {
            if (null !== $report->ownerId && !isset($owners[$report->ownerId])) {
                $owners[$report->ownerId] = $this->em->find(User::class, $report->ownerId);
            }
        }

        return $this->render('admin/drive/_frame.html.twig', [
            'settings'    => $this->policy->instanceSettings(),
            'phpLimit'    => $this->policy->phpLimitBytes(),
            'people'      => $people,
            'reports'     => $open,
            'owners'      => $owners,
            'saved'       => $saved,
        ]);
    }
}
