<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Entity\User\User;
use App\Repository\Job\BackgroundJobRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The topbar's view of this user's background work.
 *
 * A frame rather than a stream, because the thing it renders is a small piece
 * of state that several events want redrawn — a job starting, a chunk landing,
 * a job finishing. One URL that always answers with the current picture is
 * simpler than four publishers each having to compose the same markup.
 *
 * Scoped to the signed-in user by the query, not by a filter afterwards: a
 * background job belongs to whoever started it, and there is no view of anyone
 * else's.
 */
final class JobsController extends AbstractController
{
    #[Route('/mail/jobs/indicator', name: 'app_jobs_indicator', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function indicator(#[CurrentUser] User $user, BackgroundJobRepository $jobs): Response
    {
        return $this->render('mail/_jobs_indicator.html.twig', [
            'jobs' => $jobs->findVisibleForUser($user),
        ]);
    }
}
