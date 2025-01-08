<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use WPCOMSpecialProjects\CLI\Helper\AutocompleteTrait;

/**
 * Triages the connection status of a given list of sites
 */
#[AsCommand( name: 'jetpack:connection-triage' )]
final class Jetpack_Site_Connection_Triage extends Command {
	use AutocompleteTrait;

	/**
	 * The list of connected sites.
	 *
	 * @var array|null
	 */
	private ?array $sites = null;

	protected function configure(): void {
		$this->setDescription( 'Triages Jetpack connection issues for sites and generates a report.' )
			->setHelp( 'Use this command to identify and analyze sites with Jetpack connection problems. It will check connection status and generate a CSV report with details about problematic connections. You can either provide a CSV of specific sites to check, or run it without arguments to check all connected Jetpack sites.' );
	
		$this->addArgument( 
			'csv-path', 
			InputArgument::OPTIONAL, 
			'A path to a CSV with 2 columns, blog ID and url. If not provided, will check all connected Jetpack sites.' 
		);
	}

	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		if ( ! $input->getArgument( 'csv-path' ) ) {
			$this->sites = get_wpcom_jetpack_sites();
			$output->writeln( '<comment>Successfully fetched ' . \count( $this->sites ) . ' Jetpack site(s).</comment>' );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$broken_site_data = array();
		$csv_path = $input->getArgument( 'csv-path' );
	
		// Get the list of sites to check, either from CSV or from Jetpack
		if ( $csv_path ) {
			$output->writeln( "<fg=magenta;options=bold>Reading sites from {$csv_path}...</>" );
	
			if ( ! file_exists( $csv_path ) ) {
				$output->writeln( "<fg=red;options=bold>CSV file not found at {$csv_path}.</>" );
				return Command::FAILURE;
			}
	
			// Read the CSV. Column 1: Site ID, Column 2: Site URL
			$csv = file_get_contents( $csv_path );
			$csv = explode( "\n", $csv );
			unset( $csv[0] ); // Remove header row
			$sites_to_check = array();
			foreach ( $csv as $row ) {
				$line = str_getcsv( $row );
				if ( isset( $line[0], $line[1] ) ) {
					$sites_to_check[] = array(
						'blog_id' => $line[0],
						'url'     => $line[1],
					);
				}
			}
		} else {
			$output->writeln( "<fg=magenta;options=bold>Checking all Jetpack sites with connection issues...</>" );
			$output->writeln( "<comment>This initial check may take a few minutes, please be patient...</comment>" );

			// Process sites in chunks to avoid memory issues
			$sites_to_check = array();
			foreach ( array_chunk( $this->sites, 100, true ) as $sites_chunk ) {
				$modules = get_jetpack_site_modules_batch( \array_column( $sites_chunk, 'userblog_id' ), $errors );
				
				foreach ( $errors as $site_id => $error ) {
					$site = $this->sites[$site_id];
					$sites_to_check[] = array(
						'blog_id' => $site->userblog_id,
						'url'     => $site->siteurl,
					);
				}
			}
		}
	
		// Process each site through our connection check logic
		foreach ( $sites_to_check as $site ) {
			$site_id = $site['blog_id'];
			$site_url = $site['url'];
			$domain = parse_url( $site_url, PHP_URL_HOST );
			$jetpack_endpoint = $site_url . '/wp-json/jetpack/v4';
			$notes = '';
			$status_code = '';
			$new_blog_id = '';
	
			$output->writeln( 'Checking ' . $site_url . '...' );
	
			//fetch
			$options = array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => 60,
					'ignore_errors' => true,
				),
			);
			$context = stream_context_create( $options );
			$result = @file_get_contents( $site_url, false, $context );
			$headers = parse_http_headers( $http_response_header );
			$status_code = $headers['http_code'];
	
			if ( 410 === $status_code && strpos( $site_url, 'mystagingwebsite.com' ) !== false ) {
				// Probably deactivated
				$notes = 'Site is probably deactivated';
				// Check Pressable?
			} elseif ( 404 === $status_code && strpos( $site_url, 'mystagingwebsite.com' ) !== false ) {
				// probably deleted or Jetpack not installed. Next step is to curl the homepage
				//$notes = $result['body'];
				$notes = '404, check site';
				if ( strpos( $result, 'Domain not found' ) !== false ) {
					$notes = 'Domain not found, probably deleted from Pressable';
				}
			} elseif ( 500 === $status_code ) {
				//$notes = $result['body'];
				$notes = 'Check site, internal server error';
			} elseif ( 200 === $status_code && strpos( $site_url, 'mystagingwebsite.com' ) === false ) {
				$notes = 'Site either moved hosts or has a new JP connection. Check DARC and NA: https://mc.a8c.com/tools/reportcard/domain/?domain=' . $domain . ' and https://wordpress.com/wp-admin/network/sites.php?s=' . $site_url;
			} elseif ( 200 === $status_code && strpos( $site_url, 'mystagingwebsite.com' ) !== false ) {
				// Check WPCOM API for site information
				$wpcom_api_url = 'https://public-api.wordpress.com/rest/v1.1/sites/' . rawurlencode( $domain );
				$wpcom_result = @file_get_contents( $wpcom_api_url, false, $context );
				$wpcom_status = $http_response_header ? parse_http_headers( $http_response_header )['http_code'] : '';
	
				if ( '400' === $wpcom_status ) {
					$notes = 'Site is not connected to WordPress.com. If site loads, it may have moved hosts.';
				} else {
					$wpcom_data = json_decode( $wpcom_result, true );
	
					if ( $wpcom_data && isset( $wpcom_data['ID'] ) ) {
						if ( (int) $wpcom_data['ID'] !== (int) $site_id ) {
							$notes = 'Site has a new Jetpack connection. Old ID: ' . $site_id . ', New ID: ' . $wpcom_data['ID'];
							$new_blog_id = $wpcom_data['ID'];
						} else {
							$notes = 'Site connection appears valid. Original error may have been intermittent.';
						}
					}
				}
			}
	
			$broken_site_data[] = array(
				'site_id'     => $site_id,
				'site_url'    => $site_url,
				'status'      => $status_code,
				'new_blog_id' => $new_blog_id,
				'notes'       => $notes,
			);
		}
	
		// Write results to CSV
		$output->writeln( '<info>Making the CSV...<info>' );
		$timestamp = gmdate( 'Y-m-d-H-i-s' );
		$filename = 'jp-connection-' . $timestamp . '.csv';
		$filepath = getcwd() . DIRECTORY_SEPARATOR . $filename;
		$fp = fopen( $filepath, 'w' );
		fputcsv( $fp, array( 'Site ID', 'Site URL', 'Status', 'New Blog ID', 'Notes' ), ",", '"', "\\");
		foreach ( $broken_site_data as $fields ) {
			fputcsv( $fp, $fields, ",", '"', "\\");
		}
		fclose( $fp );

		$output->writeln( sprintf( '<info>Done, CSV saved to: %s</info>', $filepath ) );
	
		return Command::SUCCESS;
	}

	// endregion
}
