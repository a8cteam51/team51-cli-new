<?php
/**
 * SSH Benchmark Script
 *
 * Benchmarks sequential vs parallel long-running tasks.
 */

require 'vendor/autoload.php';

use Symfony\Component\Process\Process;

/**
 * Class for benchmarking SSH execution performance.
 */
class Benchmark
{
	/**
	 * Number of tasks to execute.
	 *
	 * @var int
	 */
	private $task_count;

	/**
	 * Maximum number of parallel executions.
	 *
	 * @var int
	 */
	private $max_parallel;

	/**
	 * Constructor.
	 *
	 * @param int $task_count   Number of tasks to execute.
	 * @param int $max_parallel Maximum number of parallel executions.
	 */
	public function __construct($task_count, $max_parallel)
	{
		$this->task_count = $task_count;
		$this->max_parallel = $max_parallel;
	}

	/**
	 * Run the benchmark comparing sequential vs parallel execution.
	 */
	public function run_benchmark()
	{
		echo "Benchmarking SSH execution with {$this->task_count} tasks...\n";
		echo "Max parallel executions: {$this->max_parallel}\n";

		$start_time = microtime(true);
		$this->run_sequential();
		$sequential_time = microtime(true) - $start_time;
		echo "⏳ Sequential Execution Time: " . round($sequential_time, 2) . " seconds\n";

		$start_time = microtime(true);
		$this->run_parallel();
		$parallel_time = microtime(true) - $start_time;
		echo "⚡ Parallel Execution Time: " . round($parallel_time, 2) . " seconds\n";

		echo "🔼 Speed Improvement: " . round($sequential_time / max($parallel_time, 1), 2) . "x faster\n";
	}

	/**
	 * Run tasks sequentially.
	 */
	private function run_sequential()
	{
		foreach (range(1, $this->task_count) as $i) {
			echo "🔄 Simulating SSH task $i...\n";
			sleep(5);
			echo "✅ Task $i completed.\n";
		}
	}

	/**
	 * Run tasks in parallel.
	 */
	private function run_parallel()
	{
		$processes = array();
		$running_processes = 0;

		foreach (range(1, $this->task_count) as $i) {
			$process = new Process(array('php', '-r', "sleep(5); echo 'Task $i completed';"));
			$process->start();
			$processes[$i] = $process;
			$running_processes++;

			if ($running_processes >= $this->max_parallel) {
				foreach ($processes as $index => $p) {
					$p->wait();
					echo $p->getOutput() . "\n";
					unset($processes[$index]);
					$running_processes--;
					if ($running_processes < $this->max_parallel) {
						break;
					}
				}
			}
		}

		foreach ($processes as $p) {
			$p->wait();
			echo $p->getOutput() . "\n";
		}
	}
}
