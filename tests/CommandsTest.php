<?php

namespace WeSort\DevPanel\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use WeSort\DevPanel\Setup\Inspector;

class CommandsTest extends TestCase
{
    protected string $site;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway site the commands inspect, set up as one would be before installing
        $this->site = sys_get_temp_dir().'/dev-panel-site-'.Str::random(8);

        $this->writeFile('package.json', json_encode(['devDependencies' => ['tailwindcss' => '^4.1.0'], 'dependencies' => ['alpinejs' => '^3.14.9']]));
        $this->writeFile('resources/css/site.css', "@import \"tailwindcss\";\n@source \"../views\";\n\n.thing { color: red; }\n");
        $this->writeFile('resources/views/layout.antlers.html', "<body x-data=\"{ showRawData: \$persist(false) }\">\n{{ partial:utils/_dev_panel }}\n</body>\n");
        $this->writeFile('resources/views/utils/_dev_panel.antlers.html', 'old');
        $this->writeFile('config/statamic/git.php', "<?php return ['commit_hash' => exec('git rev-parse --short HEAD')];");

        $this->app->instance(Inspector::class, new Inspector($this->site));

        config(['statamic.git.commit_hash' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->site);
        File::delete(config_path('statamic-dev-panel.php'));

        parent::tearDown();
    }

    protected function writeFile(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname("{$this->site}/{$path}"));
        file_put_contents("{$this->site}/{$path}", $contents);
    }

    #[Test]
    public function status_lists_what_a_new_site_still_needs()
    {
        $this->artisan('statamic:dev-panel:status')
            ->expectsOutputToContain('Tailwind source')
            ->expectsOutputToContain('Add to resources/css/site.css: @source "../../vendor/wesort/statamic-dev-panel/resources/views";')
            ->expectsOutputToContain('{{ dev_panel }} not found in resources/views')
            ->expectsOutputToContain('resources/views/layout.antlers.html:1')
            ->expectsOutputToContain('Can be deleted: resources/views/utils/_dev_panel.antlers.html')
            ->expectsOutputToContain('statamic.git.commit_hash is empty')
            ->expectsOutputToContain('runs git without -C')
            ->assertExitCode(1);
    }

    #[Test]
    public function status_passes_on_a_site_that_is_set_up()
    {
        config(['statamic.git.commit_hash' => 'abc1234']);

        $this->writeFile('resources/css/site.css', "@import \"tailwindcss\";\n@source \"../../vendor/wesort/statamic-dev-panel/resources/views\";\n");
        $this->writeFile('resources/views/layout.antlers.html', "<body x-data>\n{{ dev_panel:grids }}\n<p x-show=\"\$store.devPanel?.rawData\">x</p>\n{{ dev_panel }}\n</body>\n");
        File::delete("{$this->site}/resources/views/utils/_dev_panel.antlers.html");
        $this->writeFile('config/statamic/git.php', "<?php return ['commit_hash' => exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD')];");

        $this->artisan('statamic:dev-panel:status')
            ->expectsOutputToContain('All set.')
            ->assertExitCode(0);
    }

    #[Test]
    public function install_publishes_config_and_adds_the_tailwind_source_after_the_last_one()
    {
        $this->artisan('statamic:dev-panel:install')
            ->expectsConfirmation('Add @source "../../vendor/wesort/statamic-dev-panel/resources/views"; to resources/css/site.css?', 'yes')
            ->expectsOutputToContain('npm run build');

        $this->assertFileExists(config_path('statamic-dev-panel.php'));
        $this->assertSame(
            "@import \"tailwindcss\";\n@source \"../views\";\n@source \"../../vendor/wesort/statamic-dev-panel/resources/views\";\n\n.thing { color: red; }\n",
            file_get_contents("{$this->site}/resources/css/site.css")
        );
    }

    #[Test]
    public function install_leaves_the_css_alone_when_declined_and_never_edits_templates()
    {
        $layout = file_get_contents("{$this->site}/resources/views/layout.antlers.html");

        $this->artisan('statamic:dev-panel:install')
            ->expectsConfirmation('Add @source "../../vendor/wesort/statamic-dev-panel/resources/views"; to resources/css/site.css?', 'no')
            ->expectsOutputToContain('{{ dev_panel }}');

        $this->assertStringNotContainsString('statamic-dev-panel', file_get_contents("{$this->site}/resources/css/site.css"));
        $this->assertSame($layout, file_get_contents("{$this->site}/resources/views/layout.antlers.html"));
    }

    #[Test]
    public function install_is_safe_to_run_again()
    {
        $this->writeFile('resources/css/site.css', "@import \"tailwindcss\";\n@source \"../../vendor/wesort/statamic-dev-panel/resources/views\";\n");

        $this->artisan('statamic:dev-panel:install')
            ->expectsOutputToContain('Tailwind already includes the addon\'s views');

        $this->assertSame(1, substr_count(file_get_contents("{$this->site}/resources/css/site.css"), 'statamic-dev-panel'));
    }

    #[Test]
    public function tailwind_3_sites_are_told_where_to_add_the_content_path()
    {
        $this->writeFile('package.json', json_encode(['devDependencies' => ['tailwindcss' => '^3.4.3', 'alpinejs' => '^3.14.9']]));
        $this->writeFile('tailwind.config.js', "module.exports = { content: ['./resources/**/*.antlers.html'] }\n");

        $this->artisan('statamic:dev-panel:install')
            ->expectsOutputToContain('add this to content in tailwind.config.js')
            ->expectsOutputToContain("'./vendor/wesort/statamic-dev-panel/resources/views/**/*.html',");

        $this->assertStringNotContainsString('statamic-dev-panel', file_get_contents("{$this->site}/tailwind.config.js"));
    }
}
