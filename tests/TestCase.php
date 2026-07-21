<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Indicates if the default seeding trait should be used in feature tests.
     *
     * @var bool
     */
    protected $seed = false;
}
