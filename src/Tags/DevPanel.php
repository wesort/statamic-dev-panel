<?php

namespace WeSort\DevPanel\Tags;

use Statamic\Facades\Data;
use Statamic\Facades\User;
use Statamic\StaticCaching\Middleware\Cache;
use Statamic\StaticCaching\NoCache\Session;
use Statamic\Tags\Tags;

class DevPanel extends Tags
{
    protected static $handle = 'dev_panel';

    /**
     * {{ dev_panel }}: the panel, placed before </body>.
     */
    public function index()
    {
        return $this->region('panel', 'dev-panel');
    }

    /**
     * {{ dev_panel:grids }}: the design grids overlay, placed at the top of <body>.
     */
    public function grids()
    {
        if (! config('statamic-dev-panel.features.design_grids')) {
            return '';
        }

        return $this->region('grids', 'design-grids');
    }

    /**
     * {{ dev_panel:raw_data }} ... {{ /dev_panel:raw_data }}: content shown only
     * while the panel's raw-data toggle is on.
     *
     * Hidden with an inline style rather than x-cloak, so it stays hidden
     * without any CSS or before Alpine starts. It is hidden, not secret: the
     * content is in the page for everyone. Renders nothing when the raw_data
     * feature is off.
     *
     * Parameters: `as` sets the element (default div). Any others, such as
     * class or aria-hidden, become attributes on it.
     */
    public function rawData()
    {
        if (! config('statamic-dev-panel.enabled') || ! config('statamic-dev-panel.features.raw_data')) {
            return '';
        }

        $element = strtolower((string) $this->params->get('as', 'div'));
        $element = preg_match('/^[a-z][a-z0-9-]*$/', $element) ? $element : 'div';

        $attributes = collect($this->params->except(['as'])->all())
            ->reject(fn ($value, $name) => ! preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.-]*$/', $name) || in_array(strtolower($name), ['style', 'x-data', 'x-show']))
            // Statamic casts "true" and "false" to booleans: write them back as
            // strings, as attributes like aria-hidden need the value
            ->map(fn ($value, $name) => ' '.$name.'="'.e(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value).'"')
            ->implode('');

        return "<{$element} x-data x-show=\"\$store.devPanel?.rawData\" style=\"display: none\"{$attributes}>"
            .$this->parse()
            ."</{$element}>";
    }

    /**
     * Renders a view from inside a nocache region, at request time.
     * Used by index() and grids() rather than in templates.
     */
    public function render()
    {
        $view = $this->params->get('view') === 'grids' ? 'grids' : 'panel';

        return $this->renderView($view, $this->context->value('dev_panel_id'));
    }

    /**
     * Put the view in a nocache region, as {{ nocache select="..." }} does, so a
     * statically cached page never holds the panel and logged-in users still see
     * it. The region stores only the content ID; everything else is looked up
     * when it renders. Without static caching on the route, render it directly.
     */
    protected function region(string $view, string $name): string
    {
        if (! config('statamic-dev-panel.enabled')) {
            return '';
        }

        $id = $this->context->value('id');
        $id = is_string($id) ? $id : null;

        $html = Cache::isBeingUsedOnCurrentRoute()
            ? app(Session::class)
                ->pushRegion('{{ dev_panel:render view="'.$view.'" }}', ['dev_panel_id' => $id], 'antlers.html')
                ->placeholder()
            : $this->renderView($view, $id);

        return "<!-- Start {$name} -->\n{$html}\n<!-- End {$name} -->";
    }

    protected function renderView(string $view, ?string $id): string
    {
        if (! config('statamic-dev-panel.enabled') || ! User::current()) {
            return '';
        }

        $content = $id ? Data::find($id) : null;

        return view('statamic-dev-panel::'.$view, $this->viewData($content))->render();
    }

    protected function viewData($content): array
    {
        $config = config('statamic-dev-panel');
        $features = $config['features'] ?? [];

        $breakpoints = collect($config['breakpoints'] ?? [])->sort()->all();

        $startMinimised = (bool) ($config['start_minimised'] ?? true);

        return [
            'features' => $features,
            'store_script' => $this->storeScript($startMinimised),
            'toggles' => $this->toggles($features),
            'commit' => ($features['commit'] ?? false) ? $this->commit() : null,
            'access' => ($features['access'] ?? false) ? $this->access($content, $config['access_field'] ?? 'access') : null,
            'edit_url' => ($features['edit_entry'] ?? false) && $content && method_exists($content, 'editUrl') ? $content->editUrl() : null,
            'breakpoints_json' => json_encode((object) $breakpoints),
            'grid_columns' => (int) ($config['grids']['columns'] ?? 12),
            'grid_container_class' => $config['grids']['container_class'] ?? null,
            'extra' => $this->extra($content, $config['extra'] ?? []),
        ];
    }

    protected function storeScript(bool $startMinimised): string
    {
        // Once per request, as the panel and the grids both need the store
        if (request()->attributes->get('dev_panel_store_rendered')) {
            return '';
        }

        request()->attributes->set('dev_panel_store_rendered', true);

        return view('statamic-dev-panel::store', ['start_minimised' => $startMinimised])->render();
    }

    protected function toggles(array $features): array
    {
        return collect([
            'design_grids' => [
                'store_key' => 'grids',
                'label' => 'Show design grids',
                'icon_path' => 'M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125z',
            ],
            'raw_data' => [
                'store_key' => 'rawData',
                'label' => 'Show raw data',
                'icon_path' => 'M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4',
            ],
        ])->filter(fn ($toggle, $feature) => $features[$feature] ?? false)->values()->all();
    }

    protected function commit(): ?array
    {
        $hash = config('statamic.git.commit_hash');

        if (! $hash) {
            return null;
        }

        $repo = rtrim((string) config('statamic.git.repo_url'), '/');

        return [
            'hash' => $hash,
            'url' => $repo ? "{$repo}/commit/{$hash}" : null,
            'datetime' => config('statamic.git.commit_datetime'),
            'message' => config('statamic.git.commit_message'),
        ];
    }

    protected function access($content, string $field): ?string
    {
        if (! $content || ! method_exists($content, 'augmentedValue')) {
            return null;
        }

        $value = $content->augmentedValue($field)->value();

        if (is_object($value) && method_exists($value, 'label')) {
            $value = $value->label();
        }

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    protected function extra($content, array $views): string
    {
        if (! $views) {
            return '';
        }

        $data = $content && method_exists($content, 'toAugmentedArray') ? $content->toAugmentedArray() : [];

        return collect($views)->map(fn ($view) => view($view, $data)->render())->implode("\n");
    }
}
