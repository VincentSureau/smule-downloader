<?php

namespace App\Service;

use Exception;
use App\Entity\Song;
use App\Entity\Profile;
use App\Service\SongService;
use App\Repository\SongRepository;
use Symfony\Component\Panther\Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SongService
{
    const SMULE_SONG_LIST_URL = "https://www.smule.com/s/profile/performance/%s/sing?offset=%s&size=0";

    const SMULE_REQUEST_MEDIA_URL = "https://www.smule.com/p/%s/render";

    const SMULE_SONG_RECCORDING_URL = "https://smule.com/recording%s";

    /**
     * @var HttpClientInterface
     */
    private $client;

    /**
     * @var SongRepository
     */
    private $songRepository;

    /**
     * Undocumented variable
     *
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var integer
     */
    private $offset = 0;

    /**
     * @var AsciiSlugger
     */
    private $slugger;

    /**
     * Undocumented variable
     *
     * @var Client
     */
    private $webClient;

    /**
     * @var KernelInterface
     */
    private $appKernel;

    /**
     * Get a ChromeBrowser client
     *
     * @return Client
     */
    private function getWebClient(): Client
    {
        if(empty($this->webClient))
        {
            $this->webClient = Client::createChromeClient();
        }

        return $this->webClient;
    }

    /**
     * Get current offset
     *
     * @return integer
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Set current offset
     *
     * @param integer $offset
     * @return integer
     */
    public function setOffset(int $offset): int
    {
        $this->offset = $offset;
        return $offest;
    }

    /**
     * @param HttpClientInterface $client
     * @param SongRepository $songRepository
     * @param EntityManagerInterface $entityManager
     * @param KernelInterface $appKernel
     */
    public function __construct(
        HttpClientInterface $client,
        SongRepository $songRepository,
        EntityManagerInterface $entityManager,
        KernelInterface $appKernel
    )
    {
        $this->client = $client;
        $this->entityManager = $entityManager;
        $this->songRepository = $songRepository;
        $this->slugger = new AsciiSlugger();
        $this->appKernel = $appKernel;
    }

    /**
     * Delete profile' stored song and retrieve all profile song from Smule.com
     *
     * @param Profile $profile
     * @param OutputInterface $output
     * @return void
     */
    public function retrieveSongs(Profile $profile, OutputInterface $output): void
    {
        $output->writeln([
            '',
            'Deleting Existing songs from the database',
            '',
        ]);

        $this->songRepository->deleteByProfile($profile);


        $output->writeln([
            '',
            'Checking for all song on Smule.com',
            '',
        ]);

        $progressBar = new ProgressBar($output);

        // iterate to retrieve all songs
        while($this->offset !== -1)
        {
            $response = $this->client->request(
                'GET',
                \sprintf(SongService::SMULE_SONG_LIST_URL, $profile->getUsername(), $this->offset)
            );
    
            $content = $response->getContent();
            $content = $response->toArray();
    
            $this->setOffset($content['next_offset']);

            foreach($content['list'] as $info)
            {
                // create song from data
                $song = new Song();

                $slug = $this->slugger->slug($profile->getUsername() . "_" . $info['title'] . "_" . $info['key']);

                $song
                    ->setSmuleKey($info['key'])
                    ->setTitle($info['title'])
                    ->setSlug($slug)
                    ->setProfile($profile)
                    ->setUrl(sprintf(SongService::SMULE_SONG_RECCORDING_URL, $info['web_url']))
                    ->setType($info['type'])
                ;

                $data = $this->getSongUrlAndStatus($song);

                $song
                    ->setStatus($data["status"])
                    ->setMediaUrl($data["media_url"])
                ;

                $this->entityManager->persist($song);

                $progressBar->advance();
            }

            // save all songs
            $this->entityManager->flush();
        }
        
        $progressBar->finish();
        $output->writeln([
            '',
            'Song retrievied from Smule.com and saved in database',
            '',
        ]);
    }

    /**
     * Get profile's song stats
     *
     * @param Profile $profile
     * @return array
     */
    public function getSongsInfo(Profile $profile): array
    {
        return [
            "audio" => $this->songRepository->count(["type" => "audio", "profile" => $profile]),
            "video" => $this->songRepository->count(["type" => "video", "profile" => $profile]),
            "downloaded" => $this->songRepository->count(["status" => "downloaded", "profile" => $profile]),
            "active" => $this->songRepository->count(["status" => "active", "profile" => $profile]),
            "inactive" => $this->songRepository->count(["status" => "inactive", "profile" => $profile]),
            "deleted" => $this->songRepository->count(["status" => "deleted", "profile" => $profile]),
            "total" => $this->songRepository->count(["profile" => $profile]),
        ];
    }

    /**
     * Retrieve song's media url from Smule.com or request for link if unavailable
     *
     * @param Profile $profile
     * @param OutputInterface $output
     * @return void
     */
    public function fixMissingUrls(Profile $profile, OutputInterface $output): void
    {
        $output->writeln([
            '',
            'Fixing missing links from songs in database',
            '',
        ]);

        $songsToFix = $this->songRepository->findBy(['profile' => $profile, 'status' => 'inactive']);
        $result = [
            "fixed" => 0,
            "requested" => 0,
            "errors" => 0,
            "total" => count($songsToFix)
        ];

        $progressBar = new ProgressBar($output);
        foreach ($progressBar->iterate($songsToFix) as $song)
        {
            $data = $this->getSongUrlAndStatus($song);

            $song
                ->setStatus($data['status'])
                ->setMediaUrl($data['media_url'])
            ;

            $this->entityManager->persist($song);

            if($data['status'] === 'active')
            {
                $result['fixed'] += 1;
            }

            if($song->getStatus() === 'inactive')
            {
                $this->requestMedia($song);
                $requested = $this->clickOnPlay($song);

                if($requested)
                {
                    $result['requested'] += 1;
                } else {
                    $result['errors'] +=1;
                }
            }
            $this->entityManager->flush();
        }
    }

    /**
     * Call Smule api url to request for media url generation
     *
     * @param Song $song
     * @return boolean
     */
    private function requestMedia(Song $song): bool
    {
        $response = $this->client->request(
            'POST',
            sprintf(SongService::SMULE_REQUEST_MEDIA_URL, $song->getSmuleKey())
        );
        
        $statusCode = $response->getStatusCode();
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        return true;
    }


    /**
     * Simulate a click on play button on Smule.com to generate song's media url
     *
     * @param Song $song
     * @return boolean
     */
    private function clickOnPlay(Song $song): bool
    {
        try {
            $this->getWebClient()->request(
                'GET',
                $song->getUrl()
            );

            if(strpos($song->getUrl(), 'ensembles')){
                try {
                    $crawler = $this->getWebClient()->waitFor('.video-icon-bar');
                    $crawler->filter('.video-icon-bar')->click();
                    // dump('ok .video-icon-bar available');
                } catch (\Throwable $th) {
                    //throw $th;
                }
                try {
                    $crawler = $this->getWebClient()->waitFor('.album-art.playable');
                    $crawler->filter('.album-art.playable')->click();
                    // dump('ok .album-art.playable');
                } catch (\Throwable $th) {
                    //throw $th;
                }
            } else {
                $crawler = $this->getWebClient()->waitFor('.sc-fTNIDv.ieXpgb');
                $crawler->filter('.sc-fTNIDv.ieXpgb')->click();
            }
            sleep(10);
            $this->getWebClient()->takeScreenshot('screenshots/screen.png');

            return true;
        } catch (\Throwable $th) {
            dump($th);
            dump("Error on song " . $song->getUrl());
        }

        return false;
    }

    /**
     * Download a song from smule and store file in /public/(audio|video)
     *
     * @param Song $song
     * @return boolean
     */
    private function downloadSong(Song $song): bool
    {
        $response = $this->client->request(
            'GET',
            $song->getMediaUrl()
        );

        if($response->getStatusCode() !== 200)
        {
            dump("An error occured with song " . $song->getUrl());
            return false;
        }
        
        $mimeType = $response->getHeaders()["content-type"][0];
        $parts = explode('/', $mimeType);
        $ext = array_pop($parts);

        $song_unique_title = $song->getSlug() . $ext;

        $file = $this->appKernel->getProjectDir() . '/public/' . $song->getType() . '/' . $song_unique_title;
        file_put_contents($file, $response->getContent());

        $song->setStatus('downloaded');
        $this->entityManager->flush();

        return true;
    }


    /**
     * Download all profile song with status == active
     *
     * @param Profile $profile
     * @param OutputInterface $output
     * @return void
     */
    public function downloadSongs(Profile $profile, OutputInterface $output): void
    {
        $songsToDownload = $this->getActiveSongs($profile);

        $output->writeln([
            '',
            count($songsToDownload) . " songs to download",
            '',
        ]);

        $progressBar = new ProgressBar($output);

        foreach ($progressBar->iterate($songsToDownload) as $song) {
            $this->downloadSong($song);
        }
    }


    /**
     * Get all profile song with active status from the database
     *
     * @param Profile $profile
     * @return array
     */
    private function getActiveSongs(Profile $profile): array
    {
        $songs = $this->songRepository->findBy(['profile' => $profile, 'status' => 'active']);

        return $songs;
    }

    /**
     * Retrieve song media url and status from Smule.com
     *
     * @param Song $song
     * @return array
     */
    private function getSongUrlAndStatus(Song $song): array
    {
        dump($song->getUrl());
        $response = $this->client->request(
            'GET',
            $song->getUrl()
        );
        
        $statusCode = $response->getStatusCode();
        if($response->getStatusCode() !== 200)
        {
            return [
                'status' => 'deleted',
                'media_url' => null
            ];
        }
        
        $html = $response->getContent();
        $crawler = new Crawler($html);
        $url = $crawler->filterXpath("//meta[@name='twitter:player:stream']")->extract(array('content'));

        $status = $url ? 'active' : 'inactive';

        return [
            "status" => $status,
            "media_url" => $url[0] ?? null
        ];
    }
}