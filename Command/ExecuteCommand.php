<?php

/** @noinspection PhpUnused */

/** @noinspection PhpMissingFieldTypeInspection */

namespace JMose\CommandSchedulerBundle\Command;

use Cron\CronExpression;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\ObjectManager;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;
use JMose\CommandSchedulerBundle\Event\SchedulerCommandExecutedEvent;
use Symfony\Bridge\Doctrine\ManagerRegistry;
#use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\LockableTrait;

/**
 * Class ExecuteCommand : This class is the entry point to execute all scheduled command.
 *
 * @author  Julien Guyon <julienguyon@hotmail.com>
 */
##[ConsoleCommand(name: 'scheduler:execute', description: 'Execute scheduled commands')]
class ExecuteCommand extends Command
{
    use LockableTrait;

    /**
     * @var string
     */
    protected static $defaultName = 'scheduler:execute';
    #private ObjectManager | EntityManager $em;
    #private EntityManager $em;
    private ObjectManager $em;
    private EventDispatcherInterface $eventDispatcher;

    /**
     * @var string
     */
    private string $dumpMode;

    private ?int $commandsVerbosity = null;

    /**
     * ExecuteCommand constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param ManagerRegistry          $managerRegistry
     * @param string                   $managerName
     * @param string | bool            $logPath
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        ManagerRegistry $managerRegistry,
        string $managerName,
    private string | bool $logPath
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->em = $managerRegistry->getManager($managerName);
        #$this->em = $managerRegistry->getManagerForClass(ScheduledCommand::class);
        #EntityManagerInterface
        #$this->em = $this->getDoctrine()->getManager($managerName);

        // If logpath is not set to false, append the directory separator to it
        if (false !== $this->logPath) {
            $this->logPath = rtrim($this->logPath, '/\\').DIRECTORY_SEPARATOR;
        }

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Execute scheduled commands')
            ->addOption('dump', null, InputOption::VALUE_NONE, 'Display next execution')
            ->addOption('no-output', null, InputOption::VALUE_NONE, 'Disable output message from scheduler')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> is the entry point to execute all scheduled command:

You can list the commands with last and next exceution time with
<info>php bin/console scheduler:list</info>

HELP
            );
    }

    /**
     * Initialize parameters and services used in execute function.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->dumpMode = (string) $input->getOption('dump');

        // Store the original verbosity before apply the quiet parameter
        $this->commandsVerbosity = $output->getVerbosity();

        if (true === $input->getOption('no-output')) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     * @throws ExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /**
         * Be sure that there are no overlapping Execution of commands.
         * The command is released at the end of this function
         * @see https://symfony.com/doc/current/console/lockable_trait.html
         */
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }


        $output->writeln('<info>Start : '.($this->dumpMode ? 'Dump' : 'Execute').' all scheduled command</info>');

        // Before continue, we check that the output file is valid and writable (except for gaufrette)
        if (false !== $this->logPath &&
            !str_starts_with($this->logPath, 'gaufrette:') &&
            !is_writable($this->logPath)
        ) {
            $output->writeln(
                '<error>'.$this->logPath.
                ' not found or not writable. You should override `log_path` in your config.yml'.'</error>'
            );

            return Command::FAILURE;
        }

        $commands = $this->em->getRepository(ScheduledCommand::class)->findEnabledCommand();

        $noneExecution = true;
        foreach ($commands as $command) {
            // PullRequest: fix command refresh #183
            // https://github.com/j-guyon/CommandSchedulerBundle/pull/183/commits/b194e340df50533e576ee419a11fd1a1f4bf7c6e
            //$this->em->refresh($this->em->find(ScheduledCommand::class, $command));
            try {
                $command = $this->em->find(ScheduledCommand::class, $command->getId());
            } catch (OptimisticLockException | TransactionRequiredException | ORMException) {
            }
            if ($command->isDisabled() || $command->isLocked()) {
                continue;
            }

            /** @var ScheduledCommand $command */
            $cron = new CronExpression($command->getCronExpression());
            $nextRunDate = $cron->getNextRunDate($command->getLastExecution());
            $now = new \DateTime();

            if ($command->isExecuteImmediately()) {
                $noneExecution = false;
                $output->writeln(
                    'Immediately execution asked for : <comment>'.$command->getCommand().'</comment>'
                );

                if (!$input->getOption('dump')) {
                    $this->executeCommand($command, $output, $input);
                }
            } elseif ($nextRunDate < $now) {
                // RUN the command
                $noneExecution = false;
                $output->writeln(
                    'Command <comment>'.$command->getCommand().
                    '</comment> should be executed - last execution : <comment>'.
                    $command->getLastExecution()->format(\DateTimeInterface::ATOM).'.</comment>'
                );

                if (!$input->getOption('dump')) {
                    $this->executeCommand($command, $output, $input);
                }
            }
        }

        if ($noneExecution) {
            $output->writeln('Nothing to do.');
        }

        $this->release();

        return Command::SUCCESS;
    }

    /**
     * @param ScheduledCommand $scheduledCommand
     * @param OutputInterface  $output
     * @param InputInterface   $input
     *
     * @throws ConnectionException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     * @throws MappingException
     * @throws ExceptionInterface
     */
    private function executeCommand(
        ScheduledCommand $scheduledCommand,
        OutputInterface $output,
        InputInterface $input
    ): void {
        //reload command from database before every execution to avoid parallel execution
        $this->em->getConnection()->beginTransaction();
        try {
            $notLockedCommand = $this
                ->em
                ->getRepository(ScheduledCommand::class)
                ->getNotLockedCommand($scheduledCommand);

            //$notLockedCommand will be locked for avoiding parallel calls:
            // http://dev.mysql.com/doc/refman/5.7/en/innodb-locking-reads.html
            if (null === $notLockedCommand) {
                throw new \Exception();
            }

            $scheduledCommand = $notLockedCommand;
            $scheduledCommand->setLastExecution(new \DateTime());
            $scheduledCommand->setLocked(true);
            $this->em->persist($scheduledCommand);
            $this->em->flush();
            $this->em->getConnection()->commit();
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            $output->writeln(
                sprintf(
                    '<error>Command %s is locked %s</error>',
                    $scheduledCommand->getCommand(),
                    (empty($e->getMessage()) ? '' : sprintf('(%s)', $e->getMessage()))
                )
            );

            return;
        }

        $scheduledCommand = $this->em->find(ScheduledCommand::class, $scheduledCommand);

        try {
            $command = $this->getApplication()->find($scheduledCommand->getCommand());
        } catch (\InvalidArgumentException) {
            $scheduledCommand->setLastReturnCode(-1);
            $output->writeln('<error>Cannot find '.$scheduledCommand->getCommand().'</error>');

            return;
        }

        $input = new StringInput(
            $scheduledCommand->getCommand().' '.$scheduledCommand->getArguments().' --env='.$input->getOption('env')
        );
        $command->mergeApplicationDefinition();
        $input->bind($command->getDefinition());

        // Disable interactive mode if the current command has no-interaction flag
        if ($input->hasParameterOption(['--no-interaction', '-n'])) {
            $input->setInteractive(false);
        }

        // Use a StreamOutput or NullOutput to redirect write() and writeln() in a log file
        if (false === $this->logPath || empty($scheduledCommand->getLogFile())) {
            $logOutput = new NullOutput();
        } else {
            $logOutput = new StreamOutput(
                fopen(
                    $this->logPath.$scheduledCommand->getLogFile(),
                    'a',
                    false
                ),
                $this->commandsVerbosity
            );
        }

        // Execute command and get return code
        try {
            $output->writeln(
                '<info>Execute</info> : <comment>'.$scheduledCommand->getCommand()
                .' '.$scheduledCommand->getArguments().'</comment>'
            );

            $result = $command->run($input, $logOutput);

            // PullRequest: Clear ORM after run scheduled command #187
            // https://github.com/j-guyon/CommandSchedulerBundle/pull/187/commits/ccb0c7f561bafb3c2d5534b2ddd919b8c060963f
            $this->em->clear();
        } catch (\Throwable $e) {
            $logOutput->writeln($e->getMessage());
            $logOutput->writeln($e->getTraceAsString());
            $result = -1;
        }

        if (false === $this->em->isOpen()) {
            $output->writeln('<comment>Entity manager closed by the last command.</comment>');
            $this->em = $this->em->create($this->em->getConnection(), $this->em->getConfiguration());
        }

        // Reactivate the command in DB

        // PullRequest: Fix repeated jobs #181
        // https://github.com/j-guyon/CommandSchedulerBundle/pull/181/commits/ca325d94e78f8c3113b250ae48152bf818fa1f44
        $scheduledCommand = $this->em->find(ScheduledCommand::class, $scheduledCommand);

        $scheduledCommand->setLastReturnCode($result);
        $scheduledCommand->setLocked(false);
        $scheduledCommand->setExecuteImmediately(false);
        $this->em->persist($scheduledCommand);
        $this->em->flush();

        $this->eventDispatcher->dispatch(new SchedulerCommandExecutedEvent($scheduledCommand));

        /*
         * This clear() is necessary to avoid conflict between commands and to be sure that none entity are managed
         * before entering in a new command
         */
        $this->em->clear();

        unset($command);
        gc_collect_cycles();
    }
}
