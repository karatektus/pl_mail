<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Service\Drive\DrivePolicy;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Presentation helpers for Drive: a human file size, and an icon + tint per
 * kind of file, so the list template is markup rather than an if-ladder.
 */
final class DriveExtension extends AbstractExtension
{
    public function __construct(
        private readonly ?DrivePolicy $policy = null,
        private readonly ?Security $security = null,
    ) {
    }

    private const array KINDS = [
        'image'   => ['fa-file-image', 'text-sky-500', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'heic', 'bmp', 'avif']],
        'pdf'     => ['fa-file-pdf', 'text-red-500', ['pdf']],
        'doc'     => ['fa-file-word', 'text-blue-600', ['doc', 'docx', 'odt', 'rtf', 'pages']],
        'text'    => ['fa-file-lines', 'text-ink-muted', ['txt', 'md', 'log']],
        'sheet'   => ['fa-file-excel', 'text-emerald-600', ['xls', 'xlsx', 'ods', 'csv', 'numbers']],
        'slides'  => ['fa-file-powerpoint', 'text-orange-500', ['ppt', 'pptx', 'odp', 'key']],
        'archive' => ['fa-file-zipper', 'text-amber-600', ['zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'bz2', 'xz']],
        'video'   => ['fa-file-video', 'text-violet-500', ['mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v']],
        'audio'   => ['fa-file-audio', 'text-pink-500', ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'opus']],
        'code'    => ['fa-file-code', 'text-teal-600', ['json', 'xml', 'html', 'css', 'js', 'ts', 'php', 'py', 'sh', 'yml', 'yaml']],
    ];

    public function getFilters(): array
    {
        return [new TwigFilter('drive_size', $this->size(...))];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('drive_icon', $this->icon(...)),
            // False for a user whose Drive an admin switched off.
            new TwigFunction('drive_enabled', $this->enabled(...)),
        ];
    }

    public function enabled(): bool
    {
        $user = $this->security?->getUser();

        return !($user instanceof User && true === $this->policy?->isDisabled($user));
    }

    public function size(int|float|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < \count($units) - 1) {
            $bytes /= 1024;
            ++$i;
        }

        return 0 === $i
            ? sprintf('%d %s', (int) $bytes, $units[$i])
            : sprintf($bytes < 10 ? '%.1f %s' : '%.0f %s', $bytes, $units[$i]);
    }

    /**
     * @return array{icon: string, tint: string}
     */
    public function icon(string $filename): array
    {
        $dot = strrpos($filename, '.');
        $ext = false === $dot ? '' : strtolower(substr($filename, $dot + 1));

        foreach (self::KINDS as [$icon, $tint, $exts]) {
            if (\in_array($ext, $exts, true)) {
                return ['icon' => $icon, 'tint' => $tint];
            }
        }

        return ['icon' => 'fa-file', 'tint' => 'text-ink-faint'];
    }
}
