<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/PublicationRepository.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
require_once __DIR__ . '/../repositories/UserBlockRepository.php';
require_once __DIR__ . '/../repositories/UserFollowRepository.php';
require_once __DIR__ . '/../repositories/UserRepository.php';

class ProfileController extends AppController
{
    private PublicationRepository $publicationRepository;
    private SocialFeatureSettingsRepository $featureSettingsRepository;
    private UserBlockRepository $blockRepository;
    private UserFollowRepository $followRepository;
    private UsersRepository $usersRepository;

    public function __construct()
    {
        $this->publicationRepository = new PublicationRepository();
        $this->featureSettingsRepository = new SocialFeatureSettingsRepository();
        $this->blockRepository = new UserBlockRepository();
        $this->followRepository = new UserFollowRepository();
        $this->usersRepository = new UsersRepository();
    }

    public function ownProfile(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('community.enabled', 'Profil jest obecnie wylaczony.');

        $viewerUserId = (int)$_SESSION['user_id'];
        $profile = $this->usersRepository->getPublicProfileById($viewerUserId);
        if (!$profile) {
            $this->renderNotFound();
            return;
        }

        $publicationsEnabled = $this->featureSettingsRepository->isEnabled('community.enabled')
            && $this->featureSettingsRepository->isEnabled('publications.enabled');
        $query = trim((string)($_GET['q'] ?? ''));
        $type = (string)($_GET['type'] ?? 'all');
        $type = in_array($type, ['all', 'character', 'story', 'image', 'relation_board', 'template'], true) ? $type : 'all';
        $includeAdult = (string)($_GET['adult'] ?? '') === '1';
        $publicationTotal = $publicationsEnabled
            ? $this->publicationRepository->countVisiblePublicationsByOwner($viewerUserId)
            : 0;
        $publications = $publicationsEnabled
            ? $this->publicationRepository->visiblePublicationsByOwner($viewerUserId, 96, $query, $type, $includeAdult, $viewerUserId)
            : [];

        $this->render('profile', [
            'title' => 'Moj profil - OCStudio',
            'profile' => $profile,
            'publications' => $publications,
            'publicationsDisabled' => !$publicationsEnabled,
            'query' => $query,
            'type' => $type,
            'includeAdult' => $includeAdult,
            'publicationTotal' => $publicationTotal,
            'followerCount' => $this->followRepository->followerCount($viewerUserId),
            'followingCount' => $this->followRepository->followingCount($viewerUserId),
        ]);
    }

    public function updateBio(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('community.enabled', 'Profil jest obecnie wylaczony.');
        $this->validateCsrf();

        $this->usersRepository->updateBio((int)$_SESSION['user_id'], (string)($_POST['bio'] ?? ''));
        header('Location: /profile');
        exit();
    }

    public function updateAvatar(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('community.enabled', 'Profil jest obecnie wylaczony.');
        $this->requireFeatureEnabled('gallery.enabled', 'Galeria jest obecnie wylaczona.');
        $this->validateCsrf();

        $userId = (int)$_SESSION['user_id'];
        $removeAvatar = isset($_POST['remove_avatar']);
        $imageAssetId = $removeAvatar ? null : (int)($_POST['avatar_image_id'] ?? 0);

        if (!$this->usersRepository->updateProfileAvatar($userId, $imageAssetId)) {
            $_SESSION['flash_error'] = 'Nie udalo sie zapisac avatara. Wybierz zdjecie ze swojej galerii.';
        }

        header('Location: /profile');
        exit();
    }

    public function publicProfile(): void
    {
        $username = trim((string)($_GET['username'] ?? ''));
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username)) {
            $this->renderNotFound();
            return;
        }

        if (
            !$this->featureSettingsRepository->isEnabled('community.enabled')
            || !$this->featureSettingsRepository->isEnabled('publications.enabled')
        ) {
            http_response_code(404);
            $this->render('public_profile', [
                'title' => 'Profil niedostepny - OCStudio',
                'profile' => null,
                'publications' => [],
                'communityDisabled' => true,
                'viewerLoggedIn' => isset($_SESSION['user_id']),
            ]);
            return;
        }

        $profile = $this->usersRepository->getPublicProfileByUsername($username);
        if (!$profile) {
            $this->renderNotFound();
            return;
        }

        $query = trim((string)($_GET['q'] ?? ''));
        $type = (string)($_GET['type'] ?? 'all');
        $type = in_array($type, ['all', 'character', 'story', 'image', 'relation_board', 'template'], true) ? $type : 'all';
        $includeAdult = (string)($_GET['adult'] ?? '') === '1';
        $publicationTotal = $this->publicationRepository->countVisiblePublicationsByOwner((int)$profile['id']);
        $viewerUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $publications = $this->publicationRepository->visiblePublicationsByOwner(
            (int)$profile['id'],
            96,
            $query,
            $type,
            $includeAdult,
            $viewerUserId
        );
        $followsEnabled = $this->featureSettingsRepository->isEnabled('follows.enabled');
        $blockState = $viewerUserId !== null
            ? $this->blockRepository->state($viewerUserId, (int)$profile['id'])
            : ['viewerBlocksTarget' => false, 'targetBlocksViewer' => false];
        $this->render('public_profile', [
            'title' => $profile['username'] . ' - OCStudio',
            'profile' => $profile,
            'publications' => $publications,
            'communityDisabled' => false,
            'query' => $query,
            'type' => $type,
            'includeAdult' => $includeAdult,
            'publicationTotal' => $publicationTotal,
            'csrfToken' => $viewerUserId !== null ? $this->csrfToken() : '',
            'followsEnabled' => $followsEnabled,
            'viewerLoggedIn' => $viewerUserId !== null,
            'isOwnProfile' => $viewerUserId !== null && $viewerUserId === (int)$profile['id'],
            'canFollow' => $followsEnabled
                && $viewerUserId !== null
                && $viewerUserId !== (int)$profile['id']
                && empty($blockState['viewerBlocksTarget'])
                && empty($blockState['targetBlocksViewer']),
            'canBlock' => $viewerUserId !== null && $viewerUserId !== (int)$profile['id'],
            'viewerBlocksTarget' => !empty($blockState['viewerBlocksTarget']),
            'targetBlocksViewer' => !empty($blockState['targetBlocksViewer']),
            'isFollowing' => $viewerUserId !== null && $this->followRepository->isFollowing($viewerUserId, (int)$profile['id']),
            'followerCount' => $this->followRepository->followerCount((int)$profile['id']),
            'followingCount' => $this->followRepository->followingCount((int)$profile['id']),
        ]);
    }

    private function renderNotFound(): void
    {
        http_response_code(404);
        $this->render('public_profile', [
            'title' => 'Profil nie znaleziony - OCStudio',
            'profile' => null,
            'publications' => [],
            'communityDisabled' => false,
            'viewerLoggedIn' => isset($_SESSION['user_id']),
        ]);
    }
}
