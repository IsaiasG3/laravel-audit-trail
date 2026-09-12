<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

uses(
    TestCase::class,
    DatabaseMigrations::class
)->in('Feature');