<?php
/**
 * SSH Benchmark Script
 *
 * This script benchmarks three execution methods:
 * 1. Sequential execution
 * 2. Parallel execution using Symfony Process
 * 3. Multithreaded execution using parallel\Runtime (if installed)
 *
 * It measures the time taken for each method and calculates the speed improvement.
 */

require 'vendor/autoload.php';

use Symfony\Component\Process\Process;
use parallel\Runtime;

/**
 * Class Benchmark
 *
 * Runs performance benchmarks for sequential, parallel, and multithreaded execution.
 */
class Benchmark {
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
	 * Maximum number of threads for multithreading.
	 *
	 * @var int
	 */
	private $max_threads;

	/**
	 * Whether the `parallel` extension is available.
	 *
	 * @var bool
	 */
	private $parallel_extension_loaded;

	/**
	 * Constructor.
	 *
	 * @param int $task_count   Number of tasks to execute.
	 * @param int $max_parallel Maximum number of parallel executions.
	 * @param int $max_threads  Maximum number of multithreaded executions.
	 */
	public function __construct( $task_count, $max_parallel, $max_threads ) {
		$this->task_count                = $task_count;
		$this->max_parallel              = $max_parallel;
		$this->max_threads               = $max_threads;
		$this->parallel_extension_loaded = extension_loaded( 'parallel' );
	}

	/**
	 * Run the benchmark tests.
	 */
	public function run_benchmark() {
		echo "Benchmarking SSH execution with {$this->task_count} tasks...\n";
		echo "Max parallel executions: {$this->max_parallel}\n";
		echo "Max threads: {$this->max_threads}\n\n";

		$start_time = microtime( true );
		echo "Test Sequential:\n";
		$this->run_sequential();
		$sequential_time = microtime( true ) - $start_time;
		echo "⏳ Sequential Execution Time: " . round( $sequential_time, 2 ) . " seconds\n\n";

		$start_time = microtime( true );
		echo "Test Parallel:\n";
		$this->run_parallel();
		$parallel_time = microtime( true ) - $start_time;
		echo "⚡ Parallel Execution Time: " . round( $parallel_time, 2 ) . " seconds\n\n";

		if ( $this->parallel_extension_loaded ) {
			$start_time = microtime( true );
			echo "Test Multithreaded:\n";
			$this->run_multithreaded();
			$multithreaded_time = microtime( true ) - $start_time;
			echo "🚀 Multithreaded Execution Time: " . round( $multithreaded_time, 2 ) . " seconds\n\n";
		} else {
			echo "❌ `parallel` extension not installed. Skipping multithreaded test.\n";
		}

		echo "🔼 Speed Improvement (Parallel vs Sequential): " . round( $sequential_time / max( $parallel_time, 1 ), 2 ) . "x faster\n";
		if ( $this->parallel_extension_loaded ) {
			echo "🔼 Speed Improvement (Multithreaded vs Sequential): " . round( $sequential_time / max( $multithreaded_time, 1 ), 2 ) . "x faster\n";
		}
	}

	/**
	 * Run tasks sequentially.
	 */
	private function run_sequential() {
		foreach ( range( 1, $this->task_count ) as $i ) {
			$percent = number_format( ( $i / $this->task_count ) * 100, 2 );
			echo "\r🔄 $percent%";
			sleep( 1 );
		}
		echo "\r✅ 100.00%\n";
	}

	/**
	 * Run tasks in parallel using Symfony Process.
	 */
	private function run_parallel() {
		$processes = array();
		$completed = 0;

		foreach ( range( 1, $this->task_count ) as $i ) {
			$process = new Process( array( 'php', '-r', 'sleep(1);' ) );
			$process->start();
			$processes[ $i ] = $process;

			while ( count( $processes ) >= $this->max_parallel ) {
				foreach ( $processes as $index => $p ) {
					if ( ! $p->isRunning() ) {
						unset( $processes[ $index ] );
						$completed++;
						$percent = number_format( ( $completed / $this->task_count ) * 100, 2 );
						echo "\r🔄 $percent%";
					}
				}
				usleep( 50000 ); // Prevent CPU overload
			}
		}

		while ( ! empty( $processes ) ) {
			foreach ( $processes as $index => $p ) {
				if ( ! $p->isRunning() ) {
					unset( $processes[ $index ] );
					$completed++;
					$percent = number_format( ( $completed / $this->task_count ) * 100, 2 );
					echo "\r🔄 $percent%";
				}
			}
			usleep( 50000 );
		}

		echo "\r✅ 100.00%\n";
	}

	/**
	 * Run tasks using multithreading with parallel\Runtime.
	 */
	private function run_multithreaded() {
		$worker_count = min( $this->max_threads * 4, 128 ); // Scale workers up to 4x CPU cores, max 128
		$workers      = array();
		$completed    = 0;

		// Create worker pool
		for ( $i = 0; $i < $worker_count; $i++ ) {
			$workers[ $i ] = new Runtime();
		}

		// Batch processing to optimize execution
		$batch_size    = $worker_count * 10; // Run 10x worker count per batch
		$total_batches = ceil( $this->task_count / $batch_size );

		for ( $batch = 0; $batch < $total_batches; $batch++ ) {
			$batch_start = $batch * $batch_size;
			$batch_end   = min( $batch_start + $batch_size, $this->task_count );
			$futures     = array();

			for ( $i = $batch_start; $i < $batch_end; $i++ ) {
				$worker_id     = $i % $worker_count;
				$futures[ $i ] = $workers[ $worker_id ]->run( fn () => sleep( 1 ) );
			}

			// Wait for batch to complete before starting next batch
			foreach ( $futures as $i => $future ) {
				$future->value();
				$completed++;
				$percent = number_format( ( $completed / $this->task_count ) * 100, 2 );
				echo "\r🔄 $percent%";
			}
		}
		echo "\r✅ 100.00%\n";
	}
}

/**
 * Get the number of logical CPU cores (Linux, macOS, and Windows).
 *
 * @return int|null Number of CPU cores or null if unavailable.
 */
function get_cpu_count(): ?int {
	if ( 'Windows' === PHP_OS_FAMILY ) {
		$wmic = shell_exec( 'WMIC CPU Get NumberOfLogicalProcessors 2>nul' );
		if ( $wmic ) {
			preg_match( '/\d+/', $wmic, $matches );
			if ( $matches ) {
				return (int) $matches[0];
			}
		}

		$powershell = shell_exec( 'powershell -command "Get-WmiObject Win32_Processor | Select-Object -ExpandProperty NumberOfLogicalProcessors"' );
		if ( $powershell ) {
			preg_match( '/\d+/', $powershell, $matches );
			return $matches ? (int) $matches[0] : null;
		}

		return null;
	}

	$process = proc_open(
		'nproc 2>/dev/null || sysctl -n hw.logicalcpu 2>/dev/null',
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);

	if ( ! is_resource( $process ) ) {
		return null;
	}

	$output = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	proc_close( $process );

	preg_match( '/\d+/', $output, $matches );
	return $matches ? (int) $matches[0] : null;
}

// Define task count and concurrency settings
$task_count   = 100;
$max_parallel = 10; // Fixed limit for Symfony Process
$max_threads  = get_cpu_count() ?? 2; // Use available CPU cores for parallel\Runtime

echo "🔄 Detected {$max_threads} CPU cores for threading.\n";

$benchmark = new Benchmark( $task_count, $max_parallel, $max_threads );
$benchmark->run_benchmark();
