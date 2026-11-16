# gadwinn/benchmark

A tiny development tool to compare the execution time and memory use of pieces of code.

## Requirements

- PHP >= 8.3

## Installation

```bash
composer require --dev gadwinn/benchmark
```

## Usage

```php
use G\Benchmark\Benchmark;

$data = range(1, 10_000);

(new Benchmark(iterations: 1_000))
    ->addCallable('array_sum', 'array_sum', $data)
    ->addCallable('foreach', static function (array $numbers): int {
        $sum = 0;
        foreach ($numbers as $number) {
            $sum += $number;
        }

        return $sum;
    }, $data)
    ->display();
```

```text
TOP 1 - Benchmarking "array_sum":
Total time (ns): 6.2147e+6
Total time (s): 0.0062147
Iterations: 1000
Time per iteration (ns): 6214.7
Time per iteration (s): 6.2147e-6
Memory peak usage (B): 0
Memory peak usage (MiB): 0
--------------------------------------------------------------------------------
TOP 2 - Benchmarking "foreach":
...
```

Each callable is called `iterations` times (10 by default) with the arguments given after it. The report lists the callables from the fastest to the slowest; it is coloured in a terminal and rendered as HTML in a browser.

| Method | Description |
|---|---|
| `addCallable(string $name, callable $callable, mixed ...$args): self` | Registers a piece of code and its arguments |
| `display(): void` | Runs the benchmark and prints the report |
| `report(): string` | Runs the benchmark and returns the report |
| `run(): Generator` | Runs the benchmark and yields one raw result per callable, in the order they were added |
| `getCallables(): array` | The registered callables |

### Raw results

```php
foreach ($benchmark->run() as $result) {
    // [
    //     'callable_name'         => 'array_sum',
    //     'total_time_ns'         => 6214700,   // time spent inside the callable, all iterations
    //     'iterations'            => 1000,
    //     'time_per_iteration_ns' => 6214.7,
    //     'memory_peak_usage_b'   => 0,
    // ]
}
```

### Reading the figures

- **Time** only counts what happens inside the callable; the loop around it is not measured.
- **Memory** is the highest amount of memory a single call needed on top of what was already allocated when it started. It is the maximum over the iterations, not a sum or an average.
- Timings vary with the machine load, OPcache and the JIT: compare results obtained in the same run, and raise `iterations` for short pieces of code.

## Development

```bash
composer install
composer test-all
```

## License

MIT
