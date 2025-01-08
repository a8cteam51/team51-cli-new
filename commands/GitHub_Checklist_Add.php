<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Adds a launch checklist to a GitHub repository.
 */
#[AsCommand( name: 'github:add-checklist' )]
final class GitHub_Checklist_Add extends Command {
	use AutocompleteTrait;

	/**
	 * The checklists repository.
	 *
	 * @var string
	 */
	private const CHECKLISTS_REPOSITORY_URL = 'git@github.com:a8cteam51/special-projects-checklists.git';

	/**
	 * Checklists that can be created.
	 *
	 * @var array
	 */
	private const CHECKLISTS = array(
		'launch'                => 'Launch',
		'migrate-pressable-dns' => 'DNS Migration to Pressable',
		'qa-engineer'           => 'QA Engineer',
	);

	/**
	 * Conditional tags used for optional sections in the checklist.
	 *
	 * @var array
	 */
	private const CONDITIONAL_TAGS = array(
		'a8c'                          => array(
			'question'    => 'Is this an a8c property or product website?',
			'description' => 'This site is an a8c property or product website.',
		),
		'partner-pays-wpcom'           => array(
			'question'    => 'Will the partner be paying WordPress.com for hosting?',
			'description' => 'The partner will be paying WordPress.com for hosting.',
		),
		'retain-jetpack-likes'         => array(
			'question'    => 'Will Jetpack likes need to be retained during migration?',
			'description' => 'Jetpack likes will need to be retained during migration.',
		),
		'sensei'                       => array(
			'question'    => 'Does the site have Sensei installed?',
			'description' => 'The site has Sensei installed.',
		),
		'woocommerce'                  => array(
			'question'    => 'Does the site have WooCommerce installed?',
			'description' => 'The site has WooCommerce installed.',
		),
		'wpcom-to-pressable-migration' => array(
			'question'    => 'Has the site previously been hosted on WordPress.com or WordPress VIP?',
			'description' => 'The site has previously been hosted on WordPress.com or WordPress VIP.',
		),
	);

	/**
	 * Hosting platforms. Used for the [host:*] tags.
	 *
	 * @var array
	 */
	private const HOSTS = array(
		'pressable'    => 'Pressable',
		'wpcom-atomic' => 'WordPress.com Atomic',
		'wpcom-simple' => 'WordPress.com Simple',
	);

	/**
	 * The checklist to add.
	 *
	 * @var string
	 */
	private ?string $checklist = null;

	/**
	 * The checklist contents.
	 *
	 * @var string
	 */
	private ?string $checklist_text = null;

	/**
	 * The repository to add the checklist to.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $gh_repository = null;

	/**
	 * The host of the site.
	 *
	 * @var string|null
	 */
	protected ?string $host = null;

	/**
	 * The conditional tags.
	 *
	 * @var array
	 */
	protected array $conditional_tags = array();

	/**
	 * {@inheritDoc}
	 */
	protected function configure() {
		$this
			->setDescription( 'Adds a checklist to a GitHub repository.' )
			->setHelp( 'This command adds a checklist to a GitHub repository.' )
			->addArgument( 'checklist', InputArgument::REQUIRED, sprintf( 'The checklist to add. (%s)', implode( ', ', array_keys( self::CHECKLISTS ) ), 'launch', array_keys( self::CHECKLISTS ) ) )
			->addArgument( 'repository', InputArgument::REQUIRED, 'The slug of the repository to add the checklist to.' )
			->addArgument( 'host', InputArgument::REQUIRED, sprintf( 'The hosting platform of the site. (%s)', implode( ', ', array_keys( self::HOSTS ) ), 'pressable', array_keys( self::HOSTS ) ) );

		foreach ( self::CONDITIONAL_TAGS as $tag => $details ) {
			$this->addOption( $tag, null, InputOption::VALUE_NONE, $details['description'] );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// We will only ask for tags if arguments are not provided.
		$has_args = $input->getArgument( 'checklist' ) && $input->getArgument( 'repository' ) && $input->getArgument( 'host' );

		// Get the checklist.
		$this->checklist = get_enum_input( $input, 'checklist', array_keys( self::CHECKLISTS ), fn() => $this->prompt_checklist_input( $input, $output ) );
		$input->setArgument( 'checklist', $this->checklist );

		// Retrieve the repository.
		while ( ! $this->gh_repository ) {
			$this->gh_repository = get_github_repository_input( $input, fn() => $this->prompt_repository_input( $input, $output ) );
		}

		$input->setArgument( 'repository', $this->gh_repository );

		$this->host = get_enum_input( $input, 'host', array_keys( self::HOSTS ), fn() => $this->prompt_host_input( $input, $output ) );
		$input->setArgument( 'host', $this->host );

		// Check the conditional tags that are in the actual checklist on the repository.
		$this->checklist_text = $this->get_checklist( $this->checklist, $output );

		foreach ( self::CONDITIONAL_TAGS as $tag => $details ) {
			if ( $has_args ) {
				$this->conditional_tags[ $tag ] = get_bool_input( $input, $tag );
			} elseif ( str_contains( $this->checklist_text, '[' . $tag . ']' ) ) {
				$question                       = new ConfirmationQuestion( "<question>{$details['question']} [y/N]</question> ", false );
				$this->conditional_tags[ $tag ] = $this->getHelper( 'question' )->ask( $input, $output, $question );
			} else {
				$this->conditional_tags[ $tag ] = false;
			}
			$input->setOption( $tag, $this->conditional_tags[ $tag ] );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$tags     = implode( ', ', array_keys( array_filter( $this->conditional_tags ) ) );
		$question = new ConfirmationQuestion( "<question>Are you sure you want to add the {$this->checklist} checklist to the {$this->gh_repository->full_name} repository on host " . self::HOSTS[ $this->host ] . " with these tags: {$tags}? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$this->checklist_text = $this->parse_checklist_text( $this->checklist_text );
		$response             = create_github_issue( $this->gh_repository->name, sprintf( '%s Checklist', self::CHECKLISTS[ $this->checklist ] ), $this->checklist_text );
		if ( ! $response ) {
			$output->writeln( '<error>Failed to create checklist issue.</error>' );
			return Command::FAILURE;
		}
		$output->writeln( sprintf( '<info>Checklist issue #%d created successfully.</info> <comment>https://github.com/a8cteam51/%s/issues/%d</comment>', $response->number, $this->gh_repository->name, $response->number ) );
		return Command::SUCCESS;
	}

	/**
	 * Prompts the user to input a checklist.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_checklist_input( InputInterface $input, OutputInterface $output ): string {
		$question = new ChoiceQuestion( '<question>Please select the checklist to add [launch]:</question> ', self::CHECKLISTS, 'launch' );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user to input a repository.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string|null
	 */
	private function prompt_repository_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Please enter the slug of the GitHub repository to add the checklist to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_github_repositories() ?? array(), 'name' ) );
		}

