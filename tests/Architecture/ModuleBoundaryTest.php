<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces the module boundaries from ADR 0001 and CLAUDE.md Article 2.
 *
 * Nothing in PHP physically prevents one module from reaching into another's internals,
 * so this test is the guard. If it is ever weakened to make a change pass, the modular
 * monolith has quietly become a big ball of mud.
 */
final class ModuleBoundaryTest extends TestCase
{
    /**
     * The only layers another module may import.
     *
     * `Application/` is the use-case entry point and `Contracts/` is the module's
     * published vocabulary. Everything else — Domain, Infrastructure, Http, Providers —
     * is internal (CLAUDE.md Article 2.1).
     */
    private const PUBLIC_LAYERS = ['Application', 'Contracts'];

    /**
     * Shared is the shared kernel, not a peer domain module: every module may use its
     * response builder, error vocabulary and actor/pagination types. It is constrained
     * instead by shared_depends_on_no_other_module() below, which keeps the kernel from
     * ever pointing back at a domain (CLAUDE.md Article 2.3).
     */
    private const SHARED_KERNEL = 'Shared';

    #[Test]
    public function no_module_imports_another_modules_internals(): void
    {
        $violations = [];

        foreach (self::modules() as $module) {
            foreach (self::phpFilesIn(self::modulePath($module)) as $file) {
                foreach (self::moduleImports($file) as [$importedModule, $layer]) {
                    if ($importedModule === $module || $importedModule === self::SHARED_KERNEL) {
                        continue;
                    }

                    if (! in_array($layer, self::PUBLIC_LAYERS, true)) {
                        $violations[] = sprintf(
                            '%s imports %s\\%s (only %s are public)',
                            self::relativePath($file->getPathname()),
                            $importedModule,
                            $layer,
                            implode('/ and ', self::PUBLIC_LAYERS).'/',
                        );
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Module boundary violations:\n".implode("\n", $violations));
    }

    #[Test]
    public function shared_depends_on_no_other_module(): void
    {
        $violations = [];

        foreach (self::phpFilesIn(self::modulePath('Shared')) as $file) {
            foreach (self::moduleImports($file) as [$importedModule, $layer]) {
                if ($importedModule !== 'Shared') {
                    $violations[] = sprintf(
                        '%s imports %s\\%s',
                        self::relativePath($file->getPathname()),
                        $importedModule,
                        $layer,
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Shared is the shared kernel and may depend on nothing but the framework '
                ."(CLAUDE.md Article 2.3):\n".implode("\n", $violations),
        );
    }

    #[Test]
    public function no_module_defines_an_eloquent_relationship_to_another_module(): void
    {
        $violations = [];

        foreach (self::modules() as $module) {
            foreach (self::phpFilesIn(self::modulePath($module)) as $file) {
                $source = (string) file_get_contents($file->getPathname());

                // Cross-module data access must go through the owner's application
                // service; an Eloquent relation to another module's model would couple
                // the two schemas permanently (CLAUDE.md Article 2.2).
                if (preg_match('/(hasMany|hasOne|belongsTo|belongsToMany|morphMany)\s*\(\s*\\\\?Modules\\\\(\w+)/', $source, $matches) === 1
                    && $matches[2] !== $module) {
                    $violations[] = self::relativePath($file->getPathname()).' → Modules\\'.$matches[2];
                }
            }
        }

        $this->assertSame([], $violations, "Cross-module Eloquent relationships:\n".implode("\n", $violations));
    }

    #[Test]
    public function every_registered_module_exists_and_is_wired(): void
    {
        $enabled = require self::basePath('config/modules.php');

        $this->assertNotEmpty($enabled['enabled'], 'config/modules.php must register at least one module.');

        foreach ($enabled['enabled'] as $module) {
            $this->assertDirectoryExists(
                self::modulePath($module),
                "Module `{$module}` is registered in config/modules.php but has no directory."
            );

            $this->assertFileExists(
                self::modulePath($module)."/Providers/{$module}ServiceProvider.php",
                "Module `{$module}` must declare a service provider."
            );
        }
    }

    #[Test]
    public function every_module_directory_is_registered(): void
    {
        /** @var array{enabled: list<string>} $config */
        $config = require self::basePath('config/modules.php');

        foreach (self::modules() as $module) {
            $this->assertContains(
                $module,
                $config['enabled'],
                "Module directory `{$module}` exists but is not registered in config/modules.php, "
                    .'so it silently does not load.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function modules(): array
    {
        $modules = [];

        foreach ((array) scandir(self::basePath('modules')) as $entry) {
            if (! is_string($entry) || str_starts_with($entry, '.')) {
                continue;
            }

            if (is_dir(self::modulePath($entry))) {
                $modules[] = $entry;
            }
        }

        return $modules;
    }

    /**
     * @return list<array{0: string, 1: string}> [module, layer] pairs
     */
    private static function moduleImports(SplFileInfo $file): array
    {
        $source = (string) file_get_contents($file->getPathname());

        preg_match_all('/^use\s+Modules\\\\(\w+)\\\\(\w+)/m', $source, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): array => [$match[1], $match[2]],
            $matches,
        );
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    private static function modulePath(string $module): string
    {
        return self::basePath('modules/'.$module);
    }

    private static function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path !== '' ? DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path) : '');
    }

    private static function relativePath(string $absolute): string
    {
        return str_replace('\\', '/', substr($absolute, strlen(self::basePath()) + 1));
    }
}
