<?php

declare(strict_types=1);

namespace G\Benchmark;

use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type BenchmarkResult array{
 *     callable_name: string,
 *     total_time_ns: int,
 *     iterations: int,
 *     time_per_iteration_ns: float,
 *     memory_peak_usage_b: int
 * }
 */
class Benchmark
{
    private const int NS_PER_SECOND = 1_000_000_000;
    private const int BYTES_PER_MIB = 1_048_576;
    private const int PODIUM_SIZE = 3;
    private const int SEPARATOR_LENGTH = 80;

    /** @var list<array{name: string, callable: callable, args: array<mixed>}> */
    private array $callables = [];

    /**
     * @param int $iterations Number of iterations to run for each callable.
     *
     * @throws InvalidArgumentException If the number of iterations is not positive.
     */
    public function __construct(
        private readonly int $iterations = 10
    ) {
        if ($iterations <= 0) {
            throw new InvalidArgumentException('Iterations must be greater than 0.');
        }
    }

    /**
     * Get the list of callables added for benchmarking.
     *
     * @return list<array{name: string, callable: callable, args: array<mixed>}>
     */
    public function getCallables(): array
    {
        return $this->callables;
    }

    /**
     * Add a callable to the benchmark.
     *
     * @param string $name Name of the callable.
     * @param callable $callable The callable to benchmark.
     * @param mixed ...$args Arguments to pass to the callable.
     *
     * @return Benchmark
     */
    public function addCallable(string $name, callable $callable, mixed ...$args): self
    {
        $this->callables[] = ['name' => $name, 'callable' => $callable, 'args' => $args];

        return $this;
    }

    /**
     * Run the benchmark on the added callables, in the order they were added.
     *
     * The callables are kept: the benchmark can be run several times.
     *
     * The memory figure is the highest amount of memory a single call needed
     * on top of what was already in use when it started.
     *
     * @return Generator<int, BenchmarkResult>
     * @throws RuntimeException If no callable was added.
     */
    public function run(): Generator
    {
        if (empty($this->callables)) {
            throw new RuntimeException('No callables to benchmark.');
        }

        foreach ($this->callables as $callable) {
            $memoryPeakUsage = 0;
            $totalTime = 0;

            for ($i = 0; $i < $this->iterations; $i++) {
                $memoryBefore = memory_get_usage();
                memory_reset_peak_usage();

                $start = hrtime(true);
                $callable['callable'](...$callable['args']);
                $totalTime += hrtime(true) - $start;

                $memoryPeakUsage = max($memoryPeakUsage, memory_get_peak_usage() - $memoryBefore);
            }

            yield [
                'callable_name' => $callable['name'],
                'total_time_ns' => (int) $totalTime,
                'iterations' => $this->iterations,
                'time_per_iteration_ns' => $totalTime / $this->iterations,
                'memory_peak_usage_b' => (int) $memoryPeakUsage,
            ];
        }
    }

    /**
     * Run the benchmark and return its report, fastest callable first.
     *
     * The report is coloured for a terminal on the command line, and is HTML otherwise.
     *
     * @throws RuntimeException If no callable was added.
     */
    public function report(): string
    {
        $isCli = (PHP_SAPI === 'cli');
        $green = $isCli ? "\033[32m" : '<span style="color: #0c0;">';
        $red = $isCli ? "\033[31m" : '<span style="color: #c00;">';
        $reset = $isCli ? "\033[0m" : '</span>';

        $results = iterator_to_array($this->run(), false);
        usort(
            $results,
            static fn(array $a, array $b): int => $a['time_per_iteration_ns'] <=> $b['time_per_iteration_ns']
        );

        $out = [];
        foreach ($results as $i => $result) {
            $name = $isCli ? $result['callable_name'] : htmlspecialchars($result['callable_name']);

            $out[] = $red
                . ($i < self::PODIUM_SIZE ? sprintf('TOP %d - ', $i + 1) : '')
                . ($name !== '' ? sprintf('Benchmarking "%s":', $name) : 'Benchmarking:')
                . $reset;
            $out[] = sprintf('Total time (ns): %g', $result['total_time_ns']);
            $out[] = sprintf('Total time (s): %g', $result['total_time_ns'] / self::NS_PER_SECOND);
            $out[] = sprintf('Iterations: %d', $result['iterations']);
            $out[] = sprintf('Time per iteration (ns): %s%g%s', $green, $result['time_per_iteration_ns'], $reset);
            $out[] = sprintf('Time per iteration (s): %g', $result['time_per_iteration_ns'] / self::NS_PER_SECOND);
            $out[] = sprintf('Memory peak usage (B): %s%d%s', $green, $result['memory_peak_usage_b'], $reset);
            $out[] = sprintf('Memory peak usage (MiB): %g', $result['memory_peak_usage_b'] / self::BYTES_PER_MIB);
            $out[] = str_repeat('-', self::SEPARATOR_LENGTH);
        }

        return implode($isCli ? PHP_EOL : '<br>', $out);
    }

    /**
     * Display the benchmark results.
     *
     * @return void
     * @throws RuntimeException If no callable was added.
     */
    public function display(): void
    {
        echo $this->report();
    }
}
