<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Runs a given WP-CLI command on a given Pressable site.
 */
#[AsCommand( name: 'pressable:run-site-wp-cli-command' )]
final class Pressable_Site_WP_CLI_Command_Run extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Whether processing multiple sites or just a single given one.
	 * Can be one of 'all' or a comma-separated list of site IDs or domains.
	 *
	 * @var string|null
	 */
	private ?string $multiple = null;

	/**
	 * Pressable site definition to run the WP CLI command on.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sites = null;

	/**
	 * The WP-CLI command to run.
	 *
	 * @var string|null
	 */
	private ?string $wp_command = null;

	/**
	 * Whether to skip outputting the response to the console.
	 *
	 * @var bool|null
	 */
	private ?bool $skip_output = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Runs a given WP-CLI command on a given Pressable site.' )
			->setHelp( 'This command allows you to run an arbitrary WP-CLI command on a Pressable site.' );

		$this->addArgument( 'wp-cli-command', InputArgument::REQUIRED, 'The WP-CLI command to run.' )
			->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or numeric Pressable ID of the site to open the shell to.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the `site` argument is optional or not. Accepted values are `all` or a comma-separated list of site IDs or domains.' )
			->addOption( 'skip-output', null, InputOption::VALUE_NONE, 'Skip outputting the response to the console.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->skip_output = get_bool_input( $input, 'skip-output' );
		$this->multiple    = $input->getOption( 'multiple' );

		if ( null === $this->multiple ) {
			// If multiple is not set, treat it as a single site operation
			$site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
			$input->setArgument( 'site', $site );
			$this->sites = array( $site );
		} elseif ( 'all' === $this->multiple ) {
			$this->sites = get_pressable_sites();
		} else {
			$this->sites = $this->get_sites_from_multiple_input();
		}

		$this->wp_command = get_string_input( $input, 'wp-cli-command', fn() => $this->prompt_command_input( $input, $output ) );
		$this->wp_command = \trim( \preg_replace( '/^wp/', '', \trim( $this->wp_command ) ) );
		if ( false === \str_contains( $this->wp_command, 'eval' ) ) {
			$this->wp_command = \escapeshellcmd( $this->wp_command );
		}
		$input->setArgument( 'wp-cli-command', $this->wp_command );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = match ( true ) {
			'all' === $this->multiple => new ConfirmationQuestion( "<question>Are you sure you want to run the command `wp $this->wp_command` on <fg=red;options=bold>ALL</> Pressable sites? [y/N]</question> ", false ),
			null !== $this->multiple => new ConfirmationQuestion( "<question>Are you sure you want to run the command `wp $this->wp_command` on <fg=red;options=bold>" . count( $this->sites ) . ' selected</> Pressable sites? [y/N]</question> ', false ),
			default => new ConfirmationQuestion( "<question>Are you sure you want to run the command `wp $this->wp_command` on {$this->sites[0]->displayName} (ID {$this->sites[0]->id}, URL {$this->sites[0]->url})? [y/N]</question> ", false ),
		};

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		foreach ( $this->sites as $site ) {
			$output->writeln( "<fg=magenta;options=bold>Running the command `wp $this->wp_command` on $site->displayName (ID $site->id, URL $site->url).</>" );

			$ssh = \Pressable_Connection_Helper::get_ssh_connection( $site->id );
			if ( \is_null( $ssh ) ) {
				$output->writeln( '<error>Could not connect to the SSH server.</error>' );
				continue;
			}

			/* @noinspection DisconnectedForeachInstructionInspection */
			$output->writeln( '<fg=green;options=bold>SSH connection established.</>', OutputInterface::VERBOSITY_VERBOSE );

			try {
				$ssh->setTimeout( 0 ); // Disable timeout in case the command takes a long time.
				$ssh->exec(
					"wp $this->wp_command",
					function ( string $str ): void {
						$GLOBALS['wp_cli_output'] = $str;
						if ( ! $this->skip_output ) {
							echo "$str\n";
						}
					}
				);
			} catch ( \RuntimeException $exception ) {
				$output->writeln( "<error>Something went wrong. Please double-check if things worked out. This is what we know: {$exception->getMessage()}</error>" );
				continue;
			} finally {
				$ssh->disconnect();
			}
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the domain or Pressable site ID to run the WP-CLI command on:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a WP-CLI command.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_command_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the WP-CLI command to run:</question> ' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Get sites from the multiple input option.
	 *
	 * @return array
	 */
	private function get_sites_from_multiple_input(): array {
		$site_identifiers = array_map( 'trim', explode( ',', $this->multiple ) );
		return array_filter( array_map( fn( $identifier ) => get_pressable_site( $identifier ), $site_identifiers ) );
	}

	// endregion
}
