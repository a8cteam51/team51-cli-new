<?php

namespace WPCOMSpecialProjects\CLI\Helper;

/** @phpstan-import-type Runtime from \parallel\Runtime */
use parallel\Runtime as Parallel_Runtime;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

trait Parallel_Process {

    // region FIELDS AND CONSTANTS

    /**
     * The number of processes to run in parallel.
     *
     * @var int
     */
    protected int $max_processes;

    /**
     * The number of threads to run in parallel.
     *
     * @var int
     */
    protected int $max_threads;

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

    // endregion

    // region METHODS

    public function __construct( int $max_processes = 10, int $max_threads = 10 ) {
        $this->max_processes                 = $max_processes;
        $this->max_threads                   = $max_threads;
        static::$parallel_extension_loaded ??= extension_loaded( 'parallel' );

        return $this;
    }

    public function run( OutputInterface $output, array $command_arguments = ['site_id' => null, 'site_type' => null, 'email' => null, 'site_url' => null] ){
        $process = Process::fromShellCommandline(
            sprintf(
                'php %s ssh-worker --site-id=%s --site-type=%s --email=%s --site-url=%s --quiet',
                realpath(__DIR__ . '/../team51-cli.php'),
                ...$command_arguments
            )
        );

        $process->setTimeout(null);
        $process->start( function ( $type, $buffer ) use ( $output ) {
            if ( Process::ERR === $type ) {
                $output->writeln( "<error>ERROR: Buffer: $buffer</error>" );
            } else {
                $output->writeln( "Type: $type" );
                $output->writeln( "Buffer: $buffer" );
            }
        });

        return $process;
    }

    /* public function process( array $tasks ) {
        if ( static::$parallel_extension_loaded ) {
            $this->do_multithreaded( $tasks );
        } else {
            $this->do_parallel( $tasks );
        }
    }

    protected function do_parallel( array $tasks ) {
        // TODO: Implement do_parallel() method.
        // TODO: Implement do_parallel() method.
    }

    protected function do_multithreaded( array $tasks ) {
        $runtime = new Parallel_Runtime();
    } */

    // endregion
}
