<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

use InvalidArgumentException;

final readonly class ResourceDefinition
{
    /**
     * @param  list<FieldDefinition>  $fields
     * @param  list<ArtifactDefinition>  $artifacts
     */
    public function __construct(
        public ModelDefinition $model,
        public ResourceIdentity $identity,
        public OperationSelection $operations,
        public Layout $layout = Layout::Pages,
        public PolicyDefinition $policy = new PolicyDefinition,
        public array $fields = [],
        public array $artifacts = [],
    ) {
        new OperationSelection($model->softDeletes, $operations->selected);
        $columns = array_map(static fn (FieldDefinition $field): string => $field->column, $fields);
        if (count($columns) !== count(array_unique($columns))) {
            throw new InvalidArgumentException('Resource fields must have unique column names.');
        }
    }
}
