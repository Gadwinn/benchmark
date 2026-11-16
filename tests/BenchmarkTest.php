<?php

declare(strict_types=1);

namespace G\Benchmark\Tests;

use G\Benchmark\Benchmark;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BenchmarkTest extends TestCase
{
    #[DataProvider('invalidIterationsProvider')]
    public function testIterationsMustBePositive(int $iterations): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Benchmark($iterations);
    }

    /** @return array<string, array{int}> */
    public static function invalidIterationsProvider(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    public function testRunWithoutCallableFails(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array((new Benchmark())->run());
    }

    public function testAddCallableIsFluentAndKeepsNameAndArguments(): void
    {
        $benchmark = new Benchmark();

        $this->assertSame($benchmark, $benchmark->addCallable('sum', 'array_sum', [1, 2]));
        $this->assertCount(1, $benchmark->getCallables());
        $this->assertSame('sum', $benchmark->getCallables()[0]['name']);
        $this->assertSame([[1, 2]], $benchmark->getCallables()[0]['args']);
    }

    public function testEachCallableIsCalledOncePerIterationWithItsArguments(): void
    {
        $calls = [];
        $benchmark = (new Benchmark(4))
            ->addCallable('first', static function (string $a, int $b) use (&$calls): void {
                $calls[] = ['first', $a, $b];
            }, 'x', 2)
            ->addCallable('second', static function () use (&$calls): void {
                $calls[] = ['second'];
            });

        $results = iterator_to_array($benchmark->run(), false);

        $this->assertCount(8, $calls);
        $this->assertSame(['first', 'x', 2], $calls[0]);
        $this->assertSame(['second'], $calls[7]);
        $this->assertSame(['first', 'second'], array_column($results, 'callable_name'));
    }

    public function testResultShape(): void
    {
        $benchmark = (new Benchmark(5))->addCallable('noop', static fn (): null => null);

        $result = iterator_to_array($benchmark->run(), false)[0];

        $this->assertSame(
            ['callable_name', 'total_time_ns', 'iterations', 'time_per_iteration_ns', 'memory_peak_usage_b'],
            array_keys($result)
        );
        $this->assertSame(5, $result['iterations']);
        $this->assertGreaterThan(0, $result['total_time_ns']);
        $this->assertEqualsWithDelta($result['total_time_ns'] / 5, $result['time_per_iteration_ns'], 0.001);
        $this->assertGreaterThanOrEqual(0, $result['memory_peak_usage_b']);
    }

    public function testSlowerCallableTakesLonger(): void
    {
        $benchmark = (new Benchmark(3))
            ->addCallable('fast', static fn (): null => null)
            ->addCallable('slow', static function (): void {
                usleep(2000);
            });

        [$fast, $slow] = iterator_to_array($benchmark->run(), false);

        $this->assertGreaterThan($fast['time_per_iteration_ns'], $slow['time_per_iteration_ns']);
        $this->assertGreaterThan(1_000_000, $slow['time_per_iteration_ns']);
    }

    public function testMemoryIsThePeakOfOneCallNotASumOverIterations(): void
    {
        $allocate = static function (): void {
            $data = str_repeat('x', 2 * 1024 * 1024);
            unset($data);
        };

        $few = iterator_to_array((new Benchmark(2))->addCallable('alloc', $allocate)->run(), false)[0];
        $many = iterator_to_array((new Benchmark(20))->addCallable('alloc', $allocate)->run(), false)[0];

        $this->assertGreaterThanOrEqual(2 * 1024 * 1024, $few['memory_peak_usage_b']);
        // PHP's allocator rounds a large block up, hence the margin: a sum over 20 calls would exceed 40 MiB
        $this->assertLessThan(8 * 1024 * 1024, $many['memory_peak_usage_b']);
    }

    public function testBenchmarkCanBeRunSeveralTimes(): void
    {
        $count = 0;
        $benchmark = (new Benchmark(2))->addCallable('count', static function () use (&$count): void {
            $count++;
        });

        iterator_to_array($benchmark->run());
        iterator_to_array($benchmark->run());

        $this->assertSame(4, $count);
        $this->assertCount(1, $benchmark->getCallables());
    }

    public function testReportListsTheFastestFirst(): void
    {
        $benchmark = (new Benchmark(2))
            ->addCallable('slow one', static function (): void {
                usleep(2000);
            })
            ->addCallable('fast one', static fn (): null => null);

        $report = $benchmark->report();

        $this->assertStringContainsString('TOP 1 - Benchmarking "fast one":', $report);
        $this->assertStringContainsString('TOP 2 - Benchmarking "slow one":', $report);
        $this->assertLessThan(strpos($report, 'slow one'), strpos($report, 'fast one'));
        $this->assertStringContainsString('Iterations: 2', $report);
        $this->assertStringContainsString('Time per iteration (ns):', $report);
        $this->assertStringContainsString('Memory peak usage (MiB):', $report);
    }

    public function testOnlyTheFirstThreeAreOnThePodium(): void
    {
        $benchmark = new Benchmark(1);
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $benchmark->addCallable($name, static fn (): null => null);
        }

        $report = $benchmark->report();

        $this->assertSame(3, substr_count($report, 'TOP '));
        $this->assertSame(4, substr_count($report, 'Benchmarking "'));
    }

    public function testDisplayEchoesTheReport(): void
    {
        $benchmark = (new Benchmark(1))->addCallable('', static fn (): null => null);

        ob_start();
        $benchmark->display();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('TOP 1 - Benchmarking:', $output);
    }
}
