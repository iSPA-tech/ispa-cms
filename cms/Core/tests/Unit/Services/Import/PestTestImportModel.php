<?php

namespace Cms\Core\Tests\Unit\Services\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PestTestImportModel extends Model
{
    use SoftDeletes;

    protected $table = 'pest_import_models';

    protected $guarded = [];
}
