<?php

namespace AutoSeed\Console;

use AutoSeed\SchemaInspector;
use AutoSeed\Seeder;
use AutoSeed\ValueGenerator;
use Illuminate\Console\Command;

class AutoSeedCommand extends Command
{
    protected $signature = 'autoseed:run
        {--rows= : Default rows per table}
        {--table=* : Per-table override, e.g. --table=users:500 --table=order_items:x10}
        {--only=* : Seed only these tables}
        {--except=* : Skip these tables}
        {--multiplier=1 : Scale every table count}
        {--fresh : Truncate the tables first}
        {--seed= : Faker seed for reproducible data}
        {--force : Allow running in production}';

    protected $description = 'Fill every table with dummy data based on the live schema, columns and foreign keys';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to run in production. Use --force if you really mean it.');

            return self::FAILURE;
        }

        $config = config('autoseed') ?? [];
        $values = new ValueGenerator($config);

        if ($this->option('seed') !== null) {
            $values->seed((int) $this->option('seed'));
        }

        $seeder = new Seeder(new SchemaInspector($config['exclude'] ?? []), $values, $config);
        $seeder->run($this->seederOptions(), fn (string $line) => $this->line($line));

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return array{rows:?int,tables:array,only:array,except:array,multiplier:float,fresh:bool}
     */
    private function seederOptions(): array
    {
        return [
            'rows'       => $this->option('rows') !== null ? (int) $this->option('rows') : null,
            'tables'     => $this->parseTableOverrides($this->option('table')),
            'only'       => $this->option('only'),
            'except'     => $this->option('except'),
            'multiplier' => (float) $this->option('multiplier'),
            'fresh'      => (bool) $this->option('fresh'),
        ];
    }

    /**
     * @param  array<int,string>  $specs
     * @return array<string,string>
     */
    private function parseTableOverrides(array $specs): array
    {
        $overrides = [];

        foreach ($specs as $spec) {
            [$table, $count] = array_pad(explode(':', $spec, 2), 2, null);

            if ($table !== '' && $count !== null) {
                $overrides[$table] = $count;
            }
        }

        return $overrides;
    }
}
