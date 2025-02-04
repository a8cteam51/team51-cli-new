<?php
/**
 * SSH Worker Command
 *
 * Handles SSH execution for WordPress CLI operations.
 */
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
 * SSH Worker Command
 */
#[AsCommand( name: 'ssh-worker', hidden: true )]
class SSH_Worker extends Command {

	/**
	 * Configures the Symfony Console command.
	 */
	protected function configure(): void {
		$this
			->setDescription('SSH Worker for processing WordPress CLI commands')
			->addOption('site-id', null, InputOption::VALUE_REQUIRED, 'The site ID')
			->addOption('site-type', null, InputOption::VALUE_REQUIRED, 'Site type (wpcom or pressable)')
			->addOption('email', null, InputOption::VALUE_REQUIRED, 'The email to query via WP CLI')
			->addOption('site-url', null, InputOption::VALUE_OPTIONAL, 'The site url');
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
		$site_id = $input->getOption('site-id');
		$site_type = $input->getOption('site-type');
		$email = $input->getOption('email');
		$site_url = $input->getOption('site-url');

		if (!$site_id || !$site_type || !$email) {
			echo json_encode([
				'error'   => 'missing_required_parameters',
				'site_id' => $site_id,
				'details' => 'Required: site_id, site_type, email'
			]);
			return Command::FAILURE;
		}

		try {
			$pressable_site_id = null;
			if ($site_type !== 'wpcom') {
				$domain = parse_url($site_url, PHP_URL_HOST);
				try {
					$pressable_site = get_pressable_site($site_url);
					if (!$pressable_site) {
						echo json_encode([
							'error'   => 'get_pressable_site_not_found',
							'site_id' => $site_id,
							'details' => 'Error occurred while fetching Pressable site info for domain: ' . $domain
						]);
						return Command::FAILURE;
					}
					$pressable_site_id = $pressable_site->id;
				} catch (\Exception $e) {
					echo json_encode([
						'error'   => 'get_pressable_site_failed',
						'site_id' => $site_id,
						'details' => 'Error occurred while fetching Pressable site info for domain: ' . $domain
					]);
					return Command::FAILURE;
				}
			}

			$ssh = $this->get_ssh_connection($site_id, $site_type, $pressable_site_id);
			if (!$ssh) {
				echo json_encode([
					'error'   => $site_type . '_ssh_failed', // wpcom_ssh_failed or pressable_ssh_failed
					'site_id' => $site_id,
					'details' => sprintf('Could not establish %s SSH connection', strtoupper($site_type))
				]);
				return Command::FAILURE;
			}

			$ssh->setTimeout(60);
			// TODO: Make dynamic.
			$wp_cli_command = sprintf('wp user get %s --fields=ID,email --format=json', escapeshellarg($email));
			$result = '';
			
			try {
				$ssh->exec($wp_cli_command, function($str) use (&$result) {
					$result .= $str;
				});
			} catch (\Exception $e) {
				echo json_encode([
					'error'   => 'wp_cli_command_failed',
					'site_id' => $site_id,
					'details' => $e->getMessage()
				]);
				return Command::FAILURE;
			} finally {
				$ssh->disconnect();
			}

			// TODO: Make dynamic.
			if (str_contains($result, 'Error: Invalid user')) {
				echo json_encode([
					'code'    => 'user_not_found',
					'site_id' => $site_id,
					'details' => 'User not found'
				]);
				return Command::SUCCESS;
			}

			if (empty($result)) {
				echo json_encode([
					'error'   => 'empty_result',
					'site_id' => $site_id,
					'details' => 'No response received from WP-CLI command.'
				]);
				return Command::FAILURE;
			}

			// Only try to parse JSON if we don't have an error message
			$data = json_decode($result, true);
			// E.g. PHP errors from the SSH connection.
			if (!$data) {
				echo json_encode([
					'error'   => 'invalid_json',
					'site_id' => $site_id,
					'details' => $result
				]);
				return Command::FAILURE;
			}

			echo json_encode([
				'code'    => 'success',
				'site_id' => $site_id,
				'type'    => $site_type,
				'data'    => $data
			]);

			return Command::SUCCESS;

		} catch (\Exception $e) {
			echo json_encode([
				'error'   => 'unknown_error',
				'site_id' => $site_id,
				'details' => $e->getMessage()
			]);
			return Command::FAILURE;
		}
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
	protected function get_ssh_connection( string $site_id, string $site_type, ?string $pressable_id = null ): ?SSH2 {
		if ( 'wpcom' === $site_type ) {
			return WPCOM_Connection_Helper::get_ssh_connection( $site_id );
		}

		if ( $pressable_id ) {
			return Pressable_Connection_Helper::get_ssh_connection( $pressable_id );
		}

		return null;
	}
}