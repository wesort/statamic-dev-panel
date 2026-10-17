<?php

namespace WeSort\DevPanel\Setup;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The installed version of the addon, and the latest release on GitHub.
 */
class Releases
{
    public const PACKAGE = 'wesort/statamic-dev-panel';

    public const REPO = 'wesort/statamic-dev-panel';

    /**
     * @return array{version: ?string, reference: ?string}
     */
    public function installed(): array
    {
        if (! InstalledVersions::isInstalled(self::PACKAGE)) {
            return ['version' => null, 'reference' => null];
        }

        return [
            'version' => InstalledVersions::getPrettyVersion(self::PACKAGE),
            'reference' => InstalledVersions::getReference(self::PACKAGE),
        ];
    }

    /**
     * The highest stable version tag on GitHub, eg "v1.2.0". Null if there are
     * none. Throws if GitHub can't be reached.
     */
    public function latestTag(): ?string
    {
        return collect($this->get('tags?per_page=100'))
            ->pluck('name')
            ->filter(fn ($tag) => preg_match('/^v?\d+\.\d+\.\d+$/', $tag))
            ->sort(fn ($a, $b) => version_compare(ltrim($a, 'v'), ltrim($b, 'v')))
            ->last();
    }

    /**
     * The commit at the tip of main. Throws if GitHub can't be reached.
     */
    public function mainReference(): ?string
    {
        return $this->get('commits/main')['sha'] ?? null;
    }

    public function releaseUrl(string $tag): string
    {
        return 'https://github.com/'.self::REPO.'/releases/tag/'.$tag;
    }

    protected function get(string $path): array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/repos/'.self::REPO.'/'.$path);
        } catch (Throwable $e) {
            throw new \RuntimeException('GitHub could not be reached');
        }

        if (! $response->successful()) {
            throw new \RuntimeException('GitHub returned '.$response->status());
        }

        return $response->json() ?? [];
    }
}
