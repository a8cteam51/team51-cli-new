<?php
/**
 * SSH Worker Command
 *
 * Handles SSH execution for WPCOM and Pressable sites.
 *
 * @package WPCOMSpecialProjects\CLI\Command
 */
declare(strict_types=1);

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\{
	Command\Command,
	Input\InputInterface,
	Input\InputOption,
	Output\OutputInterface,
	Attribute\AsCommand
};

use phpseclib3\Net\SSH2;
use Pressable_Connection_Helper;
use WPCOM_Connection_Helper;

/**
 * Site type enum
 */
enum SiteType: string {
    case WPCOM = 'wpcom';
    case PRESSABLE = 'pressable';

    /**
     * Get the display name for the site type
     */
    public function get_display_name(): string {
        return match($this) {
            self::WPCOM => 'WordPress.com',
            self::PRESSABLE => 'Pressable',
        };
    }

    /**
     * Check if site type requires a site URL
     */
    public function requires_site_url(): bool {
        return match($this) {
            self::PRESSABLE => true,
            default         => false,
        };
    }
}

/**
 * SSH Worker Command
 */
#[AsCommand( name: 'ssh-worker', hidden: true )]
class SSH_Worker extends Command {

	// region FIELDS AND CONSTANTS

	/**
	 * Site UUID
	 * 
	 * @var string
	 */
	protected string $site_id;

	/**
	 * Site type i.e. wpcom or pressable
	 * 
	 * @var SiteType
	 */
    protected SiteType $site_type;

	/**
	 * Site URL
	 * 
	 * @var string|null
	 */
    protected ?string $site_url = null;

	/**
	 * Command to execute in shell
	 * 
	 * @var string
	 */
    protected string $shell_command;

	/**
	 * Timeout for SSH connection
	 * 
	 * @var int
	 */
    protected int $timeout = 60;

	// endregion

	// region INHERITED METHODS

	/**
	 * Configures the Symfony Console command.
	 */
	protected function configure(): void {
		$this
			->setDescription( 'SSH Worker for processing shell commands' )
			// Required.
			->addOption( 'site-id', null, InputOption::VALUE_REQUIRED, 'The site ID' )
			->addOption( 'site-type', null, InputOption::VALUE_REQUIRED, 'Site type (wpcom or pressable)' )
			->addOption( 'shell-command', null, InputOption::VALUE_REQUIRED, 'The shell command to execute' )
			// Optional.
			->addOption( 'site-url', null, InputOption::VALUE_OPTIONAL, 'The site url' )
			->addOption( 'timeout', null, InputOption::VALUE_OPTIONAL, 'The timeout for the SSH connection' );
	}

