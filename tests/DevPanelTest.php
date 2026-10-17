<?php

namespace WeSort\DevPanel\Tests;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

class DevPanelTest extends TestCase
{
    protected string $viewPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewPath = sys_get_temp_dir().'/dev-panel-tests-'.Str::random(8);
        mkdir($this->viewPath);
        View::addLocation($this->viewPath);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->viewPath.'/*'));
        rmdir($this->viewPath);

        parent::tearDown();
    }

    /**
     * Render from a view file, as a site's layout does. Antlers::parse() on a
     * string is sandboxed and doesn't run tags.
     */
    protected function render(string $template, array $context = []): string
    {
        $name = 'test_'.Str::random(8);
        file_put_contents("{$this->viewPath}/{$name}.antlers.html", $template);

        return view($name, $context)->render();
    }

    protected function logIn(): void
    {
        $user = User::make()->email('dev@example.com')->makeSuper();
        $user->save();

        $this->actingAs($user);
    }

    #[Test]
    public function guests_get_only_the_comments()
    {
        $html = $this->render('{{ dev_panel }}{{ dev_panel:grids }}');

        $this->assertStringContainsString('<!-- Start dev-panel -->', $html);
        $this->assertStringContainsString('<!-- End design-grids -->', $html);
        $this->assertStringNotContainsString('devPanel', $html);
        $this->assertStringNotContainsString('<div', $html);
    }

    #[Test]
    public function logged_in_users_get_the_panel_and_grids()
    {
        $this->logIn();

        $html = $this->render('{{ dev_panel:grids }}{{ dev_panel }}');

        $this->assertStringContainsString('Expand dev panel', $html);
        $this->assertStringContainsString('aria-label="Show design grids"', $html);
        $this->assertStringContainsString('aria-label="Show raw data"', $html);
        $this->assertStringContainsString('Scroll to top', $html);
        $this->assertSame(12, substr_count($html, 'border-x-2'));
    }

    #[Test]
    public function the_store_script_is_output_once()
    {
        $this->logIn();

        $html = $this->render('{{ dev_panel:grids }}{{ dev_panel }}');

        $this->assertSame(1, substr_count($html, "Alpine.store('devPanel', {"));
    }

    #[Test]
    public function features_can_be_switched_off()
    {
        config([
            'statamic-dev-panel.features.raw_data' => false,
            'statamic-dev-panel.features.back_to_top' => false,
            'statamic-dev-panel.features.breakpoints' => false,
            'statamic-dev-panel.features.design_grids' => false,
        ]);

        $this->logIn();

        $html = $this->render('{{ dev_panel:grids }}{{ dev_panel }}');

        $this->assertStringNotContainsString('Show raw data', $html);
        $this->assertStringNotContainsString('Show design grids', $html);
        $this->assertStringNotContainsString('Scroll to top', $html);
        $this->assertStringNotContainsString('matchMedia', $html);
        $this->assertStringNotContainsString('<!-- Start design-grids -->', $html);
        $this->assertStringContainsString('Expand dev panel', $html);
    }

    #[Test]
    public function it_can_be_disabled_entirely()
    {
        config(['statamic-dev-panel.enabled' => false]);

        $this->logIn();

        $this->assertSame('', $this->render('{{ dev_panel }}{{ dev_panel:grids }}'));
    }

    #[Test]
    public function the_commit_comes_from_the_statamic_git_config()
    {
        config([
            'statamic.git.commit_hash' => 'abc1234',
            'statamic.git.repo_url' => 'https://github.com/example/site',
            'statamic.git.commit_message' => 'Fix "quotes" & things',
        ]);

        $this->logIn();

        $html = $this->render('{{ dev_panel }}');

        $this->assertStringContainsString('href="https://github.com/example/site/commit/abc1234"', $html);
        $this->assertStringContainsString('Fix &quot;quotes&quot; &amp; things', $html);
    }

    #[Test]
    public function the_commit_is_hidden_without_a_hash()
    {
        config(['statamic.git.commit_hash' => null]);

        $this->logIn();

        $this->assertStringNotContainsString('/commit/', $this->render('{{ dev_panel }}'));
    }

    #[Test]
    public function the_grids_use_the_configured_container_and_columns()
    {
        config([
            'statamic-dev-panel.grids.columns' => 6,
            'statamic-dev-panel.grids.container_class' => 'my-grid mx-auto',
        ]);

        $this->logIn();

        $html = $this->render('{{ dev_panel:grids }}');

        $this->assertStringContainsString('class="my-grid mx-auto"', $html);
        $this->assertSame(6, substr_count($html, 'border-x-2'));
    }

    #[Test]
    public function breakpoints_are_passed_in_ascending_order()
    {
        config(['statamic-dev-panel.breakpoints' => ['lg' => 1024, 'xs' => 400, 'md' => 768]]);

        $this->logIn();

        $html = $this->render('{{ dev_panel }}');

        $this->assertStringContainsString('{&quot;xs&quot;:400,&quot;md&quot;:768,&quot;lg&quot;:1024}', $html);
    }

    #[Test]
    public function raw_data_wraps_its_content_hidden_until_the_toggle_is_on()
    {
        $html = $this->render('{{ dev_panel:raw_data }}{{ title }}{{ /dev_panel:raw_data }}', ['title' => 'Hello']);

        $this->assertSame('<div x-data x-show="$store.devPanel?.rawData" style="display: none">Hello</div>', $html);
    }

    #[Test]
    public function raw_data_takes_an_element_and_attributes()
    {
        $html = $this->render('{{ dev_panel:raw_data as="span" class="text-red-700" aria-hidden="true" title="a \\"quote\\"" }}*{{ /dev_panel:raw_data }}');

        $this->assertSame('<span x-data x-show="$store.devPanel?.rawData" style="display: none" class="text-red-700" aria-hidden="true" title="a &quot;quote&quot;">*</span>', $html);
    }

    #[Test]
    public function raw_data_ignores_attributes_it_relies_on_and_unsafe_element_names()
    {
        $html = $this->render('{{ dev_panel:raw_data as="div onclick" style="color: red" x-show="true" }}x{{ /dev_panel:raw_data }}');

        $this->assertSame('<div x-data x-show="$store.devPanel?.rawData" style="display: none">x</div>', $html);
    }

    #[Test]
    public function raw_data_renders_nothing_when_the_feature_is_off()
    {
        config(['statamic-dev-panel.features.raw_data' => false]);

        $this->assertSame('', $this->render('{{ dev_panel:raw_data }}secret{{ /dev_panel:raw_data }}'));
    }

    #[Test]
    public function raw_data_works_inside_loops()
    {
        $html = $this->render('{{ items }}{{ dev_panel:raw_data as="span" }}{{ name }}{{ /dev_panel:raw_data }}{{ /items }}', ['items' => [['name' => 'a'], ['name' => 'b']]]);

        $this->assertSame(2, substr_count($html, '<span x-data'));
        $this->assertStringContainsString('>a</span>', $html);
        $this->assertStringContainsString('>b</span>', $html);
    }
}
