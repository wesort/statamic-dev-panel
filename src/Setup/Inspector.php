<?php

namespace WeSort\DevPanel\Setup;

use Illuminate\Support\Facades\File;

/**
 * Reads a site's files to see how the addon is set up. Used by the install and
 * status commands. Never writes anything.
 */
class Inspector
{
    public const VIEWS_PATH = 'vendor/wesort/statamic-dev-panel/resources/views';

    public function __construct(protected string $base)
    {
        $this->base = rtrim($base, '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    public function relative(string $path): string
    {
        return ltrim(str_replace($this->base, '', $path), '/');
    }

    /**
     * The installed version of an npm package, or its package.json constraint
     * if node_modules isn't there. Null if the site doesn't require it.
     */
    public function npmVersion(string $package): ?string
    {
        $installed = $this->base."/node_modules/{$package}/package.json";

        if (file_exists($installed)) {
            return json_decode(file_get_contents($installed), true)['version'] ?? null;
        }

        $json = $this->base.'/package.json';

        if (! file_exists($json)) {
            return null;
        }

        $data = json_decode(file_get_contents($json), true) ?: [];

        return $data['dependencies'][$package] ?? $data['devDependencies'][$package] ?? null;
    }

    public function npmMajor(string $package): ?int
    {
        $version = $this->npmVersion($package);

        return $version && preg_match('/(\d+)/', $version, $m) ? (int) $m[1] : null;
    }

    /**
     * CSS files under resources/css that import Tailwind, so are the site's
     * main stylesheets.
     */
    public function tailwindCssFiles(): array
    {
        $dir = $this->base.'/resources/css';

        if (! is_dir($dir)) {
            return [];
        }

        return collect(File::allFiles($dir))
            ->map(fn ($file) => $file->getPathname())
            ->filter(fn ($path) => str_ends_with($path, '.css'))
            ->filter(fn ($path) => preg_match('/@import\s+["\']tailwindcss["\']|@tailwind\s+base/', file_get_contents($path)))
            ->sort()
            ->values()
            ->all();
    }

    public function tailwindConfigFile(): ?string
    {
        foreach (['js', 'cjs', 'mjs', 'ts'] as $extension) {
            $path = $this->base."/tailwind.config.{$extension}";

            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The file that already points Tailwind at the addon's views, if any.
     */
    public function tailwindSourceFile(): ?string
    {
        $candidates = $this->tailwindCssFiles();

        if ($config = $this->tailwindConfigFile()) {
            $candidates[] = $config;
        }

        foreach ($candidates as $path) {
            if (str_contains(file_get_contents($path), 'statamic-dev-panel')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The @source line for a Tailwind 4 CSS file, relative to that file.
     */
    public function sourceLine(string $cssFile): string
    {
        $from = array_values(array_filter(explode('/', dirname($cssFile)), 'strlen'));
        $to = array_values(array_filter(explode('/', $this->base.'/'.self::VIEWS_PATH), 'strlen'));

        while ($from && $to && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return '@source "'.str_repeat('../', count($from)).implode('/', $to).'";';
    }

    /**
     * Add the @source line to a Tailwind 4 CSS file: after its last @source
     * line, or after the Tailwind import if it has none.
     */
    public function withSourceLine(string $css, string $line): string
    {
        $lines = explode("\n", $css);
        $at = null;

        foreach ($lines as $i => $text) {
            if (preg_match('/^\s*@source\s/', $text)) {
                $at = $i;
            }
        }

        if ($at === null) {
            foreach ($lines as $i => $text) {
                if (preg_match('/@import\s+["\']tailwindcss["\']/', $text)) {
                    $at = $i;
                    break;
                }
            }
        }

        if ($at === null) {
            return rtrim($css, "\n")."\n".$line."\n";
        }

        array_splice($lines, $at + 1, 0, [$line]);

        return implode("\n", $lines);
    }

    public function viewFiles(): array
    {
        $dir = $this->base.'/resources/views';

        if (! is_dir($dir)) {
            return [];
        }

        return collect(File::allFiles($dir))
            ->map(fn ($file) => $file->getPathname())
            ->filter(fn ($path) => preg_match('/\.(antlers\.html|antlers\.php|blade\.php)$/', $path))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Lines in the site's views matching a pattern, as "path:line".
     */
    public function findInViews(string $pattern): array
    {
        $found = [];

        foreach ($this->viewFiles() as $path) {
            foreach (file($path) as $i => $text) {
                if (preg_match($pattern, $text)) {
                    $found[] = $this->relative($path).':'.($i + 1);
                }
            }
        }

        return $found;
    }

    public function filesInViews(string $pattern): array
    {
        return collect($this->viewFiles())
            ->filter(fn ($path) => preg_match($pattern, basename($path)))
            ->map(fn ($path) => $this->relative($path))
            ->values()
            ->all();
    }

    /**
     * Whether the built CSS from Vite includes a class only the panel uses,
     * so the front end has been rebuilt since the @source line was added.
     * Null if there's no Vite build to check.
     */
    public function builtCssHasPanel(): ?bool
    {
        $manifest = $this->base.'/public/build/manifest.json';

        if (! file_exists($manifest)) {
            return null;
        }

        $css = collect(json_decode(file_get_contents($manifest), true) ?: [])
            ->flatMap(fn ($chunk) => array_merge([$chunk['file'] ?? ''], $chunk['css'] ?? []))
            ->filter(fn ($file) => str_ends_with($file, '.css'))
            ->unique();

        if ($css->isEmpty()) {
            return null;
        }

        return $css->contains(function ($file) {
            $path = $this->base.'/public/build/'.$file;

            return file_exists($path) && str_contains(file_get_contents($path), 'bg-black\/80');
        });
    }

    /**
     * Whether config/statamic/git.php runs git without -C, so it reads the
     * repo of whatever directory a command runs in.
     */
    public function gitConfigRunsInCurrentDirectory(): bool
    {
        $path = $this->base.'/config/statamic/git.php';

        return file_exists($path) && preg_match("/exec\(\s*['\"]git (?!-C)/", file_get_contents($path)) === 1;
    }
}
