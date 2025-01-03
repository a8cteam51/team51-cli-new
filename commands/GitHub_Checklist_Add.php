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
		'launch' => 'Launch',
		'migrate-pressable-dns' => 'DNS Migration to Pressable',
		'qa-engineer' => 'QA Engineer',
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
			print( "{$tag}: {$details['description']}\n" );
			$this->addOption( $tag, null, InputOption::VALUE_NONE, $details['description'] );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Retrieve the repository.
		$this->gh_repository = get_github_repository_input( $input, fn() => $this->prompt_repository_input( $input, $output ) );
		$input->setArgument( 'repository', $this->gh_repository );

		$this->host = get_enum_input( $input, 'host', array_keys( self::HOSTS ), fn() => $this->prompt_host_input( $input, $output ) );
		$input->setArgument( 'host', $this->host );

		foreach ( self::CONDITIONAL_TAGS as $tag => $details ) {
			$output->writeln( "<comment>{$tag}: {$details['question']}</comment>", Output::VERBOSITY_VERBOSE );
			$this->conditional_tags[ $tag ] = get_bool_input( $input, $tag );
			$input->setOption( $tag, $this->conditional_tags[ $tag ] );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to connect the WPCOM site `{$this->site->name}` (ID {$this->site->ID}, URL {$this->site->URL}) to the GitHub repository `{$this->gh_repository->full_name}`? [y/N]</question> ", false );
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
		$output->writeln( "<fg=magenta;options=bold>Exporting {$this->pattern_name} (Category: {$this->category_slug}) from {$this->pressable_site->displayName} (ID {$this->pressable_site->id}, URL {$this->pressable_site->url})</>" );

		// Upload script.
		$sftp_connection = \Pressable_Connection_Helper::get_sftp_connection( $this->pressable_site->id );
		if ( \is_null( $sftp_connection ) ) {
			$output->writeln( '<error>Could not open SFTP connection.</error>' );
			return Command::FAILURE;
		}
		$result = $sftp_connection->put( '/htdocs/pattern-extract.php', file_get_contents( __DIR__ . '/../scaffold/pattern-extract.php' ) );
		if ( ! $result ) {
			$output->writeln( "<error>Failed to copy pattern-extract.php to {$this->pressable_site->id}.</error>" );
			return Command::FAILURE;
		}

		$ssh_connection = \Pressable_Connection_Helper::get_ssh_connection( $this->pressable_site->id );
		if ( \is_null( $ssh_connection ) ) {
			$output->writeln( "<error>Failed to connect via SSH for {$this->pressable_site->url}. Aborting!</error>" );
			return Command::FAILURE;
		}

		// Run script.
		$result = $ssh_connection->exec( sprintf( 'wp --skip-plugins --skip-theme eval-file /htdocs/pattern-extract.php %s', escapeshellarg( $this->pattern_name ) ) );
		$output->writeln( '<comment>Pattern extraction result: ' . var_export( $result, true ) . '</comment>', Output::VERBOSITY_DEBUG );
		// Delete script.
		$ssh_connection->exec( 'rm /htdocs/pattern-extract.php' );

		if ( ! empty( $result ) ) {

			// Replace placeholder images with actual images.
			if ( ! $this->preserve_images ) {
				$decoded = decode_json_content( $result );
				$output->writeln( sprintf( '<comment>Decoded content: %s</comment>', var_export( $decoded, true ) ), Output::VERBOSITY_DEBUG );
				$content = $decoded->content ?? '';
				$output->writeln( "<comment>Original content: {$content}</comment>", Output::VERBOSITY_DEBUG );
				if ( ! empty( $content ) ) {
					$replacements = array();
					$matches      = array();
					preg_match_all( '/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $matches );
					foreach ( $matches[1] as $image_url ) {
						// Download the image and determine its size.
						$image_data = $this->get_image_details( $image_url );
						// Replace the image URL with the placeholder image URL on picsum.photos.
						$replacements[ $image_url ] = 'https://picsum.photos/' . $image_data['width'] . '/' . $image_data['height'];
					}
					$decoded->content = str_replace( array_keys( $replacements ), array_values( $replacements ), $content );
					$output->writeln( "<comment>Updated content: {$content}</comment>", Output::VERBOSITY_DEBUG );
					$result = encode_json_content( $decoded );
					$output->writeln( "<comment>Re-encoded result: {$result}</comment>", Output::VERBOSITY_DEBUG );
				}
			}
			// Temporary directory to clone the repository
			$temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid( 'team51-patterns_', true );
			$output->writeln( "<comment>Local repo clone: {$temp_dir}</comment>", Output::VERBOSITY_VERBOSE );
			$repo_url = 'git@github.com:a8cteam51/team51-patterns.git';

			// Clone the repository
			\run_system_command( array( 'git', 'clone', $repo_url, $temp_dir ), sys_get_temp_dir() );

			// The 'patterns' folder at the root of the repo.
			$patterns_dir = $temp_dir . '/patterns';

			// Additional setup for category directory and metadata.json handling.
			$category_dir  = $patterns_dir . '/' . $this->category_slug;
			$metadata_path = $category_dir . '/metadata.json';

			// Ensure the category directory exists.
			\run_system_command( array( 'mkdir', '-p', $category_dir ), $temp_dir );

			// Check if metadata.json exists before creating or overwriting
			if ( ! file_exists( $metadata_path ) ) {
				$metadata = array( 'title' => $this->category_slug );
				file_put_contents( $metadata_path, encode_json_content( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

				// Add metadata.json to the repository
				\run_system_command( array( 'git', 'add', $metadata_path ), $temp_dir );
			}

			// Path to the JSON file for the pattern.
			$pattern_file_base = basename( slugify( $this->pattern_name ) );
			$pattern_file_name = $pattern_file_base . '.json';
			$json_file_path    = $category_dir . '/' . $pattern_file_name;

			// Check for existing files with the same name and append a number if necessary.
			$count = 1;
			while ( file_exists( $json_file_path ) ) {
				++$count;
				$pattern_file_name = $pattern_file_base . '-' . $count . '.json';
				$json_file_path    = $category_dir . '/' . $pattern_file_name;
			}

			// Save the pattern result to the file. Re-enconded to save as pretty JSON.
			$result = decode_json_content( $result, true );
			$result = encode_json_content( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			file_put_contents( $json_file_path, $result );

			// Add, commit, and push the change.
			$branch_name = 'add/pattern/' . $this->category_slug . '/' . $pattern_file_base . '-' . time();
			\run_system_command( array( 'git', 'branch', '-m', $branch_name ), $temp_dir );
			\run_system_command( array( 'git', 'add', $json_file_path ), $temp_dir );
			\run_system_command( array( 'git', 'commit', '-m', 'New pattern: ' . $pattern_file_base ), $temp_dir );
			\run_system_command( array( 'git', 'push', 'origin', $branch_name ), $temp_dir );

			// Clean up by removing the cloned repository directory, if desired
			\run_system_command( array( 'rm', '-rf', $temp_dir ), sys_get_temp_dir() );

			$output->writeln( "<fg=green;options=bold>Pattern exported successfully to {$branch_name}.</>" );
			$output->writeln( "<fg=green;options=bold>View the pattern at </><fg=blue>https://github.com/a8cteam51/team51-patterns/compare/trunk...{$branch_name}</>" );
		} else {
			$output->writeln( '<error>Pattern not found. Aborting!</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<info>Done!</info>' );
		return Command::SUCCESS;
	}

	/**
	 * Prompts the user to input a repository.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_repository_input( InputInterface $input, OutputInterface $output ): string {
		$question = new Question( '<question>Please enter the slug of the GitHub repository to add the checklist to:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( array_column( get_github_repositories() ?? array(), 'name' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
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
		$question = new ChoiceQuestion( '<question>Please select the site host [pressable]:</question> ', self::HOSTS, 'pressable' );
		$question->setValidator( fn( $value ) => validate_user_choice( $value, self::HOSTS ) );
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}
}
