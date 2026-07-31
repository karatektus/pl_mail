<?php

declare(strict_types=1);

// Lets phpstan-doctrine resolve entity metadata, so it can check DQL strings
// and repository return types instead of treating them as mixed.

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
