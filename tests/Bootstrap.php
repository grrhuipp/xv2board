<?php

namespace Tests;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

class Bootstrap implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements \PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber {
            public function notify(\PHPUnit\Event\TestRunner\ExecutionFinished $event): void
            {
                foreach (glob(dirname(__DIR__) . '/bootstrap/cache/*.phpunit.php') as $file) unlink($file);
            }
        });
    }
}
