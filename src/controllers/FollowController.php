<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/NotificationRepository.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
require_once __DIR__ . '/../repositories/UserBlockRepository.php';
require_once __DIR__ . '/../repositories/UserFollowRepository.php';
require_once __DIR__ . '/../repositories/UserRepository.php';

class FollowController extends AppController
{
    private UserFollowRepository $followRepository;
    private UserBlockRepository $blockRepository;
    private UsersRepository $usersRepository;
    private SocialFeatureSettingsRepository $featureSettingsRepository;
    private NotificationRepository $notificationRepository;

    public function __construct()
    {
        $this->followRepository = new UserFollowRepository();
        $this->blockRepository = new UserBlockRepository();
        $this->usersRepository = new UsersRepository();
        $this->featureSettingsRepository = new SocialFeatureSettingsRepository();
        $this->notificationRepository = new NotificationRepository();
    }

    public function toggle(): void
    {
        $this->requireLogin();

        if (!$this->featureSettingsRepository->isEnabled('community.enabled')
            || !$this->featureSettingsRepository->isEnabled('follows.enabled')) {
            $this->jsonError('Obserwowanie jest obecnie wylaczone przez administracje.', 403);
        }

        try {
            $input = $this->requireJsonPost();
            $followedUserId = (int)($input['userId'] ?? 0);
            $viewerUserId = (int)$_SESSION['user_id'];

            $profile = $this->usersRepository->getPublicProfileById($followedUserId);
            if (!$profile) {
                $this->jsonError('Profil nie zostal znaleziony.', 404);
            }
            if ($this->blockRepository->hasInteractionBlockBetween($followedUserId, $viewerUserId)) {
                $this->jsonError('Nie mozna obserwowac tego profilu.', 403);
            }

            $result = $this->followRepository->toggle($viewerUserId, $followedUserId);
            if (!empty($result['following'])) {
                $this->notificationRepository->create(
                    $followedUserId,
                    $viewerUserId,
                    'user.follow',
                    'Nowy obserwujacy',
                    'Ktos zaczal obserwowac Twoj profil.',
                    'user',
                    $followedUserId,
                    '/u/' . rawurlencode((string)$profile['username'])
                );
            }

            $this->jsonResponse(['success' => true] + $result);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie zmienic obserwowania.');
        }
    }
}
