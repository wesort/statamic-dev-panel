# Release Notes

## 1.0.0 (2026-10-17)

### What's new
- `{{ dev_panel }}` and `{{ dev_panel:grids }}` tags: a front-end developer panel and a design grids overlay, shown only to logged-in users
- Panel tools: the current commit (from the `statamic.git.*` config keys), an entry's access field, a breakpoint and viewport readout, design grids and raw-data toggles, an edit link and back to top
- `config/statamic-dev-panel.php` to switch off any feature, start minimised or not, match the grid container classes and breakpoints, and set the access field
- `extra` config to render the site's own partials inside the panel, with the current entry or term's data
- An Alpine store, `$store.devPanel`, for the toggles. Site templates can use `$store.devPanel?.rawData`, and each user's choices are remembered when the persist plugin is installed.
- Safe with static caching: output goes in a nocache region that stores only the content ID, a few hundred bytes per region
- `php please dev-panel:install` publishes the config, adds the Tailwind 4 `@source` line after asking (or shows the Tailwind 3 `content` path) and lists the layout steps left
- `php please dev-panel:status` checks the setup, lists leftovers from a copied dev panel, shows the installed version against the latest release, and exits 1 when something needs fixing
- Supports Statamic 5 and 6, PHP 8.2+, Alpine.js 3, and Tailwind 3 or 4
