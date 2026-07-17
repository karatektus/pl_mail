<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Message;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class MailBodyExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('resolve_cid', [$this, 'resolveCid']),
        ];
    }

    /**
     * Rewrite `cid:` references in an email body to point at our attachment
     * route, so inline images load lazily through AttachmentResolver.
     */
    public function resolveCid(?string $html, Message $message): string
    {
        if (null === $html || '' === $html) {
            return (string) $html;
        }

        $map = [];
        foreach ($message->getMessageParts() as $part) {
            $cid = $part->getContentId();
            if (null === $cid || '' === $cid) {
                continue;
            }

            $map[strtolower($cid)] = $this->urlGenerator->generate(
                'app_mail_attachment',
                ['id' => $part->getId()],
            );
        }

        if (count($map) === 0) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/(["\'])cid:([^"\']+)\1/i',
            static function (array $m) use ($map): string {
                $quote = $m[1];
                $cid   = strtolower(trim($m[2]));

                return $quote . ($map[$cid] ?? ('cid:' . $m[2])) . $quote;
            },
            $html,
        );
    }
}
