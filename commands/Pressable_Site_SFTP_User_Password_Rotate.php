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
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Rotates the SFTP password of users on Pressable sites.
 */
#[AsCommand( name: 'pressable:rotate-site-sftp-user-password' )]
final class Pressable_Site_SFTP_User_Password_Rotate extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * Whether processing multiple sites or just a single given one.
	 * Can be one of 'all', 'related', or a comma-separated list of site IDs or domains.
	 *
	 * @var string|null
	 */
	private ?string $multiple = null;

	/**
	 * The sites to rotate the password on.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sites = null;

	/**
	 * The SFTP users to rotate the password for.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $sftp_users = null;

	/**
	 * Whether to actually rotate the password or just simulate doing so.
	 *
	 * @var bool|null
	 */
	private ?bool $dry_run = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Rotates the SFTP user password of a given user on Pressable sites.' )
			->setHelp( 'This command allows you to rotate the SFTP password of users on Pressable sites.' );

		$this->addArgument( 'site', InputArgument::OPTIONAL, 'The domain or numeric Pressable ID of the site on which to rotate the SFTP user password.' )
			->addOption( 'user', 'u', InputOption::VALUE_REQUIRED, 'The ID, email, or username of the site SFTP user for which to rotate the password. The default is concierge@wordpress.com.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Determines whether the \'site\' argument is optional or not. Accepted values are \'related\', \'all\', or a comma-separated list of site IDs or domains.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Execute a dry run. It will output all the steps, but will keep the current SFTP user password. Useful for checking whether a given input is valid.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Retrieve and validate the modifier options.
		$this->dry_run  = (bool) $input->getOption( 'dry-run' );
		$this->multiple = $input->getOption( 'multiple' );

		// If processing a given site, retrieve it from the input.
		$site = match ( true ) {
			'all' === $this->multiple => null,
			'related' === $this->multiple => get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) ),
			null !== $this->multiple => null,
			default => get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) ),
		};
		$input->setArgument( 'site', $site );

		// If processing a given user, retrieve it from the input.
		$user = match ( true ) {
			'all' === $this->multiple => get_email_input( $input, fn() => $this->prompt_user_input( $input, $output ), 'user' ),
			'related' === $this->multiple => get_pressable_site_sftp_user_input( $input, $input->getArgument( 'site' )->id, fn() => $this->prompt_user_input( $input, $output ) ),
			null !== $this->multiple => get_email_input( $input, fn() => $this->prompt_user_input( $input, $output ), 'user' ),
			default => get_pressable_site_sftp_user_input( $input, $input->getArgument( 'site' )->id, fn() => $this->prompt_user_input( $input, $output ) ),
		};
		$input->setOption( 'user', $user->email ?? $user );

		// Compile the sites and users to process.
		if ( \is_null( $this->multiple ) ) { // One single, given website.
			$this->sites      = array( $site );
			$this->sftp_users = array( $user );
		} else { // Multiple sites.
			if ( 'related' === $this->multiple ) {
				$this->sites = get_pressable_related_sites( $site->id );
				$this->sites = \array_merge( ...$this->sites ); // Flatten out the related websites tree.
			} elseif ( 'all' === $this->multiple ) {
				$this->sites = get_pressable_sites();
			} else {
				$this->sites = $this->get_sites_from_multiple_input();
			}

			$output->writeln( '<info>Compiling list of Pressable SFTP users...</info>' );
			$progress_bar = new ProgressBar( $output, \count( $this->sites ) );
			$progress_bar->start();

			$this->sftp_users = \array_map(
				static function ( object $site ) use ( $user, $progress_bar ): ?\stdClass {
					$progress_bar->advance();
					return get_pressable_site_sftp_user( $site->id, $user->email ?? $user );
				},
				$this->sites
			);

			$progress_bar->finish();
			$output->writeln( '' ); // Empty line for UX purposes.
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		switch ( true ) {
			case 'all' === $this->multiple:
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP user password of {$input->getOption( 'user' )} on <fg=red;options=bold>ALL</> sites? [y/N]</question> ", false );
				break;
			case 'related' === $this->multiple:
				output_pressable_related_sites( $output, get_pressable_related_sites( $this->sites[0]->id ) );
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP user password of {$input->getOption( 'user' )} on all the sites listed above? [y/N]</question> ", false );
				break;
			case null !== $this->multiple:
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP user password of {$input->getOption( 'user' )} on <fg=red;options=bold>" . count( $this->sites ) . ' selected</> sites? [y/N]</question> ', false );
				break;
			default:
				$question = new ConfirmationQuestion( "<question>Are you sure you want to rotate the SFTP user password of {$this->sftp_users[0]->username} (ID {$this->sftp_users[0]->id}, email {$this->sftp_users[0]->email}) on {$this->sites[0]->displayName} (ID {$this->sites[0]->id}, URL {$this->sites[0]->url})? [y/N]</question> ", false );
		}

		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}

		if ( 'all' === $this->multiple && false === $this->dry_run ) {
			$question = new ConfirmationQuestion( '<question>This is <fg=red;options=bold>NOT</> a dry run. Are you sure you want to continue rotating the password of the SFTP user on all sites? [y/N]</question> ', false );
			if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
				$output->writeln( '<comment>Command aborted by user.</comment>' );
				exit( 2 );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		foreach ( $this->sites as $index => $site ) {
			$sftp_user = $this->sftp_users[ $index ];
			if ( \is_null( $sftp_user ) ) { // This can only happen if we're processing multiple sites. For single sites, the command would've aborted during initialization.
				$output->writeln( "<error>Could not find the SFTP user {$input->getOption( 'user' )} on site $site->displayName (ID $site->id, URL $site->url). Skipping...</error>" );
				continue;
			}

			$output->writeln( "<fg=magenta;options=bold>Rotating the SFTP user password of $sftp_user->username (ID $sftp_user->id, email $sftp_user->email) on $site->displayName (ID $site->id, URL $site->url).</>" );

			// Rotate the SFTP user password.
			$credentials = $this->rotate_site_sftp_user_password( $output, $site->id, $sftp_user->username );
			if ( \is_null( $credentials ) ) {
				$output->writeln( '<error>Failed to rotate the SFTP user password.</error>' );
				continue;
			}

			/* @noinspection DisconnectedForeachInstructionInspection */
			$output->writeln( '<fg=green;options=bold>SFTP user password rotated.</>' );
			$output->writeln( "<comment>New SFTP user password:</comment> <fg=green;options=bold>$credentials->password</>" );
		}

		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user for a site.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the site ID or URL to rotate the SFTP user password on:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for an email for the SFTP user.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_user_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the email of the SFTP user to rotate the password for [concierge@wordpress.com]:</question> ', 'concierge@wordpress.com' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			if ( is_null( $this->multiple ) || 'related' === $this->multiple ) {
				$question->setAutocompleterValues( \array_map( static fn( object $user ) => $user->email, get_pressable_site_sftp_users( $input->getArgument( 'site' )->id ) ?? array() ) );
			}
			// For 'all' or custom list of sites, we don't provide autocomplete suggestions
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Rotates the SFTP user password of a given user on a given site.
	 *
	 * @param   OutputInterface $output   The output interface.
	 * @param   string          $site_id  The ID of the site to rotate the SFTP user password on.
	 * @param   string          $username The username of the SFTP user to rotate the password for.
	 *
	 * @return  \stdClass|null
	 */
	private function rotate_site_sftp_user_password( OutputInterface $output, string $site_id, string $username ): ?\stdClass {
		if ( true === $this->dry_run ) {
			$credentials = (object) array(
				'username' => $username,
				'password' => '********',
			);
			$output->writeln( '<comment>Dry run: SFTP user password rotation skipped.</comment>', OutputInterface::VERBOSITY_VERBOSE );
		} else {
			$credentials = rotate_pressable_site_sftp_user_password( $site_id, $username );
		}

		return $credentials;
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
