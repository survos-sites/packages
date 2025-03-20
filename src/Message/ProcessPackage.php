<?php

namespace App\Message;

final class ProcessPackage
{
    public function __construct(
        public readonly string $packageName,
    ) {
    }
}
