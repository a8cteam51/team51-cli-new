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

/**
 * Class for handling OS-specific operations.
 */
class OS
{
	/**
	 * Get the number of available parallel processes based on CPU load.
	 *
	 * @return int Number of available parallel processes.
	 */
	public static function get_available_parallelism()
	{
		$load = sys_getloadavg();
		$cpu_count = self::get_cpu_count();

		if (null === $cpu_count) {
			return 2; // Default to 2 parallel tasks if CPU count cannot be determined
		}

		// Calculate max parallelism based on system load
		$max_parallel = max(1, (int) round($cpu_count - $load[0]));

		return min($max_parallel, $cpu_count); // Don't exceed CPU count
	}

	/**
	 * Get the number of logical CPU cores on Linux, macOS, and Windows.
	 *
	 * @return int|null Number of CPU cores or null if unable to determine.
	 */
	public static function get_cpu_count()
	{
		if ('Windows' === PHP_OS_FAMILY) {
			return self::get_cpu_count_windows();
		}

		return self::get_cpu_count_unix();
	}

	/**
	 * Get CPU core count for Unix-based systems (Linux/macOS).
	 *
	 * @return int|null Number of CPU cores or null if unable to determine.
	 */
	private static function get_cpu_count_unix()
	{
		$process = proc_open('nproc 2>/dev/null || sysctl -n hw.logicalcpu 2>/dev/null', [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		], $pipes);

		if (!is_resource($process)) {
			return null;
		}

		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		proc_close($process);

		preg_match('/\d+/', $output, $matches);
		return $matches ? (int) $matches[0] : null;
	}

	/**
	 * Get CPU core count for Windows (WMIC + PowerShell fallback).
	 *
	 * @return int|null Number of CPU cores or null if unable to determine.
	 */
	private static function get_cpu_count_windows()
	{
		$wmic = shell_exec('WMIC CPU Get NumberOfLogicalProcessors 2>nul');
		if ($wmic) {
			preg_match('/\d+/', $wmic, $matches);
			if ($matches) {
				return (int) $matches[0];
			}
		}

		// Fallback to PowerShell (for newer Windows versions)
		$powershell = shell_exec('powershell -command "Get-WmiObject Win32_Processor | Select-Object -ExpandProperty NumberOfLogicalProcessors"');
		if ($powershell) {
			preg_match('/\d+/', $powershell, $matches);
			return $matches ? (int) $matches[0] : null;
		}

		return null;
	}
}

// Use OS utility
$task_count = 10;
$max_parallel = OS::get_available_parallelism();

echo "🔄 Detected {$max_parallel} available parallel slots.\n";

$benchmark = new Benchmark($task_count, $max_parallel);
$benchmark->run_benchmark();