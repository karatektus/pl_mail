<?php

declare(strict_types=1);

// Lets phpstan-symfony understand console commands — argument and option types
// on InputInterface::getArgument()/getOption(), which are otherwise mixed.

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');

return new Application(new Kernel('test', true));
