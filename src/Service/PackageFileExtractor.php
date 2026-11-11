<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PackageFile;

/** Reads the structure out of a document: its outline and the shell commands it tells you to run. */
final class PackageFileExtractor
{
    private const array SHELL = ['bash', 'sh', 'shell', 'zsh', 'console'];

    /** @return array{headings: list<array{level: int, text: string}>, commands: list<string>, words: int} */
    public function extract(PackageFile $file): array
    {
        $headings = $commands = [];
        $fence = null;
        foreach (preg_split('/\R/u', $file->content) as $line) {
            if (preg_match('/^\s*(```+|~~~+)\s*([\w-]*)/u', $line, $m)) {
                $fence = null === $fence ? strtolower($m[2]) : null;
                continue;
            }
            if (null !== $fence) {
                $command = trim(preg_replace('/^\s*\$\s+/', '', $line));
                if ('' !== $command && !str_starts_with($command, '#') && in_array($fence, self::SHELL, true)) {
                    $commands[] = $command;
                }
            } elseif (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $m)) {
                $headings[] = ['level' => strlen($m[1]), 'text' => $m[2]];
            }
        }

        return [
            'headings' => $headings,
            'commands' => array_values(array_unique($commands)),
            'words' => str_word_count($file->content),
        ];
    }
}
