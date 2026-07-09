<?php

namespace WiserWebSolutions\LaravelPalegis\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \WiserWebSolutions\LaravelPalegis\LaravelPalegis
 */
class LaravelPalegis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \WiserWebSolutions\LaravelPalegis\LaravelPalegis::class;
    }
}
