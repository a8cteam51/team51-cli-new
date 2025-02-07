<?php
/**
 * Parallel Process Helper
 *
 * Handles parallel processing of tasks using PHP's parallel extension.
 *
 * @package WPCOMSpecialProjects\CLI\Helper
 */

declare(strict_types=1);

namespace WPCOMSpecialProjects\CLI\Helper;

use parallel\Runtime as Parallel_Runtime;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Class Parallel_Process
 */
class Parallel_Process {

	/**
	 * Console output interface.
	 *
	 * @var OutputInterface
	 */
	protected OutputInterface $output;

	/**
	 * The total number of tasks to run.
	 *
	 * @var int
	 */
	protected int $task_count;

	/**
	 * The number of processes to run in parallel.
	 *
	 * @var int|null
	 */
	protected ?int $max_processes;

	/**
	 * The number of threads to run in parallel.
	 *
	 * @var int|null
	 */
	protected ?int $max_threads;

	/**
	 * The tasks to run in parallel.
	 *
	 * @var array
	 */
	protected array $tasks;

	/**
	 * Whether the `parallel` extension is available.
	 *
	 * @var bool
	 */
	protected static bool $parallel_extension_loaded;

	/**
	 * The timeout, in seconds, for the SSH connection process to run.
	 *
	 * @var int|null
	 */
	protected ?int $ssh_timeout;

	/**
	 * Callbacks to run during task processing.
	 *
	 * @var array{
	 *     process_start: ?callable,
	 *     process_complete: ?callable,
	 *     buffer_out: ?callable,
	 *     shell_command: ?callable,
	 *     command_args: ?callable
	 * }
	 */
	private array $callbacks = array(
		'process_start'    => null,
		'process_complete' => null,
		'buffer_out'       => null,
		'shell_command'    => null,
		'command_args'     => null,
	);

	/**
	 * Constructor.
	 *
	 * @param OutputInterface $output Console output interface.
	 * @param array           $tasks  Tasks to process.
	 */
	public function __construct( OutputInterface $output, array $tasks ) {
		$this->output = $output;
		$this->tasks  = $tasks;

		$this->max_processes = 20;
		$this->max_threads   = 10;
		$this->task_count    = count( $tasks );
	}

	/**
	 * Creates a new instance.
	 *
	 * @param OutputInterface $output Console output interface.
	 * @param array           $tasks  Tasks to process.
	 * @return self
	 */
	public static function create( OutputInterface $output, array $tasks ): self {
		return new self( $output, $tasks );
	}

	/**
	 * Adds a callback to the parallel process.
	 *
	 * @param string   $name     Callback name.
	 * @param callable $callback Callback function.
	 * @return self
	 */
	public function add_callback( string $name, callable $callback ): self {
		$this->callbacks[ $name ] = $callback;
		return $this;
	}

	/**
	 * Runs a registered callback.
	 *
	 * @param string $name   Callback name.
	 * @param bool   $return Whether to return the callback result.
	 * @param mixed  ...$args Callback arguments.
	 * @return mixed
	 */
	protected function run_callback( string $name, bool $return, ...$args ): mixed {
		if ( is_callable( $this->callbacks[ $name ] ) ) {
			if ( $return ) {
				return ( $this->callbacks[ $name ] )( ...$args );
			}
			( $this->callbacks[ $name ] )( ...$args );
		}
		return null;
	}

	/**
	 * Configures the parallel process.
	 *
	 * @param array $config Configuration options.
	 * @return self
	 */
	public function configure( array $config ): self {
		$this->max_threads   = $config['max_threads'] ?? 10;
		$this->max_processes = $config['max_processes'] ?? 20;
		$this->ssh_timeout   = $config['ssh_timeout'] ?? 120;
		return $this;
	}