		$repository = $this->getHelper( 'question' )->ask( $input, $output, $question );
		if ( ! $repository ) {
			return null;
		}

		return $repository;
	}

	/**
	 * Prompts the user for a host.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_host_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new ChoiceQuestion( '<question>Where is the site hosted? [pressable]:</question> ', self::HOSTS, 'pressable' );
		$question->setValidator( fn( $value ) => validate_user_choice( $value, self::HOSTS ) );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Retrieves the checklist from the repository.
	 *
	 * @param   string          $checklist The checklist to retrieve.
	 * @param   OutputInterface $output    The output interface.
	 *
	 * @return  string
	 */
	private function get_checklist( string $checklist, OutputInterface $output ): string {
		// Temporary directory to clone the repository
		$temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid( 'team51-checklists_', true );
		$repo_url = self::CHECKLISTS_REPOSITORY_URL;

		$output->writeln( "<comment>Retrieving {$checklist} checklist from GitHub...</comment>" );

		// Clone the repository
		\run_system_command( array( 'git', 'clone', $repo_url, $temp_dir ), sys_get_temp_dir() );

		$checklist_file = $temp_dir . '/checklists/' . $checklist . '.md';
		if ( ! file_exists( $checklist_file ) ) {
			throw new \Exception( "Checklist file not found: {$checklist_file}" );
		}

		$checklist_text = file_get_contents( $checklist_file );
		\run_system_command( array( 'rm', '-rf', $temp_dir ), sys_get_temp_dir() );

		return $checklist_text;
	}

	/**
	 * Parse the checklist text for conditional tags.
	 *
	 * @param   string $checklist_text The checklist text.
	 *
	 * @return  string
	 */
	private function parse_checklist_text( string $checklist_text ): string {
		$lines        = explode( "\n", $checklist_text );
		$parsed_lines = array();
		$current_tag  = null;
		$skipping_tag = false;
		foreach ( $lines as $line ) {
			// If the line contains a conditional tag, check if it is set to true in the conditional_tags array.
			if ( str_starts_with( trim( $line ), '[' ) && str_ends_with( trim( $line ), ']' ) ) {
				$tag = trim( $line, '[]' );
				// If the line contains the end tag, remove it and reset the current tag.
				if ( $current_tag && str_contains( $line, '[/' . $current_tag . ']' ) ) {
					$current_tag  = null;
					$skipping_tag = false;
					continue;
				}

				if ( ( isset( $this->conditional_tags[ $tag ] ) && array_key_exists( $tag, $this->conditional_tags ) && true === $this->conditional_tags[ $tag ] )
					|| ( str_starts_with( $tag, 'host:' ) && $this->host === substr( $tag, 5 ) )
					|| ( str_starts_with( $tag, 'not:host:' ) && $this->host !== substr( $tag, 9 ) )
					|| ( str_starts_with( $tag, 'not:' ) && array_key_exists( substr( $tag, 4 ), $this->conditional_tags ) && false === $this->conditional_tags[ substr( $tag, 4 ) ] )
				) {
					// Remove this line from the array and mark to check for the end tag.
					continue;
				}

				// If the tag is not set to true, skip all lines until the end tag is found.
				$skipping_tag = true;
				$current_tag  = $tag;
				continue;
			}

			// If we are skipping the tag, skip this line.
			if ( $skipping_tag ) {
				continue;
			}

			$parsed_lines[] = $line;
		}

		return implode( "\n", $parsed_lines );
	}
}
