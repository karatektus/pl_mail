<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Entity\Integration;
use App\Repository\IntegrationProviderConfigRepository;
use App\Service\Integration\IntegrationUrlValidator;
use DateTimeImmutable;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Immich, the self-hosted photo library.
 *
 * A photo library has no directory tree, so the picker's two levels are
 * albums and then the assets inside one. The root listing is the album list;
 * there is no deeper nesting and no cursor, because the album endpoint returns
 * all of its assets in one response.
 *
 * Immich cannot make a public URL for a single asset — sharing is album-level
 * and creating an album as a side effect of attaching a photo would be a far
 * bigger action than the user asked for. So Provider::Immich omits ShareLink,
 * shareLink() here returns null, and the picker offers "attach a copy" only.
 * A photo over the attachment cap therefore cannot be attached at all, which
 * is the honest outcome rather than a link that goes nowhere.
 *
 * Auth is an API key in x-api-key, generated per user in Immich's account
 * settings. Entry ids are Immich's own UUIDs, opaque to everything else.
 */
final readonly class ImmichDriver implements IntegrationDriverInterface
{
    private const string API = '/api';

    /**
     * Immich renders two preview sizes; "thumbnail" is the small square one
     * the picker grid wants.
     */
    private const string THUMBNAIL_SIZE = 'thumbnail';

    public function __construct(
        private HttpClientInterface                 $httpClient,
        private IntegrationUrlValidator             $urlValidator,
        private IntegrationProviderConfigRepository $configRepository,
    ) {
    }

    public function supports(Provider $provider): bool
    {
        return Provider::Immich === $provider;
    }

    public function verify(Integration $integration): void
    {
        // Cheapest endpoint that proves both the address and the key.
        $this->get($integration, '/users/me');
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        if (null === $folderId || '' === $folderId) {
            return new Listing($this->albums($integration), $this->breadcrumb());
        }

        return $this->albumAssets($integration, $folderId);
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $response = $this->request($integration, 'GET', $this->url($integration, '/assets/'.rawurlencode($fileId).'/original'));

        try {
            $contents = $response->getContent();
            $headers = $response->getHeaders();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return new RemoteFile(
            filename: $this->filenameFromHeaders($headers) ?? $fileId,
            mime: trim(explode(';', $headers['content-type'][0] ?? 'application/octet-stream')[0]),
            contents: $contents,
        );
    }

    public function upload(
        Integration $integration,
        string $absolutePath,
        string $filename,
        string $mime,
        ?string $folderId = null,
    ): string {
        if (false === is_readable($absolutePath)) {
            throw new IntegrationException(sprintf('Cannot read %s to upload.', $filename));
        }

        $stat = @stat($absolutePath);
        $modified = (new DateTimeImmutable())->setTimestamp(
            false === $stat ? time() : (int) $stat['mtime'],
        )->format(DATE_ATOM);

        // deviceAssetId is Immich's dedup key: uploading the same file twice
        // under the same id returns the existing asset instead of a duplicate.
        // Keying it on the content hash means re-running a filter over the same
        // mail does not fill the library with copies.
        $deviceAssetId = 'plmail-'.hash_file('sha256', $absolutePath);

        $formFields = [
            'deviceAssetId'   => $deviceAssetId,
            'deviceId'        => 'plmail',
            'fileCreatedAt'   => $modified,
            'fileModifiedAt'  => $modified,
            'assetData'       => new DataPart(new File($absolutePath), $filename, $mime),
        ];

        $form = new FormDataPart($formFields);

        $response = $this->request($integration, 'POST', $this->url($integration, '/assets'), [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body'    => $form->bodyToIterable(),
        ]);

        try {
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        $id = $payload['id'] ?? null;

        if (false === is_string($id) || '' === $id) {
            throw new IntegrationException('Immich accepted the upload but did not return an asset id.');
        }

        if (null !== $folderId && '' !== $folderId) {
            $this->addToAlbum($integration, $folderId, $id);
        }

        return $id;
    }

    /**
     * Always null — see the class docblock. Provider::Immich does not declare
     * ShareLink, so nothing should be calling this.
     */
    public function shareLink(Integration $integration, string $fileId): ?string
    {
        return null;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        $response = $this->request(
            $integration,
            'GET',
            $this->url($integration, '/assets/'.rawurlencode($fileId).'/thumbnail'),
            ['query' => ['size' => self::THUMBNAIL_SIZE]],
            false,
        );

        try {
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $contents = $response->getContent(false);
            $mime = $response->getHeaders(false)['content-type'][0] ?? 'image/jpeg';
        } catch (HttpExceptionInterface) {
            return null;
        }

        if ('' === $contents) {
            return null;
        }

        return new RemoteFile($fileId, trim(explode(';', $mime)[0]), $contents);
    }

    // ── Listings ──────────────────────────────────────────────────────────────

    /** @return list<Entry> */
    private function albums(Integration $integration): array
    {
        $albums = [];

        foreach ($this->get($integration, '/albums') as $album) {
            if (false === is_array($album)) {
                continue;
            }

            $id = (string) ($album['id'] ?? '');

            if ('' === $id) {
                continue;
            }

            $albums[] = new Entry(
                id: $id,
                name: (string) ($album['albumName'] ?? 'Album'),
                isFolder: true,
                size: null,
                mime: null,
                modifiedAt: $this->parseDate($album['updatedAt'] ?? null),
            );
        }

        usort($albums, static fn (Entry $a, Entry $b): int => strnatcasecmp($a->name, $b->name));

        return $albums;
    }

    private function albumAssets(Integration $integration, string $albumId): Listing
    {
        $album = $this->get($integration, '/albums/'.rawurlencode($albumId));
        $assets = $album['assets'] ?? [];
        $entries = [];

        if (true === is_array($assets)) {
            foreach ($assets as $asset) {
                if (false === is_array($asset)) {
                    continue;
                }

                $id = (string) ($asset['id'] ?? '');

                if ('' === $id) {
                    continue;
                }

                $entries[] = new Entry(
                    id: $id,
                    name: (string) ($asset['originalFileName'] ?? $id),
                    isFolder: false,
                    // Immich reports size under exifInfo, and omits it entirely
                    // on assets whose metadata has not been extracted yet. Null
                    // means "unknown", which the picker treats as "cannot
                    // promise this fits" rather than "empty".
                    size: $this->intOrNull($asset['exifInfo']['fileSizeInByte'] ?? null),
                    mime: $this->stringOrNull($asset['originalMimeType'] ?? null),
                    modifiedAt: $this->parseDate($asset['fileCreatedAt'] ?? null),
                );
            }
        }

        $name = (string) ($album['albumName'] ?? 'Album');

        return new Listing($entries, [...$this->breadcrumb(), Entry::folder($albumId, $name)]);
    }

    private function addToAlbum(Integration $integration, string $albumId, string $assetId): void
    {
        // Best effort: the asset is already safely in the library, so failing
        // to file it under an album is not worth losing the upload over.
        $this->request(
            $integration,
            'PUT',
            $this->url($integration, '/albums/'.rawurlencode($albumId).'/assets'),
            ['json' => ['ids' => [$assetId]]],
            false,
        )->getStatusCode();
    }

    /** @return list<Entry> */
    private function breadcrumb(): array
    {
        return [Entry::folder('', 'Immich')];
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * @return array<mixed>
     */
    private function get(Integration $integration, string $path): array
    {
        $response = $this->request($integration, 'GET', $this->url($integration, $path));

        try {
            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    private function request(
        Integration $integration,
        string $method,
        string $url,
        array $options = [],
        bool $throwOnError = true,
    ): ResponseInterface {
        if (null === $integration->secret || '' === $integration->secret) {
            throw new IntegrationException('This Immich connection is missing its API key.');
        }

        $options['headers'] = array_merge(
            ['x-api-key' => $integration->secret, 'Accept' => 'application/json'],
            $options['headers'] ?? [],
        );

        try {
            $response = $this->httpClient->request($method, $url, $options);

            if (true === $throwOnError) {
                $status = $response->getStatusCode();

                if ($status >= 400) {
                    throw new IntegrationException($this->messageForStatus($status), $status);
                }
            }

            return $response;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }
    }

    private function translate(HttpExceptionInterface $e): IntegrationException
    {
        $status = method_exists($e, 'getResponse') ? $e->getResponse()->getStatusCode() : 0;

        return new IntegrationException($this->messageForStatus($status), $status, $e);
    }

    private function messageForStatus(int $status): string
    {
        return match (true) {
            401 === $status || 403 === $status => 'Immich rejected the API key.',
            404 === $status                    => 'Immich could not find that album or photo.',
            $status >= 500                     => 'The Immich server reported an error.',
            0 === $status                      => 'Could not reach the Immich server. Check the address.',
            default                            => sprintf('Immich returned an unexpected response (%d).', $status),
        };
    }

    private function url(Integration $integration, string $path): string
    {
        $base = $this->urlValidator->resolve(
            $integration,
            $this->configRepository->findOneByProvider(Provider::Immich),
        );

        return $base.self::API.$path;
    }

    // ── Parsing ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,list<string>> $headers
     */
    private function filenameFromHeaders(array $headers): ?string
    {
        $disposition = $headers['content-disposition'][0] ?? null;

        if (null === $disposition) {
            return null;
        }

        if (1 === preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches)) {
            return rawurldecode($matches[1]);
        }

        return null;
    }

    private function parseDate(mixed $raw): ?DateTimeImmutable
    {
        if (false === is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
