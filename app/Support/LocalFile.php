<?php

namespace App\Support;

use RuntimeException;

/**
 * Reading and opening files on this server's disk, throwing instead of
 * returning false, so a missing or unreadable file fails with its path.
 */
class LocalFile
{
    public static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read {$path}.");
        }

        return $contents;
    }

    /**
     * @return resource
     */
    public static function open(string $path, string $mode = 'r')
    {
        $stream = @fopen($path, $mode);

        if ($stream === false) {
            throw new RuntimeException("Unable to open {$path}.");
        }

        return $stream;
    }
}
