<?php

namespace App\Support;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts an artisan command as its own process and returns at once, so the caller (a web request) does not wait for it and the
 * command is not killed when the request ends. Returns false when the host does not allow spawning, so the caller can run it inline.
 */
class BackgroundArtisan
{
    public function start(string $command): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (! function_exists('exec') || in_array('exec', $disabled, true) || PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        // Under FPM or Apache PHP_BINARY is not the command-line PHP, so ask for it.
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';

        $line = sprintf('nohup %s %s %s > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg(base_path('artisan')), $command);
        exec($line, $out, $code);

        return $code === 0;
    }
}
