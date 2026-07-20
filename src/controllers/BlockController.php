<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
require_once __DIR__ . '/../repositories/UserBlockRepository.php';
require_once __DIR__ . '/../repositories/UserFollowRepository.php';
require_once __DIR__ . '/../repositories/UserRepository.php';

class BlockController extends AppController
{
    private UserBlockRepository $blockRepository;
    private UserFollowRepository $followRepository;
    private UsersRepository $usersRepository;
    private SocialFeatureSettingsRepository $featureSettingsRepository;

    public function __construct()
    {
        $this->blockRepository = new UserBlockRepository();
        $this->followRepository = new UserFollowRepository();
        $this->usersRepository = new UsersRepository();
        $this->featureSettingsRepository = new SocialFeatureSettingsRepository();
    }

    public function block(): void
    {
        $this->change(true);
    }

    public function unblock(): void
    {
        $this->change(false);
    }

    private function change(bool $blocked): void
    {
        $this->requireLogin();

        if (!$this->featureSettingsRepository->isEnabled('community.enabled')) {
            $this->jsonError('Funkcje spolecznosciowe sa obecnie wylaczone przez administracje.', 403);
        }

        try {
            $input = $this->requireJsonPost();
            $targetUserId = (int)($input['userId'] ?? 0);
            $viewerUserId = (int)$_SESSION['user_id'];
            if (!$this->usersRepository->getPublicProfileById($targetUserId)) {
                $this->jsonError('Profil nie zostal znaleziony.', 404);
            }

            $state = $blocked
                ? $this->blockRepository->block($viewerUserId, $targetUserId, 'interaction')
                : $this->blockRepository->unblock($viewerUserId, $targetUserId);

            $this->jsonResponse([
                'success' => true,
                'followerCount' => $this->followRepository->followerCount($targetUserId),
            ] + $state);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie zmienic blokady.');
        }
    }
}
