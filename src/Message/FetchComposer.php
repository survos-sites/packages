<?php

namespace App\Message;

final class FetchComposer
{
    /*
     * Add whatever properties and methods you need
     * to hold the data for this message class.
     */

    public function __construct(
        private readonly string $name,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
