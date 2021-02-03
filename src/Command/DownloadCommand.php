<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DownloadCommand extends Command
{
    protected static $defaultName = 'app:download';

    private $client;

    private $appKernel;

    private $logger;

    private $length = 0;

    private $offset = 575;


    /**
     * @param HttpClientInterface $client
     * @param KernelInterface $appKernel
     * @param LoggerInterface $logger
     */
    public function __construct(HttpClientInterface $client, KernelInterface $appKernel, LoggerInterface $songsLogger)
    {
        $this->client = $client;
        $this->appKernel = $appKernel;
        $this->logger = $songsLogger;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Download the reccorded musics of a user on Smule')
            ->addArgument('username', InputArgument::REQUIRED, 'Smule username')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {   
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');

        $output->writeln([
            'Smule Music Downloader: '. $username,
            '=====================================',
            '',
        ]);

        while($this->offset !== -1)
        {
            $this->downloadSong($username, $output);
        }

        $io->success('You have downloaded ' . $this->length . 'songs');

        return Command::SUCCESS;
    }

    private function downloadSong(string $username, OutputInterface $output)
    {
        $response = $this->client->request(
            'GET',
            'https://www.smule.com/s/profile/performance/'. $username .'/sing?offset='. $this->offset .'&size=0'
        );

        $statusCode = $response->getStatusCode();
        $contentType = $response->getHeaders()['content-type'][0];
        $content = $response->getContent();
        $content = $response->toArray();

        $songs = $content['list'];

        $this->length += count($songs);

        $this->offset = $content['next_offset'];
        $slugger = new AsciiSlugger();

        $progressBar = new ProgressBar($output);
        foreach($progressBar->iterate($songs) as $song)
        {
            // get song page url
            $song_url = $song['web_url'];

            $response = $this->client->request(
                'GET',
                'https://smule.com/recording' . $song_url
            );
            
            $statusCode = $response->getStatusCode();
            if($response->getStatusCode() !== 200)
            {
                $output->writeln([
                    "",
                    "<error>Failed to download :". $song_url . "=>" . $song['title'] . "</error>"
                ]);
                continue;
            }
            $contentType = $response->getHeaders()['content-type'][0];

            // retrieve song file link
            $html = $response->getContent();
            $crawler = new Crawler($html);
            $url = $crawler->filterXpath("//meta[@name='twitter:player:stream']")->extract(array('content'));

            if(empty($url)){
                $output->writeln([
                    "",
                    "<error>Failed to download :". $song_url . "=>" . $song['title'] . "</error>"
                ]);
                $this->logger->error("Missing link for song : " . $song['title'] . ", url :" . $song_url);
                continue;
            }

            $url = $url[0];

            // download the song
            $response = $this->client->request(
                'GET',
                $url
            );

            if($response->getStatusCode() !== 200)
            {
                $output->writeln([
                    "",
                    "<error>Failed to download :". $url . "=>" . $song['title'] . "</error>"
                ]);                continue;
            }

            // Create a unique song title
            
            $mimeType = $response->getHeaders()["content-type"][0];
            $parts = explode('/', $mimeType);
            $ext = array_pop($parts);

            $song_original_title = $song['title'];
            $song_slugged_title = $slugger->slug($song_original_title);
            $song_unique_title = $username . '_' . $song_slugged_title . '_' . $song['key'] . '.' . $ext;

            $file = $this->appKernel->getProjectDir() . '/public/' . $song['type'] . '/' . $song_unique_title;
            file_put_contents($file, $response->getContent());
        }

        $output->writeln([
            '',
            '',
            'Downloaded: '. $this->length,
            '',
        ]);
    }
}
