<?php

namespace App\Command;

use App\Service\SongService;
use App\Service\ProfileService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadCommand extends Command
{
    /**
     * Command to run on the terminal
     *
     * @var string
     */
    protected static $defaultName = 'app:download';

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
    public function __construct(
        ProfileService $profileService,
        SongService $songService
    )
    {
        $this->profileService = $profileService;
        $this->songService = $songService;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Download the reccorded musics of a user on Smule')
            ->addArgument('username', InputArgument::REQUIRED, 'Smule username')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_OPTIONAL,
                'Do you want to refresh your database ?',
                false
            )
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
            'Smule Music Downloader: '. $username,
            '=====================================',
            '',
        ]);

        $output->writeln([
            '',
            'Checking for user ' . $username . ' on Smule.com',
            '',
        ]);

        $profile = $this->profileService->getProfile($username, $output);

        $forceDownload = $input->getOption('force');

        if(false === $forceDownload)
        {
            $output->writeln([
            '',
            '<info>Skipping soung retrieving step, only reffering to data already in base</info>',
            '',
            ]);
        } else {
            $this->songService->retrieveSongs($profile, $output);
        }
            
        $this->songService->downloadSongs($profile, $output);

        $result = $this->songService->getSongsInfo($profile);

        $table = new Table($output);
        $table
            ->setHeaders(['Type', 'Items'])
            ->setRows([
                ['Audio', $result['audio']],
                ['Video', $result['video']],
                new TableSeparator(),
                ['Downloaded', $result['downloaded']],
                ['To download', $result['active']],
                ['To fix', $result['inactive']],
                ['Deleted', $result['deleted']],
                new TableSeparator(),
                ['TOTAL', $result['total']],
            ])
        ;
        $table->render();

        $io->success('Download done');

        return Command::SUCCESS;
    }
}
