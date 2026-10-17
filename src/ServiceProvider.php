<?php

namespace WeSort\DevPanel;

use Statamic\Providers\AddonServiceProvider;
use WeSort\DevPanel\Commands\Install;
use WeSort\DevPanel\Commands\Status;
use WeSort\DevPanel\Setup\Inspector;
use WeSort\DevPanel\Tags\DevPanel;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        DevPanel::class,
    ];

    protected $commands = [
        Install::class,
        Status::class,
    ];

    public function register()
    {
        parent::register();

        $this->app->bind(Inspector::class, fn () => new Inspector(base_path()));
    }

    public function bootAddon()
    {
        //
    }
}
