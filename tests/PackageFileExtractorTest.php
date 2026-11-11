<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Entity\PackageFile;
use App\Service\PackageFileExtractor;
use PHPUnit\Framework\TestCase;

final class PackageFileExtractorTest extends TestCase
{
    public function testItReadsTheOutlineAndTheShellCommands(): void
    {
        $agents = "# Agents\n\n## Testing\n\n```bash\n# run all\n\$ vendor/bin/phpunit\nbin/console lint:twig\n```\n\n```php\necho 1;\n```\n\n### Notes ###\n";
        $file = new PackageFile(new Package('acme/demo'), PackageFile::AGENTS, 'AGENTS.md', $agents);

        $result = (new PackageFileExtractor())->extract($file);

        self::assertSame([['level' => 1, 'text' => 'Agents'], ['level' => 2, 'text' => 'Testing'], ['level' => 3, 'text' => 'Notes']], $result['headings']);
        self::assertSame(['vendor/bin/phpunit', 'bin/console lint:twig'], $result['commands']);
    }
}
