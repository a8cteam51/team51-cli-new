<?php

namespace WPCOMSpecialProjects\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Connects a DeployHQ project to a GitHub repository.
 */
#[AsCommand( name: 'deployhq:connect-project-repository', aliases: array( 'deployhq:connect-project-repo' ) )]
final class DeployHQ_Project_Connect_Repository extends Command {
	// region FIELDS AND CONSTANTS

	/**
	 * The project to connect.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $project = null;

	/**
	 * The repository to connect.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $repository = null;

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	protected function configure(): void {
		$this->setDescription( 'Connects a project to a GitHub repository on DeployHQ.' )
			->setHelp( 'Use this command to connect a DeployHQ project to a GitHub repository.' );

		$this->addArgument( 'project', InputArgument::REQUIRED, 'The slug of the DeployHQ project to connect.' )
			->addArgument( 'repository', InputArgument::REQUIRED, 'The slug of the GitHub repository to connect.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize( InputInterface $input, OutputInterface $output ): void {
		$this->project = get_deployhq_project_input( $input, $output, fn() => $this->prompt_project_input( $input, $output ) );
		$input->setArgument( 'project', $this->project->permalink );

		$this->repository = get_github_repository_input( $input, $output, fn() => $this->prompt_repository_input( $input, $output ) );
		$input->setArgument( 'repository', $this->repository->name );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function interact( InputInterface $input, OutputInterface $output ): void {
		$question = new ConfirmationQuestion( "<question>Are you sure you want to connect the DeployHQ project `{$this->project->name}` (permalink {$this->project->permalink}) to the GitHub repository `{$this->repository->full_name}`? [y/N]</question> ", false );
		if ( true !== $this->getHelper( 'question' )->ask( $input, $output, $question ) ) {
			$output->writeln( '<comment>Command aborted by user.</comment>' );
			exit( 2 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$output->writeln( "<fg=magenta;options=bold>Connecting the DeployHQ project `{$this->project->name}` (permalink {$this->project->permalink}) to the GitHub repository `{$this->repository->full_name}`.</>" );

		$deployhq_project_repository = update_deployhq_project_repository( $this->project->permalink, $this->repository->ssh_url );
		if ( is_null( $deployhq_project_repository ) ) {
			$output->writeln( '<error>Failed to connect the project to the repository.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( '<fg=green;options=bold>Project connected to the repository successfully.</>' );
		return Command::SUCCESS;
	}

	// endregion

	// region HELPERS

	/**
	 * Prompts the user to input a project.
	 *
	 * @param   InputInterface  $input  The input interface.
	 * @param   OutputInterface $output The output interface.
	 *
	 * @return  string
	 */
	private function prompt_project_input( InputInterface $input, OutputInterface $output ): string {
		$question = new Question( '<question>Enter the slug of the project to connect:</question> ' );
		$question->setAutocompleterValues( array_column( get_deployhq_projects() ?? array(), 'permalink' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
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
		$question = new Question( '<question>Enter the slug of the repository to connect:</question> ' );
		$question->setAutocompleterValues( array_column( get_github_repositories() ?? array(), 'name' ) );

		return $this->getHelper( 'question' )->ask( $input, $output, $question );
	}

	// endregion
}
