# Dev Panel

> A Statamic addon that adds a small developer panel to the front end of a site, shown only to logged-in users.

The panel sits in the bottom-right corner of every page and can be minimised. It offers:

- **Commit**: the current commit hash, linked to the repo, with its date and message on hover
- **Access**: the value of an entry's access field (optional)
- **Breakpoints**: the current breakpoint name and the viewport size
- **Design grids**: a toggle for a column overlay that follows the site's grid
- **Raw data**: a toggle that site templates can use to reveal extra detail
- **Edit**: a link to edit the current entry or term in the Control Panel
- **Back to top**: a scroll-to-top button
- **Extras**: the site's own partials, for site-specific tools or reminders

Each tool can be switched off in config.

## Requirements

- Statamic 5.x or 6.x
- PHP 8.2+
- Alpine.js 3 on the front end, started from a module or deferred script (the usual Vite setup). The [persist plugin](https://alpinejs.dev/plugins/persist) is optional: with it, each user's toggles are remembered.
- Tailwind CSS 3 or 4 for the panel's styles

## Installation

Add the repository to your `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/wesort/statamic-dev-panel"
    }
]
```

Then install via Composer:

```bash
composer require wesort/statamic-dev-panel
```

### Tailwind

The panel uses Tailwind classes from the site's own build. Tailwind doesn't scan `vendor/` by default, so add the addon's views as a source and rebuild.

Tailwind 4, in your main CSS file (adjust the relative path to suit):

```css
@source "../../vendor/wesort/statamic-dev-panel/resources/views";
```

Tailwind 3, in `tailwind.config.js`:

```js
content: [
    // ...
    './vendor/wesort/statamic-dev-panel/resources/views/**/*.html',
],
```

### Templates

In your layout, put the grids at the top of `<body>` and the panel just before `</body>`:

```antlers
<body x-data>
    {{ dev_panel:grids }}

    ...

    {{ dev_panel }}
</body>
```

Both render nothing for visitors, apart from a `<!-- Start ... -->` and `<!-- End ... -->` comment that helps find them in the page source.

`<body>` (or another ancestor) needs `x-data` for any `x-show` in your own templates to work, as usual with Alpine.

### Raw data

The raw-data toggle sets `$store.devPanel.rawData`. Use it in your templates to show extra detail to logged-in users. Use optional chaining, as the store only exists for logged-in users:

```antlers
<div x-cloak x-show="$store.devPanel?.rawData">
    {{ last_modified format="Y-m-d" }}
</div>
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=statamic-dev-panel-config
```

This creates `config/statamic-dev-panel.php`, where you can:

- switch features on or off (`features`)
- choose whether the panel starts minimised (`start_minimised`)
- set the entry field shown by the access feature (`access_field`)
- set the design grids' column count and the classes of your grid container, so the overlay lines up with your layout (`grids`)
- set your breakpoints, as name => min-width in pixels (`breakpoints`)
- list your own partials to render inside the panel (`extra`)

Set `DEV_PANEL_ENABLED=false` in `.env` to hide the panel entirely.

### Commit

The commit tool reads these config keys, so it works however the site sets them. It is hidden if `statamic.git.commit_hash` is empty.

| Key | Used for |
|---|---|
| `statamic.git.commit_hash` | The hash shown |
| `statamic.git.repo_url` | Links the hash to `<repo_url>/commit/<hash>` |
| `statamic.git.commit_datetime` | Shown on hover |
| `statamic.git.commit_message` | Shown on hover |

For example, in `config/statamic/git.php`:

```php
'repo_url' => env('GIT_REPO_URL', null),
'commit_hash' => exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD'),
'commit_datetime' => exec('git -C '.escapeshellarg(base_path()).' log -1 --format=%cd --date=format:"%d %b %Y %H:%M"'),
'commit_message' => exec('git -C '.escapeshellarg(base_path()).' log -1 --format=%s'),
```

`git -C base_path()` matters: without it, git runs in whatever directory the command was started from, so a `config:cache` run from outside the site caches an empty hash.

These run whenever config loads without a config cache, so cache config in production (`php artisan config:cache` or `optimize`).

### Extra partials

Each view listed in `extra` is rendered inside the panel with the current entry or term's data, at request time. For example, a badge for entries that still hold some legacy field:

```php
'extra' => [
    'partials/dev/legacy_badge',
],
```

```antlers
{{ if legacy_field }}
    <div class="absolute top-0 right-0 rounded-full h-4 px-3 text-xs bg-red-500 text-white">Legacy</div>
{{ /if }}
```

## Static caching

The panel is safe with static caching. Both tags put their output in a nocache region, so a cached page never holds the panel, visitors are served the cached page, and logged-in users see the panel on top of it. Each region stores only the current content's ID: everything else is looked up when the region renders, so the regions stay a few hundred bytes each.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Licence

MIT. See [LICENSE](LICENSE).
