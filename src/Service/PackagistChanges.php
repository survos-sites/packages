<?php

declare(strict_types=1);

namespace App\Service;

/** What one read of Packagist's changes feed did. */
final class PackagistChanges
{
    /** Distinct packages Packagist reported as updated. */
    public int $changed = 0;
    /** @var list<string> tracked packages whose load was queued */
    public array $queued = [];
    /** @var list<string> tracked packages mid-workflow, not requeued */
    public array $skipped = [];
    /** @var list<string> packages Packagist reported deleted (tracked or not) */
    public array $deleted = [];
    /** True when Packagist could not replay from our cursor; changes in the gap were missed. */
    public bool $resync = false;

    public function __construct(
        /** True on the first run: the cursor was just set to "now" and nothing was replayed. */
        public readonly bool $initialized,
    ) {
    }
}
