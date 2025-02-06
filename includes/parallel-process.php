<?php

namespace WPCOMSpecialProjects\CLI\Helper;

use parallel\Runtime as Parallel_Runtime;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class Parallel_Process {


	// region FIELDS AND CONSTANTS

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
	 * @var int
	 */
	protected ?int $max_processes;

	/**
	 * The number of threads to run in parallel.
	 *
	 * @var int
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
	 * Callbacks to run after the task is completed.
	 *
	 * @var array
	 */
	private $callbacks = array(
		'process_start' => null,
		'process_complete' => null,
		'buffer_out' => null,
		'shell_command' => null,
		'command_args' => null,
	);

	// endregion

	// region METHODS

	public function __construct( OutputInterface $output, array $tasks ) {
		$this->output        = $output;

		$this->max_processes = $config['max_processes'] ?? 20;
		$this->max_threads   = $config['max_threads'] ?? 10;

		$this->tasks      = $tasks;
		$this->task_count = count( $tasks );

		return $this;
	}

	public static function create( OutputInterface $output, array $tasks ): self {
		return new self( $output, $tasks );
	}

	/**
	 * Add a callback to the parallel process.
	 *
	 * @param string   $name The name of the callback e.g. 'shell_command', 'command_args', 'buffer_out'
	 * @param callable $callback The callback to add.
	 * @return self The parallel process instance.
	 */
	public function add_callback( string $name, callable $callback ): self {
		$this->callbacks[ $name ] = $callback;
		return $this;
	}

	public function configure( array $config ): self {
		$this->max_threads   = $config['max_threads'] ?? 10;
		$this->max_processes = $config['max_processes'] ?? 20;
		return $this;
	}

	public function process_tasks(): array {

		$start_time = microtime( true );

		$task_failures = array();
		$processes    = array();
		$completed    = 0;
		$results      = array();

		foreach ( $this->tasks as $id => $task ) {
			if ( ! is_callable( $this->callbacks['command_args'] ) ) {
				$this->output->writeln(
					sprintf(
						'<error>Command args callback is not callable for task.</error>',
						$id
					)
				);
				exit( 1 );
			}
			$process          = $this->create_ssh_worker_process( ( $this->callbacks['command_args'] )( $id ) );
			$processes[ $id ] = $process;

			// $this->output->writeln(
			// 	sprintf(
			// 		'Started process %s (%s) - Active: %d',
			// 		$id,
			// 		$process->getPid(),
			// 		count( $processes )
			// 	)
			// );
			($this->callbacks['process_start'])( $id, $process->getPid(), count( $processes ) );

			$current_processes = count( $processes );
			while ( $current_processes >= $this->max_processes ) {
				$this->handle_completed_processes( $processes, $results, $task_failures, $completed );
				usleep( 50000 );
				$current_processes = count( $processes );
			}
		}

		// Process remaining
		while ( ! empty( $processes ) ) {
			$this->handle_completed_processes( $processes, $results, $task_failures, $completed );
			usleep( 50000 );
		}

		$end_time = microtime( true );
		$duration = round( $end_time - $start_time );

		$hours   = intval( $duration / 3600 );
		$minutes = intval( ( $duration % 3600 ) / 60 );
		$seconds = intval( $duration % 60 );

		$this->output->writeln(
			sprintf(
				"\n⏱️ Total execution time: %02d:%02d:%02d",
				$hours,
				$minutes,
				$seconds
			)
		);

		return $task_failures;
	}

	private function create_ssh_worker_process( string $args ): Process {
		$shell_command = ($this->callbacks['shell_command'])( $args );
		// $this->output->writeln( "Shell command: $shell_command" );
		$process = Process::fromShellCommandline(
			sprintf(
				'php %s ssh-worker %s --quiet',
				realpath( __DIR__ . '/../team51-cli.php' ),
				$args . ' --shell-command="' . $shell_command . '" --quiet',
			)
		);
		$process->setTimeout( 60 );
		$process->start(
			function ( $type, $buffer ) {
				$this->filter_output( $buffer );
				// if ( Process::ERR === $type ) {
				// 	$this->filter_output( "<error>Buffer: ERROR: $buffer</error>" );
				// } else {
				// }
			}
		);
		return $process;
	}

	private function filter_output( string $buffer, bool $write_to_output = false ): void {
		if ( ! $write_to_output && is_callable( $this->callbacks['buffer_out'] ) ) {
			($this->callbacks['buffer_out'])( $buffer );
			return;
		}
		$this->output->writeln( $buffer );
	}

	private function handle_completed_processes( array &$processes, array &$results, array &$task_failures, int &$completed ): void {
		foreach ( $processes as $index => $process ) {
			if ( ! $process->isRunning() ) {
				$process_output = $process->getOutput() ?: $process->getErrorOutput();
				$result         = \json_decode( $process_output, true );

				if ( $result && ! isset( $result['error'] ) ) {
					$results[ $index ] = $result;
				} else {
					$task_failures[ $index ] = $this->handle_process_result( $result, $index );
				}

				unset( $processes[ $index ] );
				++$completed;
				($this->callbacks['process_complete'])( $index, $completed, $this->task_count, $result );
			}
		}
	}

	private function handle_process_result( $result, $index ) {
		$error_message = $result['error'] ?? 'SSH connection failed';
		$error_details = $result['details'] ?? 'No details available';

		return (object) array(
			'error'  => $error_message,
			'id'     => $result['id'] ?? $index,
			'errors' => array( $error_message . ': ' . $error_details ),
		);
	}

	// endregion
}
