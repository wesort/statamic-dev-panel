<?php

namespace WeSort\DevPanel\Tests;

use Statamic\Testing\AddonTestCase;
use WeSort\DevPanel\ServiceProvider;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
