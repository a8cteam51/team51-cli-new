<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

use WPCOMSpecialProjects\CLI\Helper\{
	AutocompleteTrait, 
	Parallel_Process
};

/**
 * Deletes a WP user from WPCOM sites.
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
 */
#[AsCommand( name: 'wpcom:delete-site-wp-user' )]
final class WPCOM_Site_WP_User_Delete extends Command {
	use AutocompleteTrait;
	// region FIELDS AND CONSTANTS

	/**
	 * The email address of the user to delete.
	 *
	 * @var string|null
	 */
	private ?string $email = null;

	/**
	 * The list of user objects to process.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $users = null;

	/**
	 * The list of user objects to process using SSH to connect to the site.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $ssh_users = null;

	/**
	 * Whether to actually delete the user or just simulate doing so.
	 *
	 * @var bool|null
	 */
	private ?bool $dry_run = null;

	/**
	 * The timeout, in seconds, for the SSH connection process to run.
	 *
	 * @var int|null
	 */
	private ?int $ssh_timeout = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Deletes a WP user from WPCOM sites.' )
			->setHelp( 'Use this command to delete a WP user from WPCOM sites.' );

		$this->addArgument( 'email', InputArgument::REQUIRED, 'The email address of the user to delete.' )
			->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or WPCOM ID of the site to delete the user from.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the `site` argument is optional or not. Accepted values are `all` or a comma-separated list of site IDs or domains.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Perform a dry run without actually deleting users' )
			->addOption( 'ssh-timeout', null, InputOption::VALUE_OPTIONAL, 'Timeout, in seconds, for the SSH connection process to run.', 60 );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Retrieve the user email.
		$this->email = get_email_input( $input, fn() => $this->prompt_email_input( $input, $output ) );
		$input->setArgument( 'email', $this->email );

		// Retrieve the dry run option.
		$this->dry_run = get_bool_input( $input, 'dry-run' );

		// If processing a given site, retrieve it from the input.
		$multiple = $input->getOption( 'multiple' );
		if ( 'all' !== $multiple ) {
			if ( $multiple ) {
				$sites = $this->get_sites_from_multiple_input( $multiple, $output );
			} else {
				$site = get_wpcom_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
				$input->setArgument( 'site', $site );
				$sites = array( $site->ID => $site );
			}
		} else {
			$sites = $this->get_filtered_wpcom_sites();
		}

		// Compile the list of users to process.
		$this->users = get_wpcom_site_users_batch(
			array_column( $sites, 'ID' ),
			array_combine(
				array_column( $sites, 'ID' ),
				array_fill(
					0,
					count( $sites ),
					array(
						'search'         => $this->email,
						'search_columns' => 'user_email',
						'fields'         => 'ID,email,site_ID',
					)
				)
			),
			$errors
		);

		if ( $errors ) {
			$number_of_errors = count( $errors );
			$output->writeln( "<comment>There are $number_of_errors sites that could NOT be searched.</comment>" );
			$output->writeln( '<fg=magenta;options=bold>Trying to connect to those sites using SSH.</>' );

			$errors = $this->get_user_using_ssh( $output, $errors, $sites );
		}

		maybe_output_wpcom_failed_sites_table( $output, $errors, $sites, 'Sites that could NOT be searched' );

		$this->users = \array_filter(
			\array_map(
				static function ( string $site_id, mixed $site_users ) use ( $sites ) {
					if ( ! \is_array( $site_users ) || empty( $site_users ) ) {
						return null;
					}

					$site = $sites[ $site_id ];
					$user = \current( $site_users );

					return (object) \array_merge(
						(array) $user,
						array(
							'site_ID'  => $site->ID,
							'site_URL' => $site->URL,
						)
					);
				},
				\array_keys( $this->users ),
				$this->users
			)
		);

