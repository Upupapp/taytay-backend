<?php

declare(strict_types=1);

namespace Modules\Shared\Console;

use Illuminate\Console\Command;
use Modules\Shared\Support\OpenApiGenerator;

/**
 * Writes `docs/api/openapi.json` from the running application (ADR 0038).
 *
 * `--check` compares instead of writing, and that mode is the point: `OpenApiIsCurrentTest` runs it
 * so a response-shape change that nobody regenerated fails the build.
 *
 * The document is COMMITTED rather than generated on demand, for two reasons. A frontend developer
 * should be able to read the contract from the repository without booting PHP — and a committed
 * document means a change to a response shape produces a **diff a reviewer sees**, in the same
 * commit as the change. That is what turns "breaking response changes require a version or
 * deprecation decision" into something a person actually notices.
 */
final class GenerateOpenApiCommand extends Command
{
    protected $signature = 'lguids:openapi {--check : Fail if the committed document is stale}';

    protected $description = 'Generate the OpenAPI 3.1 document from the router, enums and error codes';

    public function handle(OpenApiGenerator $generator): int
    {
        $path = base_path('docs/api/openapi.json');

        $document = (string) json_encode(
            $generator->generate(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";

        if ($this->option('check')) {
            $committed = is_file($path) ? (string) file_get_contents($path) : '';

            if ($committed === $document) {
                $this->info('The committed OpenAPI document is current.');

                return self::SUCCESS;
            }

            $this->error('docs/api/openapi.json is stale. Run: php artisan lguids:openapi');

            return self::FAILURE;
        }

        file_put_contents($path, $document);

        $this->info('Wrote '.$path);

        return self::SUCCESS;
    }
}
