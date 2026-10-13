<?php

declare(strict_types=1);

namespace App\Controller\Drive;

use App\Entity\Drive\DriveShare;
use App\Entity\Drive\DriveShareReport;
use App\Repository\Drive\DriveShareReportRepository;
use App\Repository\Drive\DriveShareRepository;
use App\Service\Appearance\PublicAppearanceResolver;
use App\Service\Drive\DrivePolicy;
use App\Service\Drive\DriveReportNotifier;
use App\Service\Drive\DriveStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public Drive share links: /s/<token>.
 *
 * No login: the recipient has no account here, which is why security.yaml
 * lists ^/s/ as PUBLIC_ACCESS (it was missing, so every link bounced to the
 * login page). The 64-hex-char token is the gate; an optional password is a
 * second one, and password attempts are rate-limited per address and link.
 *
 * GET shows a small landing page (what the file is, how big, a Download
 * button) in the owner's theme, through sharing/_layout — which starts no
 * session, so a visitor gets no cookie. A link without a password can also be
 * fetched directly at /s/<token>/download, which is what goes into an email.
 */
final class ShareController extends AbstractController
{
    public function __construct(
        private readonly DriveShareRepository $shares,
        private readonly DriveStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly PublicAppearanceResolver $appearance,
        // Bound by name to the `drive_share_password` limiter.
        private readonly RateLimiterFactoryInterface $driveSharePasswordLimiter,
        // Reports from recipients, and owners whose Drive is off.
        private readonly RateLimiterFactoryInterface $driveShareReportLimiter,
        private readonly DrivePolicy $policy,
        private readonly DriveShareReportRepository $reports,
        private readonly DriveReportNotifier $notifier,
    ) {}

    #[Route('/s/{token<[a-f0-9]{64}>}', name: 'app_share_access', methods: ['GET', 'POST'])]
    public function access(Request $request, string $token): Response
    {
        $share = $this->liveShare($token);
        $error = null;

        if ($request->isMethod('POST')) {
            $limiter = $this->driveSharePasswordLimiter->create($request->getClientIp() . '|' . $share->id);

            if (false === $limiter->consume()->isAccepted()) {
                $error = 'drive.public.too_many';
            } elseif ($share->checkPassword((string) $request->request->get('password', ''))) {
                $limiter->reset();

                return $this->send($share);
            } else {
                $error = 'drive.wrong_password';
            }
        }

        return $this->render('drive/share_public.html.twig', [
            'share'      => $share,
            'file'       => $share->file,
            'error'      => $error,
            'appearance' => $this->appearance->forOwner($share->file->usr),
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/s/{token<[a-f0-9]{64}>}/download', name: 'app_share_download', methods: ['GET'])]
    public function download(string $token): Response
    {
        $share = $this->liveShare($token);

        if (null !== $share->passwordHash) {
            return $this->redirectToRoute('app_share_access', ['token' => $token]);
        }

        return $this->send($share);
    }

    /**
     * Anyone who was sent the link can flag it to the admin.
     * Rate-limited per address; a hidden field catches simple bots.
     */
    #[Route('/s/{token<[a-f0-9]{64}>}/report', name: 'app_share_report', methods: ['GET', 'POST'])]
    public function report(Request $request, string $token): Response
    {
        $share = $this->liveShare($token);
        $error = null;

        if ($request->isMethod('POST')) {
            $ip = $request->getClientIp();

            if ('' !== (string) $request->request->get('website', '')) {
                // A bot filled the hidden field; tell it it worked.
                return $this->redirectToRoute('app_share_report', ['token' => $token, 'sent' => 1], Response::HTTP_SEE_OTHER);
            }

            if ($this->reports->hasRecent((int) $share->id, $ip, new \DateTimeImmutable('-24 hours'))) {
                // The same person pressing Send again: one report is enough.
                return $this->redirectToRoute('app_share_report', ['token' => $token, 'sent' => 1], Response::HTTP_SEE_OTHER);
            }

            if (false === $this->driveShareReportLimiter->create((string) $ip)->consume()->isAccepted()) {
                $error = 'drive.report.too_many';
            } else {
                $email = trim((string) $request->request->get('email', ''));
                if ('' !== $email && false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $email = '';
                }
                $report = DriveShareReport::file(
                    $share,
                    (string) $request->request->get('reason', 'other'),
                    (string) $request->request->get('details', ''),
                    $email,
                    $ip,
                );
                $this->em->persist($report);
                $this->em->flush();

                $this->notifier->reportFiled($report);

                // Post/Redirect/Get: Turbo only follows a form POST that
                // redirects, which is why the thank-you never showed before.
                return $this->redirectToRoute('app_share_report', ['token' => $token, 'sent' => 1], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('drive/share_report.html.twig', [
            'share'      => $share,
            'file'       => $share->file,
            'sent'       => $request->query->getBoolean('sent'),
            'error'      => $error,
            'reasons'    => DriveShareReport::REASONS,
            'appearance' => $this->appearance->forOwner($share->file->usr),
        ], new Response(status: null !== $error ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_OK));
    }

    private function liveShare(string $token): DriveShare
    {
        $share = $this->shares->findByToken($token);

        // A link whose owner has had Drive switched off is dead too.
        if (null === $share || $share->isExpired() || false === $this->storage->exists($share->file)
            || $this->policy->isDisabled($share->file->usr)) {
            throw $this->createNotFoundException();
        }

        return $share;
    }

    private function send(DriveShare $share): Response
    {
        ++$share->downloadCount;
        $this->em->flush();

        $file = $share->file;
        $response = new BinaryFileResponse($this->storage->path($file));
        $response->headers->set('Content-Type', $file->mimeType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->filename,
            str_replace(['/', '\\', '%'], '_', preg_replace('/[^\x20-\x7e]/', '_', $file->filename) ?? 'download'),
        );

        return $response;
    }
}
