<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/PublicationRepository.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
require_once __DIR__ . '/../repositories/UserRepository.php';

class CommunityController extends AppController
{
    private PublicationRepository $publicationRepository;
    private SocialFeatureSettingsRepository $featureSettingsRepository;
    private UsersRepository $usersRepository;

    public function __construct()
    {
        $this->publicationRepository = new PublicationRepository();
        $this->featureSettingsRepository = new SocialFeatureSettingsRepository();
        $this->usersRepository = new UsersRepository();
    }

    public function index(): void
    {
        $this->requireLogin();

        $query = trim((string)($_GET['q'] ?? ''));
        $scope = (string)($_GET['scope'] ?? 'all');
        $type = (string)($_GET['type'] ?? 'all');
        $sort = (string)($_GET['sort'] ?? 'desc');
        $includeAdult = (string)($_GET['adult'] ?? '') === '1';
        $viewerUserId = (int)$_SESSION['user_id'];

        $scope = in_array($scope, ['all', 'content', 'users', 'following', 'mine'], true) ? $scope : 'all';
        $type = in_array($type, ['all', 'character', 'story', 'image', 'relation_board', 'template'], true) ? $type : 'all';
        $sort = in_array($sort, ['asc', 'desc', 'random'], true) ? $sort : 'desc';

        $communityEnabled = $this->featureSettingsRepository->isEnabled('community.enabled');
        $publicationsEnabled = $this->featureSettingsRepository->isEnabled('publications.enabled');
        $publicSearchEnabled = $this->featureSettingsRepository->isEnabled('public_search.enabled');

        $publications = [];
        $users = [];
        if ($communityEnabled && $publicationsEnabled && $publicSearchEnabled) {
            if ($scope === 'following' && $this->featureSettingsRepository->isEnabled('follows.enabled')) {
                $publications = $this->publicationRepository->exploreFollowedPublications(
                    $viewerUserId,
                    $query,
                    $type,
                    $includeAdult,
                    36,
                    $sort
                );
            } elseif ($scope !== 'users' && $scope !== 'following') {
                $publications = $this->publicationRepository->exploreVisiblePublications(
                    $query,
                    $viewerUserId,
                    $type,
                    $includeAdult,
                    $scope === 'mine' ? 48 : 36,
                    $sort
                );

                if ($scope === 'mine') {
                    $publications = array_values(array_filter(
                        $publications,
                        static fn(array $publication): bool => !empty($publication['isOwn'])
                    ));
                }
            }

            if ($scope === 'users') {
                $users = $this->usersRepository->publicProfilesDirectory($viewerUserId, 40, $query, $sort);
            } elseif ($scope === 'all' && mb_strlen($query) >= 2) {
                $users = $this->usersRepository->searchPublicProfiles($query, $viewerUserId, 16, $sort);
            }
        }

        $this->render('community', [
            'title' => 'Spolecznosc - OCStudio',
            'communityDisabled' => !$communityEnabled,
            'publicationsDisabled' => !$publicationsEnabled || !$publicSearchEnabled,
            'publications' => $publications,
            'users' => $users,
            'query' => $query,
            'scope' => $scope,
            'type' => $type,
            'sort' => $sort,
            'includeAdult' => $includeAdult,
            'followsReady' => $this->featureSettingsRepository->isEnabled('follows.enabled'),
        ]);
    }
}
