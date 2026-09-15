<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Credentials live in gitignored files at the repository root (Standing Rule 10),
// split by who may read them (V3-1(i), V4 batch B2b-1, 2026-09-16):
//   .env        application DSNs and secrets; every Symfony kernel loads it
//   .env.test   the schema owner's DSN; `loadEnv` reads it here by name because
//               phpunit.dist.xml forces APP_ENV=test (owner fixtures need it)
//   .env.infra  compose bootstrap passwords; no PHP process loads it
// backend/.env deliberately does not exist.
$rootEnv = dirname(__DIR__, 2).'/.env';

foreach (['' => '.env.example', '.test' => '.env.test.example'] as $suffix => $template) {
    if (!is_file($rootEnv.$suffix)) {
        fwrite(STDERR, "\n  Missing ".$rootEnv.$suffix."\n  Run: cp ".$template.' .env'.$suffix."   (then fill in every CHANGE_ME)\n\n");
        exit(1);
    }
}

(new Dotenv())->bootEnv($rootEnv);