	/**
	 * Initializes the command.
	 *
	 * @param InputInterface  $input  The input interface.
	 * @param OutputInterface $output The output interface.
	 *
	 * @throws \InvalidArgumentException If required parameters are missing.
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->site_id = $input->getOption( 'site-id' );
		$site_type_str = $input->getOption( 'site-type' );
		
		// Safely convert string to enum
		$this->site_type = SiteType::tryFrom($site_type_str) 
			?? throw new \InvalidArgumentException(
				json_encode([
					'error' => 'invalid_site_type',
					'details' => "Site type must be one of: " . 
						implode(', ', array_column(SiteType::cases(), 'value'))
				])
			);

		// Use enum methods
		if ($this->site_type->requires_site_url() && empty($input->getOption('site-url'))) {
			throw new \InvalidArgumentException(
				json_encode([
					'error'   => 'missing_site_url',
					'details' => $this->site_type->get_display_name() . ' sites require a site URL'
				])
			);
		}

		$this->site_url      = $input->getOption( 'site-url' );
		$this->shell_command = $input->getOption( 'shell-command' );
		$this->timeout       = $input->getOption( 'timeout' ) ?? 60;

		if ( ! $this->site_id || ! $this->site_type ) {
			throw new \InvalidArgumentException(
				json_encode(
					array(
						'error'   => 'missing_required_parameters',
						'site_id' => $this->site_id,
						'details' => 'Required: site_id and site_type',
					)
				)
			);
		}
		if ( ! $this->shell_command ) {
			throw new \InvalidArgumentException(
				json_encode(
					array(
						'error'   => 'missing_required_parameters',
						'site_id' => $this->site_id,
						'details' => 'Required: shell-command',
					)
				)
			);
		}
	}

	/**
	 * Executes the SSH command.
	 *
	 * @param InputInterface  $input  The input interface.
	 * @param OutputInterface $output The output interface.
	 *
	 * @return int Command exit status.
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$emit = $this->emit( $this->site_id );
		try {
			$pressable_site_id = null;
			echo json_encode([
				'site_id' => $this->site_id,
				'site_type' => $this->site_type->value,
				'site_url' => $this->site_url,
				'site_type_enum_pressable' => SiteType::PRESSABLE,
			]);
			if ( SiteType::PRESSABLE === $this->site_type->value ) {
				try {
					$pressable_site = get_pressable_site( $this->site_url );
					if ( ! $pressable_site ) {
						return $emit(
							array(
								'error'   => 'get_pressable_site_not_found',
								'details' => 'Error occurred while fetching Pressable site info for domain: ' . $this->site_url,
							)
						);
					}
					$pressable_site_id = $pressable_site->id;
				} catch ( \Exception $e ) {
					return $emit(
						array(
							'error'   => 'get_pressable_site_failed',
							'details' => 'Error occurred while fetching Pressable site info for domain: ' . $this->site_url,
						)
					);
				}
			}

			$ssh = $this->get_ssh_connection( $this->site_id, $this->site_type->value, $pressable_site_id );
			if ( ! $ssh ) {
				return $emit(
					array(
						'error'   => $this->site_type->value . '_ssh_failed', // wpcom_ssh_failed or pressable_ssh_failed
						'details' => sprintf( 'Could not establish %s SSH connection', strtoupper( $this->site_type->value ) ),
					)
				);
			}

			$ssh->setTimeout( $this->timeout );
			
			$wp_cli_command = sprintf( $this->shell_command );
			$result         = '';

			try {
				$ssh->exec(
					$wp_cli_command,
					function ( $str ) use ( &$result ) {
						$result .= $str;
					}
				);
			} catch ( \Exception $e ) {
				return $emit(
					array(
						'error'   => 'wp_cli_command_failed',
						'details' => $e->getMessage(),
					)
				);
			} finally {
				$ssh->disconnect();
			}

			if ( empty( $result ) ) {
				return $emit(
					array(
						'error'   => 'empty_result',
						'details' => 'No response received from shell command.',
					)
				);
			}

			// Only try to parse JSON if we don't have an error message
			$data = json_decode( $result, true );
			if ( ! $data ) {
				if ( str_contains( $result, 'Fatal error:' ) ) {
					return $emit(
						array(
							'error'   => 'fatal_error',
							'details' => 'Fatal error occurred at source while executing shell command.',
						)
					);
				}
				return $emit(
					array(
						'error'   => 'invalid_json',
						'details' => $result,
					)
				);
			}

			return $emit(
				array(
					'code' => 'success',
					'type' => $this->site_type,
					'data' => $data,
				),
				false
			);
		} catch ( \Exception $e ) {
			return $emit(
				array(
					'error'   => 'unknown_error',
					'details' => $e->getMessage(),
				)
			);
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Closure that buffers messages to output and returns a success or failure status.
	 *
	 * @param string $site_id The site ID.
	 *
	 * @return callable Returns a closure that buffers messages to output and returns a success or failure status.
	 */
	private function emit( $site_id ) {
		return function ( $result, $failed = true ) use ( $site_id ) {
			echo json_encode(
				array(
					...$result,
					'site_id' => $site_id,
				)
			);
			return $failed ? Command::FAILURE : Command::SUCCESS;
		};
	}

	/**
	 * Establishes an SSH connection.
	 *
	 * @param string $site_id      The site ID.
	 * @param string $site_type    The site type ("wpcom" or "pressable").
	 * @param string $pressable_id The Pressable site ID (if applicable).
	 *
	 * @return SSH2|null SSH connection instance or null if unavailable.
	 */
	protected function get_ssh_connection(string $site_id, ?string $pressable_id = null): ?SSH2 {
        return match($this->site_type->value) {
            'wpcom'     => WPCOM_Connection_Helper::get_ssh_connection($site_id),
            'pressable' => $pressable_id ? Pressable_Connection_Helper::get_ssh_connection($pressable_id) : null,
            default     => null,
        };
    }

	// endregion
}
