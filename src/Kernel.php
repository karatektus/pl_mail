<?php

namespace App;

use App\Doctrine\Type\EncryptedStringType;
use App\Encryption\Encryptor;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Doctrine builds its types through a static registry with no access to
     * the container, so EncryptedStringType is handed its Encryptor here —
     * after boot, when the container exists, and before any entity is loaded.
     */
    public function boot(): void
    {
        parent::boot();

        EncryptedStringType::setEncryptor($this->container->get(Encryptor::class));
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
