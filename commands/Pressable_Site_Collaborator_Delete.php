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
 * Deletes a collaborator from Pressable sites.
 */
#[AsCommand( name: 'pressable:delete-site-collaborator' )]
final class Pressable_Site_Collaborator_Delete extends Command {
	use AutocompleteTrait;

	// region FIELDS AND CONSTANTS

	/**
	 * The email address of the collaborator to delete.
	 *
	 * @var string|null
	 */
	private ?string $email = null;

	/**
	 * The list of collaborator objects to process.
	 *
	 * @var \stdClass[]|null
	 */
	private ?array $collaborators = null;

	/**
	 * Whether to also delete the WordPress user associated with the collaborator.
	 *
	 * @var bool|null
	 */
	private ?bool $delete_wp_user = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Deletes a collaborator from Pressable sites.' )
			->setHelp( 'Use this command to delete a collaborator from Pressable sites.' );

		$this->addArgument( 'email', InputArgument::REQUIRED, 'The email address of the collaborator to delete.' )
			->addArgument( 'site', InputArgument::OPTIONAL, 'The site ID or domain to delete the collaborator from.' );

		$this->addOption( 'multiple', null, InputOption::VALUE_REQUIRED, 'Process multiple sites. Use `all` for all sites, `related` for related sites, or a comma-separated list of site IDs or domains.' )
			->addOption( 'delete-wp-user', null, InputOption::VALUE_NONE, 'Also delete the WordPress user associated with the collaborator.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->delete_wp_user = (bool) $input->getOption( 'delete-wp-user' );

		// Retrieve the collaborator email.
		$this->email = get_email_input( $input, fn() => $this->prompt_email_input( $input, $output ) );
		$input->setArgument( 'email', $this->email );

		// Process the multiple option
		$multiple = $input->getOption( 'multiple' );
		if ( null === $multiple ) {
			$site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
			$input->setArgument( 'site', $site );
			$sites = array( $site );
		} elseif ( 'all' === $multiple ) {
			$sites = get_pressable_sites();
		} elseif ( 'related' === $multiple ) {
			$site  = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
			$sites = array_merge( ...get_pressable_related_sites( $site->id ) );
		} else {
			$sites = $this->get_sites_from_multiple_input( $multiple );
		}

		// Compile the list of collaborators to process.
		$this->collaborators = array_filter(
			get_pressable_collaborators() ?? array(),
			function ( \stdClass $collaborator ) use ( $sites ) {
				$is_email_match = is_case_insensitive_match( $collaborator->email, $this->email );
				$is_site_match  = \is_null( $sites ) || \in_array( $collaborator->siteId, \array_column( $sites, 'id' ), true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

				return $is_email_match && $is_site_match;
			}
		);
		if ( empty( $this->collaborators ) ) {
			$output->writeln( '<error>No collaborators found with the given email address.</error>' );
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
				static fn( \stdClass $collaborator ) => array(
					// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$collaborator->siteId,
					get_pressable_site( $collaborator->siteId )->url,
					$collaborator->wpUsername ?? '',
					// phpcs:enable
				),
				$this->collaborators
			),
			array( 'Site ID', 'Site URL', 'WP Username' ),
			'Pressable sites on which the collaborator was found'
		);

		$wp_user_text = $this->delete_wp_user ? 'and WordPress user' : 'and <fg=red;options=bold>keep</> the WordPress user';
		$question     = new ConfirmationQuestion( "<question>Are you sure you want to delete the collaborator $wp_user_text $this->email from all the sites above? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$wp_user_text = $this->delete_wp_user ? 'and WordPress user' : 'and <fg=red;options=bold>keep</> the WordPress user';
		$output->writeln( "<fg=magenta;options=bold>Deleting collaborator $wp_user_text `$this->email` from " . count( $this->collaborators ) . ' Pressable site(s).</>' );

		foreach ( $this->collaborators as $collaborator ) {
			$result = delete_pressable_site_collaborator( $collaborator->siteId, $collaborator->id, $this->delete_wp_user ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( true !== $result ) {
				$output->writeln( "<error>Failed to delete collaborator $collaborator->id from Pressable site $collaborator->siteName (ID $collaborator->siteId).</error>" );
				continue;
			}

			$output->writeln( "<fg=green;options=bold>Deleted collaborator $collaborator->id from Pressable site $collaborator->siteName (ID $collaborator->siteId) successfully.</>" );
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
		$question = new Question( '<question>Enter the email address of the collaborator to delete:</question> ' );
		$question->setValidator( fn( $value ) => filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : throw new \RuntimeException( 'Invalid email address.' ) );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_pressable_collaborators() ?? array(), 'email' ) );
		}

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
		$question = new Question( '<question>Enter the site ID or URL to delete the collaborator from:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Get sites from the multiple input option.
	 *
	 * @param   string $multiple The multiple input option.
	 *
	 * @return array
	 */
	private function get_sites_from_multiple_input( string $multiple ): array {
		$site_identifiers = array_map( 'trim', explode( ',', $multiple ) );
		return array_filter( array_map( fn( $identifier ) => get_pressable_site( $identifier ), $site_identifiers ) );
	}

	// endregion
}
