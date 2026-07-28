<?php

namespace App;

use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Infrastructure\Encryption\Encryptor;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Doctrine builds its types through a static registry with no access to
     * the container, so EncryptedStringType is handed its Encryptor here —
     * after boot, when the container exists, and before any entity is loaded.
     *
     * The has() guard covers booting against a compiled container that
     * predates this service, which is what a deployment looks like in the
     * moment before cache:clear rebuilds it. Skipping is safe rather than
     * silently insecure: the type refuses to convert a value without an
     * encryptor, so credentials can never fall back to plain text.
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->container->has(Encryptor::class)) {
            EncryptedStringType::setEncryptor($this->container->get(Encryptor::class));
        }
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
