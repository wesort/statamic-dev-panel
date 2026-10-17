<?php

namespace WeSort\DevPanel\Commands;

use Composer\Semver\Semver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\View;
use Statamic\Console\RunsInPlease;
use Throwable;
use WeSort\DevPanel\Setup\Inspector;
use WeSort\DevPanel\Setup\Releases;

class Status extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:dev-panel:status
        {--offline : Skip checking GitHub for a newer release}';

    protected $description = 'Check how the Dev Panel addon is set up on this site.';

    protected array $results = [];

    public function handle(Inspector $site, Releases $releases): int
    {
        $config = config('statamic-dev-panel');
        $features = $config['features'] ?? [];

        $this->checkVersion($site, $releases);
        $this->checkConfig($config);
        $this->checkFrontEnd($site);
        $this->checkTemplates($site, $features);
        $this->checkLeftovers($site);
        $this->checkCommit($site, $features);
        $this->checkExtras($config['extra'] ?? []);

        $this->output->newLine();
        $this->line('<options=bold>Dev Panel status</>');
        $this->output->newLine();

        foreach ($this->results as [$state, $label, $detail, $hints]) {
            $mark = ['ok' => '<fg=green>✓</>', 'warn' => '<fg=yellow>!</>', 'fail' => '<fg=red>✗</>', 'info' => '<fg=gray>·</>'][$state];

            $this->line(sprintf('  %s  %-16s %s', $mark, $label, $detail));

            foreach ($hints as $hint) {
                $this->line(str_repeat(' ', 22).'<fg=gray>'.$hint.'</>');
            }
        }

        $failures = collect($this->results)->where(0, 'fail')->count();
        $warnings = collect($this->results)->where(0, 'warn')->count();

        $this->output->newLine();

        if ($failures) {
            $this->line("<fg=red>{$failures} to fix</>".($warnings ? ", {$warnings} to look at" : '').'.');

            return self::FAILURE;
        }

        $this->line($warnings ? "<fg=yellow>Working, with {$warnings} to look at.</>" : '<fg=green>All set.</>');

        return self::SUCCESS;
    }

    protected function add(string $state, string $label, string $detail, array $hints = []): void
    {
        $this->results[] = [$state, $label, $detail, $hints];
    }

    protected function checkVersion(Inspector $site, Releases $releases): void
    {
        ['version' => $version, 'reference' => $reference] = $releases->installed();
        $short = $reference ? substr($reference, 0, 7) : null;
        $isBranch = $version && str_starts_with($version, 'dev-');

        if (! $version) {
            $this->add('info', 'Version', 'unknown: the addon isn\'t in Composer\'s installed packages');

            return;
        }

        $label = $isBranch && $short ? "{$version} ({$short})" : $version;

        if ($this->option('offline')) {
            $this->add('info', 'Version', $label.', not checked for updates (--offline)');

            return;
        }

        try {
            $latest = $releases->latestTag();
            $main = $isBranch ? $releases->mainReference() : null;
        } catch (Throwable $e) {
            $this->add('info', 'Version', $label.', couldn\'t check for updates: '.$e->getMessage());

            return;
        }

        if ($isBranch) {
            $hints = [];

            if ($main && $reference && $main !== $reference) {
                $hints[] = 'main has newer commits: composer update '.Releases::PACKAGE;
            }

            if ($latest) {
                $hints[] = $latest.' is released: require "'.Releases::PACKAGE.'": "^'.$this->major($latest).'.0" instead of a branch';
            }

            $hints
                ? $this->add('warn', 'Version', $label, $hints)
                : $this->add('ok', 'Version', $label.', up to date with main');

            return;
        }

        if (! $latest || version_compare(ltrim($latest, 'v'), ltrim($version, 'v'), '<=')) {
            $this->add('ok', 'Version', $version.', the latest release');

            return;
        }

        $constraint = $site->composerConstraint(Releases::PACKAGE);
        $allowed = $constraint && $this->satisfies(ltrim($latest, 'v'), $constraint);

        $this->add('warn', 'Version', "{$version}, {$latest} is available", $allowed
            ? ['Update: composer update '.Releases::PACKAGE, 'Release notes: '.$releases->releaseUrl($latest)]
            : [
                "{$latest} is outside composer.json's \"{$constraint}\": read the release notes first",
                'Release notes: '.$releases->releaseUrl($latest),
                'Then: composer require '.Releases::PACKAGE.':^'.$this->major($latest).'.0',
            ]);
    }

    protected function major(string $tag): int
    {
        return (int) explode('.', ltrim($tag, 'v'))[0];
    }

    protected function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function checkConfig(array $config): void
    {
        file_exists(config_path('statamic-dev-panel.php'))
            ? $this->add('ok', 'Config', 'config/statamic-dev-panel.php')
            : $this->add('info', 'Config', 'using the defaults', ['Publish to customise: php please dev-panel:install']);

        ($config['enabled'] ?? true)
            ? $this->add('ok', 'Enabled', 'yes')
            : $this->add('warn', 'Enabled', 'no: DEV_PANEL_ENABLED is false, so nothing renders');
    }

    protected function checkFrontEnd(Inspector $site): void
    {
        $alpine = $site->npmVersion('alpinejs');

        if (! $alpine) {
            $this->add('fail', 'Alpine.js', 'not found in package.json', ['The panel needs Alpine.js 3: npm install alpinejs, then start it in your JS']);
        } else {
            $site->npmMajor('alpinejs') === 3
                ? $this->add('ok', 'Alpine.js', $alpine)
                : $this->add('warn', 'Alpine.js', "{$alpine}: the panel is built for Alpine.js 3");
        }

        ($persist = $site->npmVersion('@alpinejs/persist'))
            ? $this->add('ok', 'Alpine persist', $persist)
            : $this->add('info', 'Alpine persist', 'not installed', ['Optional: with it, each user\'s toggles are remembered']);

        $tailwind = $site->npmVersion('tailwindcss');
        $major = $site->npmMajor('tailwindcss');

        if (! $tailwind) {
            $this->add('fail', 'Tailwind', 'not found in package.json', ['The panel is styled with the site\'s Tailwind build (3 or 4)']);

            return;
        }

        $this->add('ok', 'Tailwind', $tailwind);

        if ($source = $site->tailwindSourceFile()) {
            $this->add('ok', 'Tailwind source', 'addon views included in '.$site->relative($source));
        } elseif ($major >= 4) {
            $files = $site->tailwindCssFiles();

            $this->add('fail', 'Tailwind source', 'the addon\'s views aren\'t in the build', $files
                ? ['Add to '.$site->relative($files[0]).': '.$site->sourceLine($files[0]), 'Or run: php please dev-panel:install']
                : ['Add an @source line for '.Inspector::VIEWS_PATH.' to your main CSS file']);
        } else {
            $this->add('fail', 'Tailwind source', 'the addon\'s views aren\'t in the build', [
                'Add to content in '.($site->tailwindConfigFile() ? $site->relative($site->tailwindConfigFile()) : 'tailwind.config.js').": './".Inspector::VIEWS_PATH."/**/*.html'",
            ]);
        }

        $built = $site->builtCssHasPanel();

        if ($built === true) {
            $this->add('ok', 'Built CSS', 'includes the panel\'s classes');
        } elseif ($built === false) {
            $this->add('warn', 'Built CSS', 'doesn\'t include the panel\'s classes yet', ['Rebuild the front end: npm run build']);
        }
    }

    protected function checkTemplates(Inspector $site, array $features): void
    {
        $panel = $site->findInViews('/\{\{\s*dev_panel\s*\}\}/');

        $panel
            ? $this->add('ok', 'Panel tag', implode(', ', $panel))
            : $this->add('fail', 'Panel tag', '{{ dev_panel }} not found in resources/views', ['Add it to your layout, just before </body>']);

        if ($features['design_grids'] ?? false) {
            $grids = $site->findInViews('/\{\{\s*dev_panel:grids\s*\}\}/');

            $grids
                ? $this->add('ok', 'Grids tag', implode(', ', $grids))
                : $this->add('fail', 'Grids tag', '{{ dev_panel:grids }} not found in resources/views', ['Add it to your layout, at the top of <body>, or set features.design_grids to false']);
        }

        if ($features['raw_data'] ?? false) {
            $uses = $site->findInViews('/\{\{\s*dev_panel:raw_data\b|\$store\.devPanel\??\.rawData/');

            $uses
                ? $this->add('ok', 'Raw data', 'used in '.count($uses).' '.(count($uses) === 1 ? 'place' : 'places'))
                : $this->add('info', 'Raw data', 'the toggle is on, but no template uses it', ['Wrap detail in {{ dev_panel:raw_data }} ... {{ /dev_panel:raw_data }}, or set features.raw_data to false']);
        }
    }

    protected function checkLeftovers(Inspector $site): void
    {
        $keys = $site->findInViews('/\b(showRawData|showDesignGrids|minimiseDevPanel)\b/');

        if ($keys) {
            $this->add('warn', 'Old Alpine keys', 'found in '.count($keys).' '.(count($keys) === 1 ? 'place' : 'places'), array_merge(
                array_slice($keys, 0, 8),
                count($keys) > 8 ? ['...and '.(count($keys) - 8).' more'] : [],
                ['Wrap raw data in {{ dev_panel:raw_data }} ... {{ /dev_panel:raw_data }}, and remove the dev keys from x-data']
            ));
        }

        $includes = $site->findInViews('/\{\{\s*partial:[^}]*(dev[_-]panel|design[_-]grids|raw[_-]data[_-]toggle|rwd[_-]debug)/');
        $files = $site->filesInViews('/^_?(dev[_-]panel|dev[_-]panel[_-]minimise|design[_-]grids(_toggle|-toggle)?|raw[_-]data[_-]toggle|rwd[_-]debug)\./');

        if ($includes || $files) {
            $this->add('warn', 'Old partials', 'the panel\'s old partials are still here', array_merge(
                array_map(fn ($line) => "Included at {$line}", $includes),
                array_map(fn ($file) => "Can be deleted: {$file}", $files),
            ));
        }
    }

    protected function checkCommit(Inspector $site, array $features): void
    {
        if (! ($features['commit'] ?? false)) {
            return;
        }

        if ($hash = config('statamic.git.commit_hash')) {
            $this->add('ok', 'Commit', $hash.(config('statamic.git.repo_url') ? '' : ' (no statamic.git.repo_url, so not linked)'));
        } else {
            $this->add('fail', 'Commit', 'statamic.git.commit_hash is empty, so the commit is hidden', [
                'Set it in config/statamic/git.php, for example:',
                "'commit_hash' => exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD'),",
                'Or set features.commit to false',
            ]);
        }

        if ($site->gitConfigRunsInCurrentDirectory()) {
            $this->add('warn', 'Commit config', 'config/statamic/git.php runs git without -C', [
                'A config:cache run from outside the site caches an empty hash.',
                "Use exec('git -C '.escapeshellarg(base_path()).' ...') instead",
            ]);
        }
    }

    protected function checkExtras(array $views): void
    {
        foreach ($views as $view) {
            View::exists($view)
                ? $this->add('ok', 'Extra', $view)
                : $this->add('fail', 'Extra', "{$view}: view not found", ['Check the name in the extra config, or create the view']);
        }
    }
}
