<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces CLAUDE.md Article 0: this repository is backend-only.
 *
 * The four client applications live in their own repositories. Frontend code appearing
 * here — even "just a small admin page" — would create a second place where authorization
 * appears to be decided, which is exactly the failure ADR 0002 exists to prevent.
 */
final class NoFrontendCodeTest extends TestCase
{
    /** Paths that must not exist anywhere in the repository. */
    private const FORBIDDEN_PATHS = [
        'package.json',
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
        'node_modules',
        'vite.config.js',
        'vite.config.ts',
        'webpack.mix.js',
        'tailwind.config.js',
        'postcss.config.js',
        '.npmrc',
        'resources/js',
        'resources/css',
        'resources/views',
    ];

    /** File types that only exist to render a UI. */
    private const FORBIDDEN_SUFFIXES = [
        '.blade.php',
        '.vue',
        '.jsx',
        '.tsx',
        '.svelte',
        '.scss',
        '.sass',
        '.less',
    ];

    private const SCAN_EXCLUSIONS = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache', 'public/build'];

    #[Test]
    public function no_frontend_scaffolding_exists(): void
    {
        foreach (self::FORBIDDEN_PATHS as $path) {
            $this->assertFileDoesNotExist(
                self::basePath($path),
                "Frontend scaffolding `{$path}` must not exist in the backend repository (CLAUDE.md Article 0)."
            );
        }
    }

    #[Test]
    public function no_ui_files_exist(): void
    {
        $offenders = [];

        foreach (self::sourceFiles() as $file) {
            $relative = self::relativePath($file->getPathname());

            foreach (self::FORBIDDEN_SUFFIXES as $suffix) {
                if (str_ends_with(strtolower($file->getFilename()), $suffix)) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Backend repository contains UI files: \n".implode("\n", $offenders)
        );
    }

    #[Test]
    public function composer_scripts_do_not_invoke_a_frontend_toolchain(): void
    {
        $composer = file_get_contents(self::basePath('composer.json'));
        self::assertIsString($composer);

        /** @var array{scripts?: array<string, mixed>} $manifest */
        $manifest = json_decode($composer, true, 512, JSON_THROW_ON_ERROR);
        $scripts = json_encode($manifest['scripts'] ?? [], JSON_THROW_ON_ERROR);

        foreach (['npm ', 'npx ', 'vite', 'yarn ', 'pnpm '] as $tool) {
            $this->assertStringNotContainsString(
                $tool,
                $scripts,
                "composer.json scripts must not invoke the frontend toolchain (`{$tool}`)."
            );
        }
    }

    #[Test]
    public function the_application_registers_no_web_routes(): void
    {
        $bootstrap = file_get_contents(self::basePath('bootstrap/app.php'));
        self::assertIsString($bootstrap);

        // Matches an actual `web:` routing argument rather than the word anywhere in the
        // file, so explanatory comments do not trip the check.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*web:\s*\S/m',
            $bootstrap,
            'This backend serves no web UI; bootstrap/app.php must not register a web route file.'
        );
        $this->assertFileDoesNotExist(self::basePath('routes/web.php'));
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function sourceFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::basePath(), RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = self::relativePath($file->getPathname());

            foreach (self::SCAN_EXCLUSIONS as $excluded) {
                if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                    continue 2;
                }
            }

            if ($file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private static function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path !== '' ? DIRECTORY_SEPARATOR.$path : '');
    }

    private static function relativePath(string $absolute): string
    {
        return str_replace('\\', '/', substr($absolute, strlen(self::basePath()) + 1));
    }
}
