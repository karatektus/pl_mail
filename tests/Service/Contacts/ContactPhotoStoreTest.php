<?php

declare(strict_types=1);

namespace App\Tests\Service\Contacts;

use App\Service\Contacts\ContactPhotoStore;
use PHPUnit\Framework\TestCase;

/**
 * Photos are written under the storage directory's uploads, the volume the web
 * and worker containers share. Under var/contacts, a photo the worker's sync
 * stored was invisible to the web container serving it.
 */
final class ContactPhotoStoreTest extends TestCase
{
    public function testAPhotoIsStoredUnderTheSharedUploadsAndReadBackFromThere(): void
    {
        $root  = sys_get_temp_dir() . '/plmail-photo-store-' . bin2hex(random_bytes(4));
        $store = new ContactPhotoStore($root, 'var');

        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $path = $store->storeBytes($png, 'image/png', 7);

        self::assertStringStartsWith('contacts/7/', $path, 'what Contact::$photoPath holds is unchanged');
        self::assertSame($root . '/var/uploads/' . $path, $store->absolutePath($path));
        self::assertFileExists($root . '/var/uploads/' . $path);
        self::assertTrue($store->exists($path));
        self::assertSame('image/png', $store->read($path)?->mime);
        self::assertNull($store->absolutePath('contacts/../../secrets/key'), 'no way out of the photo directory');

        $store->delete($path);

        self::assertFalse($store->exists($path));
    }
}
