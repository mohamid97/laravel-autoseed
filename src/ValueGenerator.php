<?php

namespace AutoSeed;

use Faker\Factory;
use Faker\Generator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ValueGenerator
{
    private Generator $f;
    private ?string $hashed = null;
    private string $run;
    private int $offset;

    public function __construct(private array $config)
    {
        $this->f = Factory::create($config['locale'] ?? 'en_US');
        $this->run = strtolower(Str::random(4));
        $this->offset = random_int(1000, 5_000_000);
    }

    public function seed(int $seed): void
    {
        $this->f->seed($seed);
    }

    public function faker(): Generator
    {
        return $this->f;
    }

    public function make(array $c, int $seq, bool $allowNull = true): mixed
    {
        $name = strtolower($c['name']);

        if ($name === 'deleted_at' && $c['nullable']) {
            return null;
        }
        if ($allowNull && $c['nullable'] && mt_rand(1, 100) <= ($this->config['null_chance'] ?? 10)) {
            return null;
        }

        $full = strtolower($c['type']);
        $len = preg_match('/\((\d+)\)/', $full, $m) ? (int) $m[1] : null;

        return match ($this->kind($c)) {
            'bool'     => $this->f->boolean(),
            'int'      => $this->int($name, strtolower($c['type_name'])),
            'decimal'  => $this->decimal($name, $full),
            'datetime' => $this->date($name, 'Y-m-d H:i:s'),
            'date'     => $this->date($name, 'Y-m-d'),
            'time'     => $this->f->time('H:i:s'),
            'year'     => $this->f->numberBetween(1990, 2026),
            'json'     => json_encode(['key' => $this->f->word(), 'value' => $this->f->numberBetween(1, 100)]),
            'uuid'     => $this->f->uuid(),
            'binary'   => random_bytes(8),
            'enum'     => $this->enum($full),
            'text'     => $this->f->paragraph(),
            default    => $this->string($name, $len),
        };
    }

    /** Make a value unique for unique-indexed columns. */
    public function unique(mixed $v, array $c, int $seq): mixed
    {
        $kind = $this->kind($c);

        if ($kind === 'int') {
            return $seq + $this->offset;
        }
        if (! is_string($v) || in_array($kind, ['uuid', 'enum', 'datetime', 'date', 'time', 'json', 'binary'], true)) {
            return $v;
        }

        $len = preg_match('/\((\d+)\)/', $c['type'], $m) ? (int) $m[1] : 255;
        $suffix = $this->run . base_convert((string) $seq, 10, 36);

        if (str_contains($v, '@')) {
            [$local, $domain] = explode('@', $v, 2);
            $local = mb_substr($local, 0, max(1, $len - strlen($domain) - strlen($suffix) - 2));

            return "{$local}.{$suffix}@{$domain}";
        }

        return mb_substr($v, 0, max(1, $len - strlen($suffix) - 1)) . '-' . $suffix;
    }

    private function kind(array $c): string
    {
        $t = strtolower($c['type_name']);
        $full = strtolower($c['type']);

        return match (true) {
            str_starts_with($full, 'enum')                                   => 'enum',
            in_array($t, ['bool', 'boolean'], true), $full === 'tinyint(1)'  => 'bool',
            (bool) preg_match('/^(tiny|small|medium|big)?int|^serial|^int[248]$/', $t) => 'int',
            (bool) preg_match('/decimal|numeric|float|double|real|money/', $t)         => 'decimal',
            (bool) preg_match('/^(datetime|timestamp)/', $t)                 => 'datetime',
            $t === 'date'                                                    => 'date',
            str_starts_with($t, 'time')                                      => 'time',
            $t === 'year'                                                    => 'year',
            str_contains($t, 'json')                                         => 'json',
            $t === 'uuid'                                                    => 'uuid',
            (bool) preg_match('/blob|binary|bytea/', $t)                     => 'binary',
            (bool) preg_match('/text$/', $t)                                 => 'text',
            default                                                          => 'string',
        };
    }

    private function int(string $name, string $type): int
    {
        return match (true) {
            (bool) preg_match('/qty|quantity|stock|count/', $name)    => $this->f->numberBetween(1, 100),
            str_contains($name, 'age')                                => $this->f->numberBetween(18, 80),
            (bool) preg_match('/rating|stars/', $name)                => $this->f->numberBetween(1, 5),
            (bool) preg_match('/sort|order|position|priority/', $name) => $this->f->numberBetween(0, 100),
            str_starts_with($type, 'tiny')                            => $this->f->numberBetween(0, 100),
            str_starts_with($type, 'small')                           => $this->f->numberBetween(0, 10000),
            str_starts_with($type, 'big'), $type === 'int8'           => $this->f->numberBetween(1, 1_000_000),
            default                                                   => $this->f->numberBetween(1, 100_000),
        };
    }

    private function decimal(string $name, string $full): float
    {
        $max = 1000;
        $scale = 2;

        if (preg_match('/\((\d+),\s*(\d+)\)/', $full, $m)) {
            $scale = (int) $m[2];
            $max = min($max, (10 ** ((int) $m[1] - $scale)) - 1);
        }

        $value = match (true) {
            (bool) preg_match('/price|amount|total|cost|balance|salary/', $name) => $this->f->randomFloat($scale, 1, min(5000, $max)),
            str_contains($name, 'lat')                                          => $this->f->latitude(),
            (bool) preg_match('/lng|lon/', $name)                               => $this->f->longitude(),
            (bool) preg_match('/rate|percent|discount|tax/', $name)             => $this->f->randomFloat($scale, 0, min(100, $max)),
            default                                                             => $this->f->randomFloat($scale, 0, max(1, $max)),
        };

        return round(max(-$max, min($max, $value)), $scale);
    }

    private function date(string $name, string $format): string
    {
        $future = (bool) preg_match('/expire|due|end|until|valid_to/', $name);

        return ($future
            ? $this->f->dateTimeBetween('now', '+1 year')
            : $this->f->dateTimeBetween('-2 years', 'now'))->format($format);
    }

    private function enum(string $full): mixed
    {
        preg_match_all("/'((?:[^']|'')*)'/", $full, $m);
        $values = array_map(fn ($v) => str_replace("''", "'", $v), $m[1] ?? []);

        return $values ? $values[array_rand($values)] : null;
    }

    private function string(string $name, ?int $len): string
    {
        $f = $this->f;

        $v = match (true) {
            str_contains($name, 'email')                              => $f->safeEmail(),
            str_contains($name, 'password')                           => $this->hashed ??= Hash::make('password'),
            (bool) preg_match('/phone|mobile|tel$/', $name)           => $f->phoneNumber(),
            $name === 'first_name'                                    => $f->firstName(),
            $name === 'last_name'                                     => $f->lastName(),
            (bool) preg_match('/^(full_)?name$|_name$/', $name)       => $f->name(),
            str_contains($name, 'company')                            => $f->company(),
            (bool) preg_match('/username|user_name|handle/', $name)   => $f->userName(),
            str_contains($name, 'slug')                               => $f->slug(3),
            (bool) preg_match('/title|subject|headline/', $name)      => rtrim($f->sentence(4), '.'),
            (bool) preg_match('/description|body|content|bio|summary|note/', $name) => $f->text(200),
            (bool) preg_match('/address|street/', $name)              => $f->address(),
            str_contains($name, 'city')                               => $f->city(),
            str_contains($name, 'country')                            => $f->country(),
            (bool) preg_match('/state|province/', $name)              => $f->state(),
            (bool) preg_match('/zip|postal/', $name)                  => $f->postcode(),
            (bool) preg_match('/image|avatar|photo|picture|logo|thumbnail/', $name) => 'https://picsum.photos/seed/' . $f->numberBetween(1, 99999) . '/640/480',
            (bool) preg_match('/url|link|website/', $name)            => $f->url(),
            $name === 'ip' || str_contains($name, 'ip_address')       => $f->ipv4(),
            (bool) preg_match('/token|api_key|secret/', $name)        => sha1($f->uuid()),
            str_contains($name, 'currency')                           => $f->currencyCode(),
            str_contains($name, 'color')                              => $f->hexColor(),
            (bool) preg_match('/locale|lang/', $name)                 => $f->languageCode(),
            (bool) preg_match('/sku|code/', $name)                    => strtoupper($f->bothify('??-####')),
            $name === 'status'                                        => $f->randomElement(['active', 'inactive', 'pending']),
            $len !== null && $len <= 30                               => $f->word(),
            default                                                   => rtrim($f->sentence(6), '.'),
        };

        return $len ? mb_substr((string) $v, 0, $len) : (string) $v;
    }
}
