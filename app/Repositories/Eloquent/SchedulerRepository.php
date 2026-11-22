<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Interfaces\SchedulerInterface;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use App\Models\Scheduler;

class SchedulerRepository extends RepositoriesAbstract implements SchedulerInterface
{
    public function __construct() {
        $this->setModel(Scheduler::class);
    }
}
