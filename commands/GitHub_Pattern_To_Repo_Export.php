<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Exports a block pattern from a site to the block pattern library.
 */
#[AsCommand( name: 'github:export-pattern-to-repo' )]
final class GitHub_Pattern_To_Repo_Export extends Command {
	use AutocompleteTrait;


	/**
	 * The Pressable site to process.
	 *
	 * @var object|null
	 */
	protected ?object $pressable_site = null;

	/**
	 * The name of the pattern to export.
	 *
	 * @var string|null
	 */
	protected ?string $pattern_name = null;

	/**
	 * The slug of the category under which the pattern will be exported.
	 *
	 * @var string|null
	 */
	protected ?string $category_slug = null;

	/**
	 * Whether to preserve images in the pattern.
	 *
	 * @var bool
	 */
	protected bool $preserve_images = false;

	/**
	 * {@inheritDoc}
	 */
	protected function configure() {
		$this
			->setDescription( 'Exports a block pattern from a site to a GitHub.' )
			->setHelp( 'This command exports a specified block pattern into a category within a GitHub repository.' )
			->addArgument( 'site', InputArgument::REQUIRED, 'ID or URL of the Pressable site to run the command on.' )
			->addArgument( 'pattern-name', InputArgument::REQUIRED, 'The unique identifier of the block pattern to export (e.g., `namespace/pattern-name`).' )
			->addArgument( 'category-slug', InputArgument::REQUIRED, 'The slug of the category under which the pattern should be exported. It should be lowercase with hyphens instead of spaces (e.g., `featured-patterns`).' )
			->addOption( 'preserve-images', null, InputOption::VALUE_NONE, 'Skip substition of placeholder images.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		// Retrieve the given site.
		$this->pressable_site = get_pressable_site_input( $input, fn() => $this->prompt_site_input( $input, $output ) );
		$output->writeln( "<comment>Site {$this->pressable_site->id}: {$this->pressable_site->url}</comment>", Output::VERBOSITY_VERBOSE );

		// Store the ID of the site in the argument field.
		$input->setArgument( 'site', $this->pressable_site->id );

		// Check if the pattern name was already provided as an argument. If not, prompt the user for it.
		$this->pattern_name = get_string_input( $input, 'pattern-name', fn() => $this->prompt_pattern_name_input( $input, $output ) );
		$output->writeln( "<comment>Pattern name: {$this->pattern_name}</comment>", Output::VERBOSITY_VERBOSE );

		// Check if the category slug was already provided as an argument. If not, prompt the user for it.
		$this->category_slug = slugify( get_string_input( $input, 'category-slug', fn() => $this->prompt_category_slug_input( $input, $output ) ) );
		$output->writeln( "<comment>Category slug: {$this->category_slug}</comment>", Output::VERBOSITY_VERBOSE );

		// Set preserve-images option.
		$this->preserve_images = get_bool_input( $input, 'preserve-images' );
		$output->writeln( sprintf( '<comment>Preserve images: %s</comment>', ( $this->preserve_images ? 'Yes' : 'No' ) ), Output::VERBOSITY_VERBOSE );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
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
	 * Prompts the user for a site if in interactive mode.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_site_input( InputInterface $input, OutputInterface $output ): ?string {
		$question = new Question( '<question>Enter the site ID or URL to extract the pattern from:</question> ' );
		if ( ! $input->getOption( 'no-autocomplete' ) ) {
			$question->setAutocompleterValues( \array_column( get_pressable_sites( include_aliases: true ) ?? array(), 'url' ) );
		}

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a pattern name in interactive mode.
	 *
	 * @param InputInterface  $input  The input object.
	 * @param OutputInterface $output The output object.
	 *
	 * @return string|null
	 */
	private function prompt_pattern_name_input( InputInterface $input, OutputInterface $output ): ?string {

		// Ask for the pattern name, providing an example as a hint.
		$question_text = '<question>Enter the pattern name (e.g., "twentytwentyfour/banner-hero"):</question> ';
		$question      = new Question( $question_text );

		// Retrieve the user's input.
		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	/**
	 * Prompts the user for a category slug in interactive mode.
	 *
	 * @param   InputInterface  $input  The input object.
	 * @param   OutputInterface $output The output object.
	 *
	 * @return  string|null
	 */
	private function prompt_category_slug_input( InputInterface $input, OutputInterface $output ): ?string {
			// Provide guidance on the expected format for the category slug.
			$question = new Question( '<question>Enter the category slug (lowercase, hyphens for spaces, e.g., "hero"):</question> ' );

			// Ask the question and retrieve the user's input.
			$category_slug = $this->getHelper( 'question' )->ask( $input, $output, $question );

			return $category_slug;
	}

	/**
	 * Downloads an image from a URL and returns its dimensions and extension.
	 *
	 * @param string $image_url The URL of the image to download.
	 *
	 * @return array
	 */
	private function get_image_details( $image_url ) {
		// Download the image
		$image_data = file_get_contents( $image_url );
		if ( false === $image_data ) {
			throw new Exception( "Failed to download image from URL: $image_url" );
		}

		// Determine the image size
		$image_size = getimagesizefromstring( $image_data );
		if ( false === $image_size ) {
			throw new Exception( "Failed to determine the size of the image from URL: $image_url" );
		}

		// Determine the image extension
		$extension = image_type_to_extension( $image_size[2], false );

		return array(
			'data'      => $image_data,
			'width'     => $image_size[0],
			'height'    => $image_size[1],
			'mime_type' => $image_size['mime'],
			'extension' => $extension,
		);
	}
}
