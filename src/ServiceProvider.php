<?php

namespace WeSort\DevPanel;

use Statamic\Providers\AddonServiceProvider;
use WeSort\DevPanel\Tags\DevPanel;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        DevPanel::class,
    ];

    public function bootAddon()
    {
        //
    }
}
