<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Les pages se rendent sans assets compilés : pas de `npm run build` avant les tests. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
