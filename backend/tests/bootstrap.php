<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Credentials live in exactly one gitignored file at the repository root
// (Standing Rule 10). backend/.env deliberately does not exist.
$rootEnv = dirname(__DIR__, 2).'/.env';

if (!is_file($rootEnv)) {
    fwrite(STDERR, "\n  Missing ".$rootEnv."\n  Run: cp .env.example .env   (then fill in every CHANGE_ME)\n\n");
    exit(1);
}

(new Dotenv())->bootEnv($rootEnv);
