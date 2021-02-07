<?php

namespace App\Command;

use App\Service\SongService;
use App\Service\ProfileService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;

class FixSongsLinkCommand extends Command
{
    /**
     * Command to run on the terminal
     *
     * @var string
     */
    protected static $defaultName = 'app:fix-songs';

    /**
     * @var ProfileService
     */
    private $profileService;

    /**
     * @var SongService
     */
    private $songService;

    /**
     * @param ProfileService $profileService
     * @param SongService $songService
     */
    public function __construct(ProfileService $profileService, SongService $songService)
    {
        $this->profileService = $profileService;
        $this->songService = $songService;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription("Fix missing songs' url of a user on Smule")
            ->addArgument('username', InputArgument::REQUIRED, 'Smule username')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');

        $output->writeln([
            'Smule Songs Missing Urls Fixer : '. $username,
            '=====================================',
            '',
        ]);

        $profile = $this->profileService->getProfile($username, $output);

        $result = $this->songService->fixMissingUrls($profile, $output);

        $table = new Table($output);
        $table
            ->setHeaders(['Status', 'Number'])
            ->setRows([
                ['Fixed', $result['fixed']],
                ['Requested', $result['requested']],
                ['Errors', $result['errors']],
                new TableSeparator(),
                ['TOTAL', $result['total']],
            ])
        ;
        $table->render();



        return Command::SUCCESS;
    }
}
