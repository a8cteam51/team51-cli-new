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
		'migrate-pressable-dns'        => array(
			'question'    => 'Will the site DNS be migrated to Pressable?',
			'description' => 'The site DNS will be migrated to Pressable.',
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
		'videopress'                   => array(
			'question'    => 'Does the site use VideoPress?',
			'description' => 'The site uses VideoPress.',
		),
		'woocommerce'                  => array(
			'question'    => 'Does the site have WooCommerce installed?',
			'description' => 'The site has WooCommerce installed.',
		),
		'wpcom-to-pressable-migration' => array(
			'question'    => 'Is the site migrating from WordPress.com (Simple or VIP) to Pressable?',
			'description' => 'The site is migrating from WordPress.com (Simple or VIP) to Pressable.',
		),
		'yoast'                        => array(
			'question'    => 'Does the site have Yoast installed?',
			'description' => 'The site has Yoast installed.',
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

		foreach ( self::CONDITIONAL_TAGS as $tag => $details ) {
			if ( $has_args ) {
				$this->conditional_tags[ $tag ] = get_bool_input( $input, $tag );
			} else {
				$question                       = new ConfirmationQuestion( "<question>{$details['question']} [y/N]</question> ", false );
				$this->conditional_tags[ $tag ] = $this->getHelper( 'question' )->ask( $input, $output, $question );
			}
			$input->setOption( $tag, $this->conditional_tags[ $tag ] );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$tags     = implode( ', ', array_keys( array_filter( $this->conditional_tags ) ) );
		$question = new ConfirmationQuestion( "<question>Are you sure you want to add the {$this->checklist} checklist to the {$this->gh_repository->full_name} repository with these tags: {$tags}? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
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
}
