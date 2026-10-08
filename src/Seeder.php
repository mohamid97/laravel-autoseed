<?php

namespace AutoSeed;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Seeder
{
    private array $pools = [];
    private ?array $morphTargets = null;

    public function __construct(
        private SchemaInspector $inspector,
        private ValueGenerator $values,
        private array $config,
    ) {
    }

    /**
     * @param array{rows?:?int,tables?:array,only?:array,except?:array,multiplier?:float,fresh?:bool} $options
     */
    public function run(array $options, callable $log): void
    {
        $tables = $this->inspector->inspect();
        $order = $this->filterTables($this->inspector->order($tables), $options);

        if (! empty($options['fresh'])) {
            $this->truncateTables($order);
        }

        foreach ($order as $table) {
            $count = $this->rowsFor($table, $options);
            $inserted = $this->seedTable($table, $tables[$table], $count, $log);
            $log(sprintf('%-35s %d rows', $table, $inserted));
        }
    }

    /**
     * @param  array<int,string>  $order
     * @param  array{only?:array,except?:array}  $options
     * @return array<int,string>
     */
    private function filterTables(array $order, array $options): array
    {
        if (! empty($options['only'])) {
            $order = array_values(array_filter(
                $order,
                fn (string $table) => in_array($table, $options['only'], true),
            ));
        }

        if (! empty($options['except'])) {
            $order = array_values(array_diff($order, $options['except']));
        }

        return $order;
    }

    /**
     * @param  array<int,string>  $order
     */
    private function truncateTables(array $order): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (array_reverse($order) as $table) {
            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();
    }

    private function rowsFor(string $table, array $o): int
    {
        $base = (int) ($o['rows'] ?? $this->config['default_rows']);
        $spec = $o['tables'][$table] ?? $this->config['tables'][$table] ?? null;

        $n = match (true) {
            $spec === null                                => $base,
            is_string($spec) && str_starts_with($spec, 'x') => $base * (float) substr($spec, 1),
            default                                       => (int) $spec,
        };

        return max(0, (int) round($n * ($o['multiplier'] ?? 1)));
    }

    private function seedTable(string $table, array $meta, int $count, callable $log): int
    {
        $cols = $meta['columns'];
        $fkByCol = [];
        foreach ($meta['fks'] as $i => $fk) {
            foreach ($fk['columns'] as $c) {
                $fkByCol[$c] = $i;
            }
        }

        // load parent pools (deep relations work because parents were seeded first)
        $pools = [];
        $selfFks = [];
        foreach ($meta['fks'] as $i => $fk) {
            $nullable = collect($fk['columns'])->every(fn ($c) => $cols[$c]['nullable']);

            if ($fk['table'] === $table) {
                if (! $nullable) {
                    $log("  ! skipped $table: non-nullable self-referencing FK");

                    return 0;
                }
                $selfFks[] = $fk;
                continue;
            }

            $pool = $this->pool($fk['table'], $fk['foreign_columns']);
            if (! $pool && ! $nullable) {
                $log("  ! skipped $table: parent {$fk['table']} has no rows");

                return 0;
            }
            $pools[$i] = $pool;
        }

        $morphs = $this->detectMorphs($cols, $fkByCol);

        $single = [];
        $multi = [];
        foreach ($meta['uniques'] as $set) {
            if (count($set) === 1 && ! isset($fkByCol[$set[0]]) && ! isset($morphs['ids'][$set[0]])) {
                $single[$set[0]] = true;
            } else {
                $multi[] = $set;
            }
        }

        $seen = [];
        $buffer = [];
        $inserted = 0;
        $chunk = $this->config['chunk_size'] ?? 500;

        for ($i = 0; $i < $count; $i++) {
            $row = null;

            for ($try = 0; $try < 30; $try++) {
                $row = $this->buildRow($cols, $meta, $fkByCol, $pools, $morphs, $single, $i);
                if (! $multi) {
                    break;
                }

                $keys = [];
                $ok = true;
                foreach ($multi as $k => $set) {
                    $key = md5(json_encode(array_map(fn ($c) => $row[$c] ?? null, $set)));
                    if (isset($seen[$k][$key])) {
                        $ok = false;
                        break;
                    }
                    $keys[$k] = $key;
                }
                if ($ok) {
                    foreach ($keys as $k => $key) {
                        $seen[$k][$key] = true;
                    }
                    break;
                }
                $row = null;
            }

            if ($row === null) {
                continue; // no unique combination left (e.g. pivot saturated)
            }

            $buffer[] = $row;
            if (count($buffer) >= $chunk) {
                $this->flushBuffer($table, $buffer, $inserted);
            }
        }

        if ($buffer !== []) {
            $this->flushBuffer($table, $buffer, $inserted);
        }

        // new rows invalidate cached pools of this table
        foreach (array_keys($this->pools) as $k) {
            if (str_starts_with($k, $table . '|')) {
                unset($this->pools[$k]);
            }
        }

        $pk = count($meta['pk']) === 1 ? $meta['pk'][0] : null;
        foreach ($selfFks as $fk) {
            if ($pk && count($fk['columns']) === 1 && $fk['foreign_columns'] === [$pk]) {
                $this->linkSelf($table, $pk, $fk['columns'][0]);
            }
        }

        return $inserted;
    }

