<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources\Pipeline;

/**
 * One stage of the schema build pipeline (spec 6.6).
 *
 * A stage receives the schema built so far and returns the schema it hands to the next stage. Shared state between
 * stages (the fields known so far) lives on the {@see SchemaContext}.
 */
interface SchemaStage
{
    /**
     * Apply the stage.
     *
     * @param  array<string, mixed>  $schema  schema produced by the previous stage (empty for the first stage)
     * @return array<string, mixed>
     */
    public function apply(array $schema, SchemaContext $context): array;
}
