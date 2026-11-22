<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Schema;

use Illuminate\Database\Eloquent\Model;
use Sirius\LaravelBuilder\Definitions\ForeignKeyDefinition;
use Sirius\LaravelBuilder\Definitions\IndexDefinition;
use Sirius\LaravelBuilder\Definitions\TableDefinition;
use Throwable;

final readonly class SchemaInspector
{
    public function __construct(private ColumnNormalizer $normalizer) {}

    public function inspect(Model $model, ?string $table = null): TableDefinition
    {
        $resolved = $model->getTable();
        if ($table !== null && $table !== $resolved) {
            throw new SchemaInspectionException('The selected table differs from Model::getTable(); correct the selection or model configuration before generation.');
        }
        if (trim($resolved) === '' || str_contains($resolved, "\0") || count(explode('.', $resolved)) > 2 || in_array('', explode('.', $resolved), true)) {
            throw new SchemaInspectionException('Use a nonempty table or schema.table reference.');
        }
        try {
            $connection = $model->getConnection();
            $driver = $connection->getDriverName();
            if (!in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
                throw new SchemaInspectionException('Unsupported schema driver: ' . $driver);
            }
            $schema = $connection->getSchemaBuilder();
            $raw = $schema->getColumns($resolved);
            if ($raw === []) {
                throw new SchemaInspectionException('The selected table is missing or has no visible columns. Check the model table, connection, and metadata permissions.');
            }
            $indexes = array_map(static fn (array $index): IndexDefinition => new IndexDefinition($index['name'], $index['columns'], $index['unique'], $index['primary'], $index['type']), $schema->getIndexes($resolved));
            $primary = array_values(array_filter($indexes, static fn (IndexDefinition $index): bool => $index->primary));
            if (count($primary) !== 1 || count($primary[0]->columns) !== 1) {
                throw new SchemaInspectionException('CRUD requires a single-column primary key; missing or composite primary keys are unsupported.');
            }
            $key = $primary[0]->columns[0];
            if ($model->getKeyName() !== $key) {
                throw new SchemaInspectionException('The model key differs from the database primary key; configure the model primaryKey before generation.');
            }
            $foreignKeys = array_map(static fn (array $key): ForeignKeyDefinition => new ForeignKeyDefinition($key['name'], $key['columns'], $key['foreign_schema'], $key['foreign_table'], $key['foreign_columns'], $key['on_update'], $key['on_delete']), $schema->getForeignKeys($resolved));
            $enums = [];
            $identities = [];
            if ($driver === 'pgsql') {
                // Bind a grammar-quoted relation name: no identifiers are concatenated into SQL.
                [$namespace, $name] = $schema->parseSchemaAndTable($resolved, true);
                $relation = $connection->getQueryGrammar()->wrapTable($namespace . '.' . $name);
                $attributes = $connection->selectFromWriteConnection(
                    'select a.attname, a.attidentity, e.enumlabel from pg_catalog.pg_attribute a left join pg_catalog.pg_enum e on e.enumtypid = a.atttypid where a.attrelid = pg_catalog.to_regclass(?) and a.attnum > 0 and not a.attisdropped order by a.attnum, e.enumsortorder',
                    [$relation],
                );
                foreach ($attributes as $attribute) {
                    $identities[$attribute->attname] = $attribute->attidentity !== '';
                    if ($attribute->enumlabel !== null) {
                        $enums[$attribute->attname][] = $attribute->enumlabel;
                    }
                }
            }
            $columns = [];
            $warnings = ['Index column lists do not fully describe partial, expression, or prefix indexes. Do not infer unconditional uniqueness from these alone.', 'Defaults retain database expressions; null does not distinguish an absent default from DEFAULT NULL.'];
            foreach ($raw as $column) {
                $normalized = $this->normalizer->normalize($column, $enums[$column['name']] ?? null, $identities[$column['name']] ?? false);
                $columns[] = $normalized;
                if ($normalized->type === 'unknown') {
                    $warnings[] = 'Unsupported or ambiguous type for column ' . $normalized->name . ': ' . $normalized->nativeType;
                }
            }
            if ($driver === 'sqlite') {
                $warnings[] = 'SQLite type affinity and CHECK constraints do not reliably expose enum values or enforce declared lengths/decimal precision.';
            }

            return new TableDefinition($connection->getName(), $driver, $resolved, $key, $columns, $indexes, $foreignKeys, $warnings);
        } catch (SchemaInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Connection exceptions can contain credentials or DSNs; never expose their messages.
            throw new SchemaInspectionException('Schema inspection failed. Check the selected connection, driver support, table reference, and metadata permissions.');
        }
    }
}