    private function buildRow(array $cols, array $meta, array $fkByCol, array $pools, array $morphs, array $single, int $seq): array
    {
        $row = [];
        $done = [];

        foreach ($cols as $name => $c) {
            if (! empty($c['auto_increment']) || ! empty($c['generation']) || isset($done[$name])) {
                continue;
            }

            if (isset($fkByCol[$name])) {
                $this->assignForeignKeyValues($row, $done, $meta['fks'][$fkByCol[$name]], $pools[$fkByCol[$name]] ?? []);
                continue;
            }

            if (isset($morphs['types'][$name])) {
                $this->assignMorphValues($row, $done, $name, $morphs['types'][$name]);
                continue;
            }

            $isUnique = isset($single[$name]);
            $v = $this->values->make($c, $seq, ! $isUnique);
            $row[$name] = ($isUnique && $v !== null) ? $this->values->unique($v, $c, $seq) : $v;
        }

        return $row;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,bool>  $done
     * @param  array{columns:array,table:string,foreign_columns:array}  $fk
     * @param  array<int,array<string,mixed>>  $pool
     */
    private function assignForeignKeyValues(array &$row, array &$done, array $fk, array $pool): void
    {
        $pick = $pool !== [] ? $pool[array_rand($pool)] : null;

        foreach ($fk['columns'] as $k => $col) {
            $row[$col] = $pick === null ? null : $pick[$fk['foreign_columns'][$k]];
            $done[$col] = true;
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,bool>  $done
     */
    private function assignMorphValues(array &$row, array &$done, string $typeCol, string $idCol): void
    {
        $targets = $this->morphTargets();
        $alias = array_rand($targets);
        $pool = $this->pool($targets[$alias], ['id']);

        if ($pool !== []) {
            $row[$typeCol] = $alias;
            $row[$idCol] = $pool[array_rand($pool)]['id'];
        } else {
            $row[$typeCol] = null;
            $row[$idCol] = null;
        }

        $done[$typeCol] = true;
        $done[$idCol] = true;
    }

    private function flushBuffer(string $table, array &$buffer, int &$inserted): void
    {
        DB::table($table)->insert($buffer);
        $inserted += count($buffer);
        $buffer = [];
    }

    private function pool(string $table, array $columns): array
    {
        $key = $table . '|' . implode(',', $columns);

        return $this->pools[$key] ??= DB::table($table)
            ->select($columns)
            ->limit($this->config['pool_limit'] ?? 50000)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** Detect {name}_type + {name}_id polymorphic pairs (only when a morph map is available). */
    private function detectMorphs(array $cols, array $fkByCol): array
    {
        $out = ['types' => [], 'ids' => []];
        if (! $this->morphTargets()) {
            return $out;
        }

        foreach ($cols as $name => $c) {
            if (! str_ends_with($name, '_type') || isset($fkByCol[$name])) {
                continue;
            }
            $idCol = substr($name, 0, -5) . '_id';
            if (isset($cols[$idCol]) && ! isset($fkByCol[$idCol])) {
                $out['types'][$name] = $idCol;
                $out['ids'][$idCol] = true;
            }
        }

        return $out;
    }

    private function morphTargets(): array
    {
        if ($this->morphTargets !== null) {
            return $this->morphTargets;
        }

        $targets = $this->config['morph_map'] ?? [];
        foreach (Relation::morphMap() as $alias => $class) {
            if (is_a($class, Model::class, true)) {
                $targets[$alias] = (new $class)->getTable();
            }
        }

        return $this->morphTargets = $targets;
    }

    /** Give a share of rows in a self-referencing table (categories, comments...) a parent. */
    private function linkSelf(string $table, string $pk, string $col): void
    {
        $ids = DB::table($table)->pluck($pk)->all();
        if (count($ids) < 2) {
            return;
        }

        $pct = $this->config['self_ref_pct'] ?? 70;
        foreach ($ids as $id) {
            if (mt_rand(1, 100) > $pct) {
                continue;
            }
            do {
                $parent = $ids[array_rand($ids)];
            } while ($parent === $id);

            DB::table($table)->where($pk, $id)->update([$col => $parent]);
        }
    }
}
