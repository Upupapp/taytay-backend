<?php

declare(strict_types=1);

namespace Modules\Shared\Support;

/**
 * The authoritative list of loaded modules (ADR 0001).
 *
 * Registration is explicit rather than filesystem-discovered: a module that is not listed
 * in config/modules.php does not exist as far as the application is concerned. Explicit
 * registration keeps boot deterministic and makes "what is deployed" reviewable.
 */
final class ModuleRegistry
{
    /**
     * @return list<string>
     */
    public static function enabled(): array
    {
        /** @var list<string> $modules */
        $modules = config('modules.enabled', []);

        return $modules;
    }

    public static function path(string $module, string $relative = ''): string
    {
        return base_path('modules/'.$module.($relative !== '' ? '/'.ltrim($relative, '/') : ''));
    }

    /**
     * Service provider class names for enabled modules, skipping modules that do not
     * declare one.
     *
     * @return list<class-string>
     */
    public static function providers(): array
    {
        $providers = [];

        foreach (self::enabled() as $module) {
            /** @var class-string $provider */
            $provider = "Modules\\{$module}\\Providers\\{$module}ServiceProvider";

            if (class_exists($provider)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Route files contributed to /api/v1, in module-registration order.
     *
     * @return list<string>
     */
    public static function apiV1RouteFiles(): array
    {
        $files = [];

        foreach (self::enabled() as $module) {
            $file = self::path($module, 'Routes/api_v1.php');

            if (is_file($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
