<?php

namespace Tests\Unit\Models;

use Cms\Core\Models\Language;
use Cms\Core\Models\MetaClass;
use Cms\Core\Models\Upload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class ObserverBootCompatibilityTest extends TestCase
{
    public function test_observers_register_once_after_boot_including_models_with_a_booted_hook(): void
    {
        $originalDispatcher = Model::getEventDispatcher();
        $dispatcher = new Dispatcher();
        Model::clearBootedModels();
        Model::setEventDispatcher($dispatcher);

        try {
            foreach ([Language::class, Upload::class, MetaClass::class] as $modelClass) {
                new $modelClass();
                $event = 'eloquent.created: '.$modelClass;
                $this->assertCount(1, $dispatcher->getListeners($event), $modelClass);
                new $modelClass();
                $this->assertCount(1, $dispatcher->getListeners($event), $modelClass);
            }

            $this->assertTrue($dispatcher->hasListeners('eloquent.retrieved: '.Upload::class));
        } finally {
            Model::clearBootedModels();
            if ($originalDispatcher !== null) {
                Model::setEventDispatcher($originalDispatcher);
            } else {
                Model::unsetEventDispatcher();
            }
        }
    }
}
