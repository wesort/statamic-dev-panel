<?php

namespace WeSort\DevPanel\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use WeSort\DevPanel\Setup\Inspector;

class Install extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:dev-panel:install';

    protected $description = 'Set up the Dev Panel addon: publish its config, add it to the Tailwind build, then check the rest.';

    public function handle(Inspector $site): int
    {
        $this->publishConfig();
        $rebuild = $this->addTailwindSource($site);
        $this->showTemplateSteps($site);

        if ($rebuild) {
            $this->output->newLine();
            $this->warn('Rebuild the front end so the panel is styled: npm run build');
        }

        $this->call('statamic:dev-panel:status');

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        if (file_exists(config_path('statamic-dev-panel.php'))) {
            $this->info('Config is already published at config/statamic-dev-panel.php.');

            return;
        }

        $this->callSilent('vendor:publish', ['--tag' => 'statamic-dev-panel-config']);
        $this->info('Published config/statamic-dev-panel.php: switch off any features the site doesn\'t use.');
    }

    /**
     * Returns whether the front end needs rebuilding.
     */
    protected function addTailwindSource(Inspector $site): bool
    {
        if ($source = $site->tailwindSourceFile()) {
            $this->info('Tailwind already includes the addon\'s views ('.$site->relative($source).').');

            return false;
        }

        $major = $site->npmMajor('tailwindcss');

        if (! $major) {
            $this->warn('Tailwind wasn\'t found in package.json. The panel is styled with the site\'s Tailwind build (3 or 4).');

            return false;
        }

        if ($major < 4) {
            $config = $site->tailwindConfigFile();
            $this->warn('Tailwind '.$major.': add this to content in '.($config ? $site->relative($config) : 'tailwind.config.js').':');
            $this->line("    './".Inspector::VIEWS_PATH."/**/*.html',");

            return true;
        }

        $files = $site->tailwindCssFiles();

        if (! $files) {
            $this->warn('No CSS file under resources/css imports Tailwind. Add an @source line for '.Inspector::VIEWS_PATH.' to your main CSS file.');

            return false;
        }

        $file = count($files) === 1
            ? $files[0]
            : $site->base().'/'.$this->choice('Which CSS file is the main one?', array_map(fn ($f) => $site->relative($f), $files), 0);

        $line = $site->sourceLine($file);

        if (! $this->confirm('Add '.$line.' to '.$site->relative($file).'?', true)) {
            $this->line('Skipped. Add it yourself when ready.');

            return false;
        }

        file_put_contents($file, $site->withSourceLine(file_get_contents($file), $line));
        $this->info('Added '.$line.' to '.$site->relative($file).'.');

        return true;
    }

    protected function showTemplateSteps(Inspector $site): void
    {
        $hasPanel = (bool) $site->findInViews('/\{\{\s*dev_panel\s*\}\}/');
        $hasGrids = (bool) $site->findInViews('/\{\{\s*dev_panel:grids\s*\}\}/');

        if ($hasPanel && $hasGrids) {
            return;
        }

        $this->output->newLine();
        $this->line('<options=bold>In your layout</> (templates are left for you to edit):');
        $this->output->newLine();
        $this->line('    <body x-data>');
        $hasGrids || $this->line('        {{ dev_panel:grids }}');
        $this->line('        ...');
        $hasPanel || $this->line('        {{ dev_panel }}');
        $this->line('    </body>');
        $this->output->newLine();
        $this->line('Show extra detail while the raw-data toggle is on with {{ dev_panel:raw_data }} ... {{ /dev_panel:raw_data }}.');
    }
}
