<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable the panel
    |--------------------------------------------------------------------------
    |
    | The panel and design grids only ever render for logged-in users.
    | Set DEV_PANEL_ENABLED=false to hide them entirely.
    |
    */

    'enabled' => env('DEV_PANEL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Switch off any tool the site doesn't use.
    |
    | - commit: the current commit, read from config('statamic.git.commit_hash')
    |   and friends (see the README). Hidden if commit_hash isn't set.
    | - access: the value of the access field below, if the entry has one.
    | - breakpoints: the current breakpoint name and the viewport size.
    | - design_grids: a toggle for the {{ dev_panel:grids }} overlay.
    | - raw_data: a toggle for $store.devPanel.rawData, which site templates
    |   can use to show extra detail (see the README).
    | - edit_entry: a link to edit the current entry or term in the CP.
    | - back_to_top: a scroll-to-top button. Logged-in users only: the site's
    |   front end may have its own for visitors.
    |
    */

    'features' => [
        'commit' => true,
        'access' => true,
        'breakpoints' => true,
        'design_grids' => true,
        'raw_data' => true,
        'edit_entry' => true,
        'back_to_top' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Start minimised
    |--------------------------------------------------------------------------
    |
    | Whether the panel starts minimised. Each user's choice is then
    | remembered in their browser when the Alpine persist plugin is installed.
    |
    */

    'start_minimised' => true,

    /*
    |--------------------------------------------------------------------------
    | Access field
    |--------------------------------------------------------------------------
    |
    | The entry field shown by the access feature. Select-style fields show
    | their label.
    |
    */

    'access_field' => 'access',

    /*
    |--------------------------------------------------------------------------
    | Design grids
    |--------------------------------------------------------------------------
    |
    | Set container_class to the classes of the site's own grid container so
    | the overlay lines up with the layout. Those classes must already be used
    | in the site's templates, so its CSS build includes them. When it is null,
    | a plain full-width grid of the given number of columns is used.
    |
    */

    'grids' => [
        'columns' => 12,
        'container_class' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Breakpoints
    |--------------------------------------------------------------------------
    |
    | Name => min-width in pixels, matching the site's CSS. Below the smallest,
    | the readout shows "_". The defaults are Tailwind's.
    |
    */

    'breakpoints' => [
        'sm' => 640,
        'md' => 768,
        'lg' => 1024,
        'xl' => 1280,
        '2xl' => 1536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra partials
    |--------------------------------------------------------------------------
    |
    | Site views rendered inside the panel, for site-specific tools or
    | reminders, eg: ['partials/dev/todo_badge']. Each is rendered with the
    | current entry or term's data, at request time.
    |
    */

    'extra' => [],

];
