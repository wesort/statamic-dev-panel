<?php

namespace WeSort\DevPanel\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use WeSort\DevPanel\Setup\Inspector;
use WeSort\DevPanel\Setup\Releases;

class CommandsTest extends TestCase
{
    protected string $site;

    // What the faked GitHub API returns
    protected array $tags = [];

    protected string $main = '';

    protected int $githubStatus = 200;

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

        $this->writeFile('composer.json', json_encode(['require' => ['wesort/statamic-dev-panel' => '^1.0']]));

        $this->app->instance(Inspector::class, new Inspector($this->site));

        // On main and up to date, with no releases, unless a test says otherwise
        $this->installed('dev-main', str_repeat('a', 40));
        $this->github([], str_repeat('a', 40));

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->githubStatus !== 200) {
                return Http::response([], $this->githubStatus);
            }

            return str_contains($request->url(), '/tags')
                ? Http::response(array_map(fn ($tag) => ['name' => $tag], $this->tags))
                : Http::response(['sha' => $this->main]);
        });

        config(['statamic.git.commit_hash' => null]);
    }

    protected function installed(string $version, ?string $reference = null): void
    {
        $this->app->instance(Releases::class, new class($version, $reference) extends Releases
        {
            public function __construct(private string $version, private ?string $reference) {}

            public function installed(): array
            {
                return ['version' => $this->version, 'reference' => $this->reference];
            }
        });
    }

    protected function github(array $tags, string $main): void
    {
        $this->tags = $tags;
        $this->main = $main;
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

    #[Test]
    public function status_shows_a_branch_install_that_is_up_to_date()
    {
        $this->artisan('statamic:dev-panel:status')
            ->expectsOutputToContain('dev-main (aaaaaaa), up to date with main');
    }

    #[Test]
    public function status_shows_newer_commits_on_main_and_a_release_to_switch_to()
    {
        $this->github(['v1.0.0', 'v0.9.0'], str_repeat('b', 40));

        $this->artisan('statamic:dev-panel:status')
            ->expectsOutputToContain('main has newer commits: composer update wesort/statamic-dev-panel')
            ->expectsOutputToContain('v1.0.0 is released: require "wesort/statamic-dev-panel": "^1.0" instead of a branch');
    }

    #[Test]
    public function status_shows_the_latest_release_is_installed()
    {
        $this->installed('v1.2.0');
        $this->github(['v1.2.0', 'v1.10.0-beta', 'v1.1.0'], 'x');

        $this->artisan('statamic:dev-panel:status')
            ->expectsOutputToContain('v1.2.0, the latest release');
    }

    #[Test]
    public function status_shows_an_update_within_the_constraint()
    {
        $this->installed('v1.0.0');
        $this->github(['v1.0.0', 'v1.10.0', 'v1.2.0'], 'x');

        $this->artisan('statamic:dev-panel:status')
            ->expectsOutputToContain('v1.0.0, v1.10.0 is available')
            ->expectsOutputToContain('Update: composer update wesort/statamic-dev-panel')
            ->expectsOutputToContain('https://github.com/wesort/statamic-dev-panel/releases/tag/v1.10.0');
    }

    #[Test]
    public function status_says_when_an_update_needs_a_constraint_change()
    {
        $this->installed('v1.4.0');
        $this->github(['v2.0.0', 'v1.4.0'], 'x');

        $this->artisan('statamic:dev-panel:status')
            ->expectsOutputToContain('v2.0.0 is outside composer.json\'s "^1.0": read the release notes first')
            ->expectsOutputToContain('Then: composer require wesort/statamic-dev-panel:^2.0');
    }

    #[Test]
    public function status_carries_on_when_github_cannot_be_reached()
    {
        $this->githubStatus = 503;

        $this->writeFile('resources/css/site.css', "@import \"tailwindcss\";\n@source \"../../vendor/wesort/statamic-dev-panel/resources/views\";\n");

        $this->artisan('statamic:dev-panel:status')
            ->expectsOutputToContain('couldn\'t check for updates: GitHub returned 503')
            ->expectsOutputToContain('Tailwind source');
    }

    #[Test]
    public function status_can_skip_the_update_check()
    {
        $this->artisan('statamic:dev-panel:status', ['--offline' => true])
            ->expectsOutputToContain('not checked for updates (--offline)');

        Http::assertNothingSent();
    }

    #[Test]
    public function status_finds_old_alpine_keys_in_the_sites_scripts_but_not_node_modules()
    {
        $this->writeFile('resources/js/site.js', "import Alpine from 'alpinejs'\nAlpine.store('ui', { showRawData: false })\n");
        $this->writeFile('resources/js/components/Thing.vue', "<template><div v-if=\"minimiseDevPanel\" /></template>\n");
        $this->writeFile('resources/js/notes.txt', 'showDesignGrids');
        $this->writeFile('node_modules/some-package/index.js', 'showRawData');

        $this->artisan('statamic:dev-panel:status')
            ->expectsOutputToContain('resources/js/site.js:2')
            ->expectsOutputToContain('resources/js/components/Thing.vue:1')
            ->doesntExpectOutputToContain('notes.txt')
            ->doesntExpectOutputToContain('node_modules');
    }
}
