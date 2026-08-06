<?php

declare(strict_types=1);

namespace App\Domain\DTO\Push;

use App\Domain\Exception\InvalidFirebaseCredentialsException;

/**
 * The four public values an Android client needs to build FirebaseOptions at
 * runtime, read out of an instance's google-services.json.
 *
 * **Why the server publishes these at all.** The plMail Android app ships as
 * one APK from the Play Store, and every self-hosted install has its own
 * Firebase project — so the app cannot bake in a google-services.json the way
 * the Gradle plugin normally arranges. It initialises Firebase from the
 * Session's push capability instead, and these are exactly the inputs to
 * FirebaseOptions.Builder: setProjectId, setApplicationId, setApiKey,
 * setGcmSenderId.
 *
 * **Not secret, and that is a property rather than an oversight.** All four
 * ship inside every Firebase app's APK and are readable by anyone who unzips
 * one; the API key here is a client key scoped by Firebase's own project rules,
 * not a credential that can send anything. So they are stored in clear columns
 * and published in the Session to any authenticated user, while the
 * service-account key beside them — which CAN send — is encrypted and never
 * leaves the server.
 *
 * **One Android client is picked, not all of them.** A google-services.json can
 * register several packages (a debug variant, a white-label build), and the
 * Session publishes one object because a client builds one FirebaseOptions. The
 * preferred package is named below; anything else falls back to the first
 * registered client, so an install that renamed its package still works and an
 * install that did not gets the right one deliberately rather than by position.
 */
final readonly class ClientConfig
{
    /**
     * The package the official plMail Android app is published under.
     *
     * Preferred rather than required: a fork or a self-built APK has its own
     * package name, and refusing that file would make this feature unusable by
     * exactly the people most likely to be self-hosting.
     */
    public const string PREFERRED_PACKAGE = 'de.plmail.google';

    public function __construct(
        public string $projectId,
        /** `mobilesdk_app_id`, e.g. "1:1234567890:android:0123abcd". */
        public string $applicationId,
        /** The Android client's `current_key`. */
        public string $apiKey,
        /** `project_number` — the FCM sender id. */
        public string $senderId,
        /** Which registered package the three values above came from. */
        public string $packageName,
    ) {}

    /**
     * @throws InvalidFirebaseCredentialsException naming what is missing or wrong
     */
    public static function fromGoogleServicesJson(string $json): self
    {
        try {
            $decoded = json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidFirebaseCredentialsException(
                sprintf('That is not valid JSON (%s). Paste the whole google-services.json, braces included.', $exception->getMessage()),
                previous: $exception,
            );
        }

        if (false === is_array($decoded)) {
            throw new InvalidFirebaseCredentialsException('That is valid JSON but not an object. Paste the whole google-services.json, braces included.');
        }

        // The shape check, and it comes first for the same reason the service
        // account's "type" test does: a file with neither key is the WRONG
        // file, and reporting it as four missing fields reads as a corrupt one.
        $info    = $decoded['project_info'] ?? null;
        $clients = $decoded['client'] ?? null;

        if (false === is_array($info) || false === is_array($clients)) {
            throw new InvalidFirebaseCredentialsException(
                'This does not look like a google-services.json — it has no "project_info" and "client" pair. '
                . 'Firebase console → Project settings → Your apps → the Android app → google-services.json.',
            );
        }

        $client = self::pickAndroidClient($clients);

        $values = [
            'project_info.project_id'                  => $info['project_id'] ?? null,
            'project_info.project_number'              => $info['project_number'] ?? null,
            'client[].client_info.mobilesdk_app_id'    => $client['client_info']['mobilesdk_app_id'] ?? null,
            'client[].api_key[].current_key'           => $client['api_key'][0]['current_key'] ?? null,
        ];

        $missing = [];

        foreach ($values as $path => $value) {
            // project_number is a number in some exports and a string in
            // others, and both are correct — Firebase's own tooling writes it
            // either way. Rejecting the numeric form would refuse a genuine
            // file over a JSON type.
            if (false === is_string($value) && false === is_int($value)) {
                $missing[] = $path;

                continue;
            }

            if ('' === trim((string) $value)) {
                $missing[] = $path;
            }
        }

        if ([] !== $missing) {
            throw new InvalidFirebaseCredentialsException(sprintf(
                'The google-services.json is missing %s.',
                implode(', ', $missing),
            ));
        }

        $package = $client['client_info']['android_client_info']['package_name'] ?? null;

        return new self(
            projectId:     trim((string) $values['project_info.project_id']),
            applicationId: trim((string) $values['client[].client_info.mobilesdk_app_id']),
            apiKey:        trim((string) $values['client[].api_key[].current_key']),
            senderId:      trim((string) $values['project_info.project_number']),
            packageName:   is_string($package) ? trim($package) : '',
        );
    }

    /**
     * The registered Android client to publish, preferring the official package
     * and otherwise taking the first.
     *
     * Deliberately not "the only one": multi-client files are ordinary, and a
     * file that registers a debug variant beside the release one would
     * otherwise be refused for being complete.
     *
     * @param array<mixed> $clients
     *
     * @return array<string,mixed>
     */
    private static function pickAndroidClient(array $clients): array
    {
        $first = null;

        foreach ($clients as $client) {
            if (false === is_array($client)) {
                continue;
            }

            $first ??= $client;

            $package = $client['client_info']['android_client_info']['package_name'] ?? null;

            if (self::PREFERRED_PACKAGE === $package) {
                return $client;
            }
        }

        if (null === $first) {
            throw new InvalidFirebaseCredentialsException(
                'The google-services.json registers no apps at all — its "client" array is empty. '
                . 'Add an Android app to the Firebase project and download the file again.',
            );
        }

        return $first;
    }
}
