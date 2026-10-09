<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\Demo\DemoVisitRepository;
use App\Repository\User\UserRepository;
use App\Service\Demo\DemoMode;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Demo visitors: how many sessions a public demo has handed out, and to
 * roughly how many different people.
 *
 * Demo mode only, and a 404 without it rather than an empty table, for the
 * reason DemoController gives: on a normal install nothing about the demo
 * should look as though it had been routed.
 *
 * Read-only, and rendered into its own Turbo Frame like the other sections.
 */
#[Route('/admin/demo-stats', name: 'app_admin_demo_stats_')]
#[IsGranted('ROLE_ADMIN')]
final class DemoStatsController extends AbstractController
{
    /**
     * The windows, in the order the table shows them. A month is thirty days:
     * it is also how long a visit keeps its visitor hash, so the longest window
     * is exactly as far back as unique visitors can be counted.
     */
    private const array WINDOWS = [
        'hour'  => '-1 hour',
        'day'   => '-1 day',
        'week'  => '-7 days',
        'month' => '-'.DemoVisitRepository::HASH_RETENTION_DAYS.' days',
    ];

    public function __construct(
        private readonly DemoMode            $demoMode,
        private readonly DemoVisitRepository $visits,
        private readonly UserRepository      $users,
    ) {
    }

    #[Route('', name: 'panel', methods: ['GET'])]
    public function panel(): Response
    {
        if (false === $this->demoMode->isEnabled()) {
            throw new NotFoundHttpException();
        }

        $now     = new DateTimeImmutable();
        $windows = [];

        foreach (self::WINDOWS as $name => $since) {
            $windows[$name] = $this->visits->countSince($now->modify($since));
        }

        return $this->render('admin/demo/_frame.html.twig', [
            'windows'       => $windows,
            'total'         => $this->visits->countAll(),
            // Visitors whose two hours are not up yet: the users the reaper
            // has not taken, which is everybody who could be looking right now.
            'active'        => $this->users->countDemoVisitors(),
            'retentionDays' => DemoVisitRepository::HASH_RETENTION_DAYS,
        ]);
    }
}
