<?php

namespace App\Service;

use Exception;
use App\Entity\Profile;
use App\Repository\ProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ProfileService
{
    /**
     * @var HttpClientInterface
     */
    private $client;

    /**
     * @var ProfileRepository
     */
    private $profileRepository;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @param HttpClientInterface $client
     * @param ProfileRepository $profileRepository
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(HttpClientInterface $client, ProfileRepository $profileRepository, EntityManagerInterface $entityManager)
    {
        $this->client = $client;
        $this->entityManager = $entityManager;
        $this->profileRepository = $profileRepository;
    }

    /**
     * Retrieve user profile on Smule.com and store it in the database if needed
     *
     * @param string $username
     * @param OutputInterface $output
     * @return Profile
     */
    public function getProfile(string $username, OutputInterface $output): Profile
    {
        $profile = $this->profileRepository->findOneBy(['username' => $username, 'status' => 'verified']);

        if(!empty($profile))
        {
            $output->writeln([
                '',
                'Profile ' . $username . ' found in database',
                '',
            ]);
            return $profile;
        }

        $output->writeln([
            '',
            'Profile ' . $username . ' not found in database, trying to create new profile from Smule.com',
            '',
        ]);

        $profileUrl = 'https://www.smule.com/'. $username;

        $response = $this->client->request(
            'GET',
            $profileUrl
        );

        $statusCode = $response->getStatusCode();

        if($statusCode === 200)
        {
            $profile = new Profile();
            $profile
                ->setUsername($username)
                ->setUrl($profileUrl)
                ->setStatus('verified')
            ;

            $this->entityManager->persist($profile);
            $this->entityManager->flush();

            return $profile;
        }

        throw new Exception("User profile was not found on Smule, Url : $profileUrl");
    }
}