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

// Standing Rule 17, mechanised (W-4): every PHPUnit process reads API metadata from its OWN empty cache directory, so a
// warm `var/cache/test` left by an earlier run can never answer a spec test with a stale shape (a fake green that
// deleting the cache by hand used to prevent). `MicroKernelTrait::getCacheDir()` honours APP_CACHE_DIR; APP_BUILD_DIR
// stays unset, so the build directory follows it. A child PHP process that inherits this environment and requires this
// bootstrap reuses the directory and leaves its removal to the process that made it. Directories left by a killed run
// (whose process is gone) are removed on the next start. Checked by ContractArtefactTest::theSpecWasGeneratedFromAColdCache.
(static function (): void {
    $prefix = 'erp-phpunit-cache-';
    $shape = '/^erp-phpunit-cache-(\d+)-[0-9a-f]{8}$/';
    $temp = rtrim(sys_get_temp_dir(), '/');
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $remove($path.'/'.$entry);
            }
        }
        @rmdir($path);
    };

    $inherited = getenv('ERP_TEST_CACHE_DIR');
    if (\is_string($inherited) && 1 === preg_match($shape, basename($inherited)) && \dirname($inherited) === $temp && is_dir($inherited)) {
        $directory = $inherited;
    } else {
        foreach (scandir($temp) ?: [] as $entry) {
            if (1 !== preg_match($shape, $entry, $match) || !is_dir($temp.'/'.$entry)) {
                continue;
            }
            $stale = \function_exists('posix_kill')
                ? !posix_kill((int) $match[1], 0) && 1 !== posix_get_last_error() // 1 = EPERM: alive, someone else's
                : filemtime($temp.'/'.$entry) < time() - 86400;
            if ($stale) {
                $remove($temp.'/'.$entry);
            }
        }

        $directory = $temp.'/'.$prefix.getmypid().'-'.bin2hex(random_bytes(4));
        if (file_exists($directory) || !@mkdir($directory, 0700)) {
            fwrite(STDERR, "\n  Could not create a fresh test cache directory at ".$directory."\n\n");
            exit(1);
        }
        $creator = getmypid();
        register_shutdown_function(static function () use ($directory, $creator, $remove, $shape): void {
            if (getmypid() === $creator && 1 === preg_match($shape, basename($directory)) && is_dir($directory)) {
                $remove($directory);
            }
        });
    }

    $_SERVER['APP_CACHE_DIR'] = $_ENV['APP_CACHE_DIR'] = $directory;
    putenv('APP_CACHE_DIR='.$directory);
    putenv('ERP_TEST_CACHE_DIR='.$directory);
    \define('ERP_TEST_CACHE_DIR', $directory);
})();