	/**
	 * Processes all tasks in parallel.
	 *
	 * @return array Task failures.
	 */
	public function process_tasks(): array {
		$start_time    = microtime( true );
		$task_failures = array();
		$processes     = array();
		$completed     = 0;
		$results       = array();

		foreach ( $this->tasks as $id ) {
			if ( ! is_callable( $this->callbacks['command_args'] ) ) {
				$this->output->writeln(
					sprintf(
						'<error>Command args callback is not callable for task %s.</error>',
						$id
					)
				);
				exit( 1 );
			}

			$process          = $this->create_ssh_worker_process( ( $this->callbacks['command_args'] )( $id ) );
			$processes[ $id ] = $process;

			$this->run_callback( 'process_start', false, $id, $process->getPid(), count( $processes ) );

			$current_processes = count( $processes );
			while ( $current_processes >= $this->max_processes ) {
				$this->handle_completed_processes( $processes, $results, $task_failures, $completed );
				usleep( 50000 );
				$current_processes = count( $processes );
			}
		}

		while ( ! empty( $processes ) ) {
			$this->handle_completed_processes( $processes, $results, $task_failures, $completed );
			usleep( 50000 );
		}

		$this->output_execution_time( $start_time );

		return $task_failures;
	}

	/**
	 * Creates an SSH worker process.
	 *
	 * @param string $args Command arguments.
	 * @return Process
	 */
	private function create_ssh_worker_process( string $args ): Process {
		$shell_command = $this->run_callback( 'shell_command', true, $args );
		if ( ! $shell_command ) {
			$this->output->writeln( '<error>Shell command empty.</error>' );
			exit( 1 );
		}

		$process = Process::fromShellCommandline(
			sprintf(
				'php %s ssh-worker %s %s %s --quiet',
				TEAM51_CLI_FILE,
				$args,
				sprintf( '--shell-command="%s"', $shell_command ),
				$this->ssh_timeout ? sprintf( '--timeout=%d', $this->ssh_timeout ) : ''
			)
		);

		$process->setTimeout( null );
		$process->start(
			function ( $type, $buffer ) {
				$this->filter_output( $buffer );
			}
		);

		return $process;
	}

	/**
	 * Filters and handles process output.
	 *
	 * @param string $buffer         Output buffer.
	 * @param bool   $write_to_output Whether to write directly to output.
	 */
	private function filter_output( string $buffer, bool $write_to_output = false ): void {
		if ( $write_to_output ) {
			$this->output->writeln( $buffer );
		} else {
			$this->run_callback( 'buffer_out', false, $buffer );
		}
	}

	/**
	 * Handles completed processes.
	 *
	 * @param array $processes     Reference to active processes.
	 * @param array $results       Reference to results array.
	 * @param array $task_failures Reference to task failures array.
	 * @param int   $completed     Reference to completed count.
	 */
	private function handle_completed_processes( array &$processes, array &$results, array &$task_failures, int &$completed ): void {
		foreach ( $processes as $index => $process ) {
			if ( ! $process->isRunning() ) {
				$process_output = $process->getOutput() ?: $process->getErrorOutput();
				$result         = json_decode( $process_output, true );
				$result         = $this->run_callback(
					'parse_result',
					true,
					$result ?? array( 'no_result' => json_encode( $process_output ) )
				);

				if ( $result && ! isset( $result['error'] ) ) {
					$results[ $index ] = $result;
				} else {
					$task_failures[ $index ] = (object) array(
						'error'  => $result['error'] ?? 'SSH connection failed',
						'id'     => $result['id'] ?? $index,
						'errors' => array(
							sprintf(
								'%s: %s',
								$result['error'] ?? 'SSH connection failed',
								$result['details'] ?? 'No details available'
							),
						),
					);
				}

				unset( $processes[ $index ] );
				++$completed;
				$this->run_callback( 'process_complete', false, $index, $completed, $this->task_count, $result );
			}
		}
	}

	/**
	 * Outputs execution time information.
	 *
	 * @param float $start_time Process start time.
	 */
	private function output_execution_time( float $start_time ): void {
		$duration = round( microtime( true ) - $start_time );
		$hours    = intval( $duration / 3600 );
		$minutes  = intval( ( $duration % 3600 ) / 60 );
		$seconds  = intval( $duration % 60 );

		$this->output->writeln(
			sprintf(
				"\n⏱️ Total execution time: %02d:%02d:%02d",
				$hours,
				$minutes,
				$seconds
			)
		);
	}
}
