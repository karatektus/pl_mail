<?php

declare(strict_types=1);

namespace App\Infrastructure\JmapClient;

/**
 * The capability URIs plMail asks a remote JMAP server for.
 *
 * NOT App\Jmap\Protocol\Capability, and deliberately a second list. That one is
 * plMail's own server, which publishes its calendars, contacts and push under
 * private `urn:plmail:` names; a remote server speaks the IETF ones, and a
 * client that borrowed the server's constants would ask Stalwart for
 * `urn:plmail:params:jmap:calendars` and conclude it has no calendars.
 *
 * Calendars is still an Internet-Draft (draft-ietf-jmap-calendars) and the URI
 * below is the one it registers. Contacts is RFC 9610.
 */
final class JmapCapability
{
    public const string CORE       = 'urn:ietf:params:jmap:core';
    public const string MAIL       = 'urn:ietf:params:jmap:mail';
    public const string SUBMISSION = 'urn:ietf:params:jmap:submission';
    public const string CALENDARS  = 'urn:ietf:params:jmap:calendars';
    public const string CONTACTS   = 'urn:ietf:params:jmap:contacts';

    /**
     * RFC 9404's Blob/get, which fetches several blobs in one request.
     *
     * Optional: without it every message body is its own download, which is
     * correct and only slower. See JmapBlobReader.
     */
    public const string BLOB = 'urn:ietf:params:jmap:blob';
}
