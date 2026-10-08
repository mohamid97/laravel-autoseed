<?php

namespace AutoSeed;

use Illuminate\Support\Facades\Schema;

class SchemaInspector
{
    public function __construct(private array $exclude = [])
    {
    }

    /** @return array<string, array{columns:array,pk:array,uniques:array,fks:array}> */
    public function inspect(): array
    {
        $tables = [];

        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            if ($this->excluded($name)) {
                continue;
            }

            $columns = collect(Schema::getColumns($name))->keyBy('name')->all();
            $pk = [];
            $uniques = [];

            foreach (Schema::getIndexes($name) as $index) {
                if ($index['primary']) {
                    $pk = $index['columns'];
                } elseif ($index['unique']) {
                    $uniques[] = $index['columns'];
                }
            }

            // composite primary key (pivot tables) must be unique too
            if (count($pk) > 1) {
                $uniques[] = $pk;
            }

            $fks = [];
            foreach (Schema::getForeignKeys($name) as $fk) {
                $fks[] = [
                    'columns'         => $fk['columns'],
                    'table'           => $fk['foreign_table'],
                    'foreign_columns' => $fk['foreign_columns'],
                ];
            }

            $tables[$name] = compact('columns', 'pk', 'uniques', 'fks');
        }

        return $tables;
    }

    /** Topological order: parents before children (cycles are broken arbitrarily). */
    public function order(array $tables): array
    {
        $remaining = [];
        foreach ($tables as $table => $meta) {
            $remaining[$table] = collect($meta['fks'])
                ->pluck('table')
                ->unique()
                ->filter(fn (string $parent) => $parent !== $table && isset($tables[$parent]))
                ->values()
                ->all();
        }

        $ordered = [];
        while ($remaining) {
            $ready = array_keys(array_filter(
                $remaining,
                fn (array $dependencies) => ! array_diff($dependencies, $ordered),
            ));

            if ($ready === []) {
                $ready = [array_key_first($remaining)]; // circular dependency
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($remaining[$table]);
            }
        }

        return $ordered;
    }

    private function excluded(string $name): bool
    {
        foreach ($this->exclude as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
