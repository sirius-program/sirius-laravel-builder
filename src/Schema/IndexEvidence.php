<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Schema;

use Illuminate\Database\Connection;

final class IndexEvidence
{
    /** @return array<string, bool> */
    public function inspect(Connection $connection, string $table): array
    {
        $schema = $connection->getSchemaBuilder();
        [$namespace, $name] = $schema->parseSchemaAndTable($table);
        $physical = $connection->getTablePrefix() . $name;
        $driver = $connection->getDriverName();
        $result = [];
        if ($driver === 'pgsql') {
            [$namespace, $name] = $schema->parseSchemaAndTable($table, true);
            $relation = $connection->getQueryGrammar()->wrapTable($namespace . '.' . $name);
            foreach ($connection->selectFromWriteConnection('select c.relname as name, (i.indpred is null and i.indexprs is null and i.indnkeyatts = i.indnatts) as complete from pg_catalog.pg_index i join pg_catalog.pg_class c on c.oid = i.indexrelid where i.indrelid = pg_catalog.to_regclass(?)', [$relation]) as $row) {
                $result[strtolower($row->name)] = (bool) $row->complete;
            }
        } elseif ($driver === 'sqlite') {
            foreach ($connection->selectFromWriteConnection('select name, partial from pragma_index_list(?, ?)', [$physical, $namespace ?? 'main']) as $row) {
                $complete = !(bool) $row->partial;
                foreach ($connection->selectFromWriteConnection('select cid, key from pragma_index_xinfo(?, ?)', [$row->name, $namespace ?? 'main']) as $part) {
                    if ($part->key && $part->cid < 0) {
                        $complete = false;
                    }
                }
                $result[strtolower($row->name)] = $complete;
            }
        } else {
            foreach ($connection->selectFromWriteConnection('select index_name as index_name, column_name as column_name, sub_part as sub_part from information_schema.statistics where table_schema = ? and table_name = ?', [$namespace ?? $connection->getDatabaseName(), $physical]) as $row) {
                $name = strtolower($row->index_name);
                $result[$name] = ($result[$name] ?? true) && $row->column_name !== null && $row->sub_part === null;
            }
        }

        return $result;
    }
}