		if ( empty( $this->users ) ) {
			$output->writeln( '<error>No users found with the given email address.</error>' );
			exit( 1 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		output_table(
			$output,
			array_map(
				static fn( \stdClass $user ) => array(
					$user->site_ID,
					$user->site_URL,
					$user->ID,
				),
				$this->users
			),
			array( 'Site ID', 'Site URL', 'WP User ID' ),
			'WPCOM sites on which the user was found'
		);

		$question = new ConfirmationQuestion( "<question>Are you sure you want to delete the user $this->email from all the sites above? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$action_verb = $this->dry_run ? 'Would delete' : 'Deleting';

		$output->writeln( "<fg=magenta;options=bold>$action_verb user `$this->email` from " . count( $this->users ) . ' WPCOM site(s).</>' );

		foreach ( $this->users as $user ) {
			if ( isset( $this->ssh_users[ $user->site_ID ] ) ) {
				$ssh_user_data = $this->ssh_users[ $user->site_ID ];
				$output->writeln( "<fg=magenta;options=bold>Connecting to site ID {$ssh_user_data['id']} using SSH.</>" );

				if ( $this->dry_run ) {
					$output->writeln( "<comment>Dry run: Would delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID) using SSH.</comment>", OutputInterface::VERBOSITY_VERBOSE );
					continue;
				}

				$ssh = 'pressable' === $ssh_user_data['type'] ? \Pressable_Connection_Helper::get_ssh_connection( $ssh_user_data['id'] ) : \WPCOM_Connection_Helper::get_ssh_connection( $ssh_user_data['id'] );

				if ( ! $ssh ) {
					$output->writeln( "<error>Failed to connect to site ID {$ssh_user_data['id']} using SSH.</error>" );
					continue;
				}

				try {
					$ssh->setTimeout( 0 ); // Disable timeout in case the command takes a long time.
					$ssh->exec(
						"wp user delete $user->ID --yes",
						function ( string $str ) use ( $output ): void {
							$GLOBALS['wp_cli_output'] = $str;
						}
					);

					if ( ! is_string( $GLOBALS['wp_cli_output'] ) || ! str_contains( $GLOBALS['wp_cli_output'], 'Success' ) ) {
						$output->writeln( "<error>Failed to delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID).</error>" );
						continue;
					}

					$ssh->disconnect();
				} catch ( \RuntimeException $exception ) {
					$output->writeln( "<error>SSH command failed for site ID {$user->site_ID}: {$exception->getMessage()}</error>" );
					continue;
				}
			} else {
				if ( $this->dry_run ) {
					$output->writeln( "<comment>Dry run: Would delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID).</comment>", OutputInterface::VERBOSITY_VERBOSE );
					continue;
				}

				$result = delete_wpcom_site_user( $user->site_ID, $user->ID );
				if ( true !== $result ) {
					$output->writeln( "<error>Failed to delete user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID).</error>" );
					continue;
				}
			}

			$output->writeln( "<fg=green;options=bold>Deleted user $user->ID from WPCOM site $user->site_URL (ID $user->site_ID) successfully.</>" );
		}

		if ( $this->dry_run ) {
			$output->writeln( '<info>Dry run completed. No users were actually deleted.</info>', OutputInterface::VERBOSITY_VERBOSE );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for an email address.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_email_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the email address of the user to delete:</question> ' );
		$question->setValidator( fn( $value ) => filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : throw new \RuntimeException( 'Invalid email address.' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or WPCOM site ID to remove the user from:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues(
				\array_map(
					static fn( string $url ) => \parse_url( $url, PHP_URL_HOST ),
					\array_column( get_wpcom_sites( array( 'fields' => 'ID,URL' ) ) ?? array(), 'URL' )
				)
			);
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	private function get_user_using_ssh(OutputInterface $output, array $sites_with_errors, array $sites): array {
		$email        			= $this->email;
		$sites_by_id 			= array_column( $sites, null, 'ID' );
		// @TODO Remove once dev is over.
		$site_ids_with_errors   = array_filter(array_keys($sites_with_errors), function ($id) use ($sites_by_id){
			return str_contains($sites_by_id[$id]->URL, 'mystagingwebsite.com');
		});
		$sites_index = array_filter(array_column( $sites, null, 'ID' ), function($site) use ($site_ids_with_errors) {
			return in_array($site->ID, $site_ids_with_errors);
		});
		$users = &$this->users;
		$ssh_users = &$this->ssh_users;

		return Parallel_Process::create($output, $site_ids_with_errors)
			->configure([
				'max_processes' => 5,
				'max_threads'   => 20,
				'ssh_timeout'   => $this->ssh_timeout
			])
			->add_callback('shell_command', static function() use ($email): string {
				return sprintf(
					'wp user get %s --fields=ID,email --format=json',
					escapeshellarg($email)
				);
			})
			->add_callback('command_args', static function(int $site_id) use ($sites_index): string {
				$site = $sites_index[$site_id];
				return sprintf(
					'--site-id=%s --site-type=%s --site-url=%s', 
					escapeshellarg($site_id),
					$site->is_wpcom_atomic ? 'wpcom' : 'pressable',
					escapeshellarg($site->URL)
				);
			})
			->add_callback('parse_result', static function(array $result) use ($sites_index, $output): array {
				$site_url = $sites_index[$result['site_id']]->URL;
				// Test for invalid users.
				if (isset($result['details']) && str_contains($result['details'], 'Error: Invalid user')) {
					return [
						'code'    => 'user_not_found',
						'site_id' => $result['site_id'],
						'details' => sprintf('User not found on %s', $site_url)
					];
				}
				// Test for valid users.
				if (isset($result['code']) && $result['code'] === 'success') {
					return [
						'code'              => 'user_found',
						'site_id'           => $result['site_id'],
						'details'           => $result['data'],
						'type'              => $result['type'],
						'pressable_site_id' => $result['pressable_site_id']
					];
				}
				return $result;
			})
			->add_callback('process_complete', static function(
				int $site_id,
				int $completed,
				int $task_count,
				mixed $result
			) use ($output, $sites_index, &$users, &$ssh_users): void {
				$site_url     = $sites_index[ $site_id ]->URL;
				$result_code  = $result['code'] ?? null;
				$result_error = $result['error'] ?? null;

				if ( $result_code === 'user_found' ) {
					$users[ $site_id ] = [
						(object) [
							'ID'       => $result[ 'details' ]['ID'],
							'site_ID'  => $site_id,
							'site_URL' => $site_url,
						]
					];
					$ssh_users[ $site_id ] = [
						'type' => $result['type'],
						'id'   => 'pressable' === $result['type'] ? $result['pressable_site_id'] : $result['site_id']
					];
				}

				$progress_percentage = intval(round(($completed / $task_count) * 100));
				$status_icon         = $result_error ? '❌' : '✅';
				$user_status         = $result_code === 'user_found' ? '🔎 User found' : '😭 User not found';

				$output->writeln(sprintf(
					'🔄 Progress: %d%% (%d/%d) / Site ID: %s / Status: %s [[%s]]' . PHP_EOL . '[[%s - [%s] - [%s]]]' . PHP_EOL . PHP_EOL . '--------------------------------' . PHP_EOL,
					$progress_percentage,
					$completed,
					$task_count,
					$site_id,
					$status_icon,
					$sites_index[$site_id]->URL,
					$user_status,
					$result_code,
					$result_error,
				));
			})
			->process_tasks();
	}

	/**
	 * Get sites from the multiple input option.
	 *
	 * @param   string          $multiple The multiple input option.
	 * @param   OutputInterface $output   The output interface.
	 *
	 * @return  array
	 */
	private function get_sites_from_multiple_input( string $multiple, OutputInterface $output ): array {
		$site_list = explode( ',', $multiple );
		$sites     = array();
		foreach ( $site_list as $site_identifier ) {
			$site = get_wpcom_site( trim( $site_identifier ) );
			if ( $site ) {
				$sites[ $site->ID ] = $site;
			} else {
				$output->writeln( "<error>Invalid site identifier: $site_identifier</error>" );
			}
		}

		return $sites;
	}

	/**
	 * Get filtered WPCOM sites, excluding specific domains.
	 *
	 * @return array Filtered WPCOM sites.
	 */
	private function get_filtered_wpcom_sites(): array {
		return \array_filter(
			get_wpcom_sites( array( 'fields' => 'ID,URL,is_wpcom_atomic' ) ),
			static function ( \stdClass $site ) {
				$exclude_sites = array( 'woocommerce.com', 'woo.com' );
				$site_domain   = \parse_url( $site->URL, PHP_URL_HOST );

				return ! \in_array( $site_domain, $exclude_sites, true );
			}
		);
	}

	// endregion
}
