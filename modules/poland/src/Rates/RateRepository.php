<?php

declare(strict_types=1);

namespace Poland\Rates;

use Poland\Domain\Period;
use RuntimeException;

/**
 * Loads the rate tables. Framework-free on purpose: the calculators must be
 * runnable from a plain PHP script, a test, an artisan command or an HTTP
 * request without changing behaviour.
 */
final class RateRepository
{
    public const TABLES = ['zus_social', 'zus_health', 'pit', 'vat', 'deadlines'];

    /** @var array<string,RateTable> */
    private array $tables = [];

    public function __construct(private readonly string $directory)
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("Rate directory not found: {$directory}");
        }
    }

    public static function default(): self
    {
        return new self(dirname(__DIR__, 2).'/config/rates');
    }

    public function table(string $name): RateTable
    {
        if (! isset($this->tables[$name])) {
            $path = $this->directory.'/'.$name.'.php';
            if (! is_file($path)) {
                throw new RuntimeException("Rate table file not found: {$path}");
            }

            /** @var array<string,mixed> $config */
            $config = require $path;
            $this->tables[$name] = new RateTable($name, $config);
        }

        return $this->tables[$name];
    }

    public function zusSocial(): RateTable
    {
        return $this->table('zus_social');
    }

    public function zusHealth(): RateTable
    {
        return $this->table('zus_health');
    }

    public function pit(): RateTable
    {
        return $this->table('pit');
    }

    public function vat(): RateTable
    {
        return $this->table('vat');
    }

    public function deadlines(): RateTable
    {
        return $this->table('deadlines');
    }

    /**
     * Which tables can settle a given month.
     *
     * @return array{ok: bool, missing: list<string>}
     */
    public function coverage(Period $period): array
    {
        $missing = [];
        foreach (self::TABLES as $name) {
            $table = $this->table($name);
            // "deadlines" and "vat" carry constants as well as versions; a table
            // with no versions at all is not date-scoped and always applies.
            if ($table->versions() === []) {
                continue;
            }
            if (! $table->covers($period)) {
                $missing[] = $name;
            }
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }
}
