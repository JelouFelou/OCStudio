<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/NotificationRepository.php';
require_once __DIR__ . '/../repositories/PublicationRepository.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
require_once __DIR__ . '/../repositories/UserBlockRepository.php';
require_once __DIR__ . '/../repositories/UserFollowRepository.php';
require_once __DIR__ . '/../services/PublicationService.php';

class PublicationController extends AppController
{
    private PublicationService $publicationService;
    private NotificationRepository $notificationRepository;
    private PublicationRepository $publicationRepository;
    private SocialFeatureSettingsRepository $featureSettingsRepository;
    private UserBlockRepository $blockRepository;
    private UserFollowRepository $followRepository;

    public function __construct()
    {
        $this->publicationService = new PublicationService();
        $this->notificationRepository = new NotificationRepository();
        $this->publicationRepository = new PublicationRepository();
        $this->featureSettingsRepository = new SocialFeatureSettingsRepository();
        $this->blockRepository = new UserBlockRepository();
        $this->followRepository = new UserFollowRepository();
    }

    public function view(): void
    {
        $viewerUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $publication = $this->publicationRepository->findVisibleByPublicId((string)($_GET['id'] ?? ''), $viewerUserId);
        if (!$publication) {
            http_response_code(404);
            $this->render('public_publication', [
                'title' => 'Publikacja nie znaleziona - OCStudio',
                'publication' => null,
                'viewerLoggedIn' => isset($_SESSION['user_id']),
            ]);
            return;
        }

        $reactionsEnabled = $this->featureSettingsRepository->isEnabled('community.enabled')
            && $this->featureSettingsRepository->isEnabled('publications.enabled')
            && $this->featureSettingsRepository->isEnabled('reactions.enabled');
        $commentsEnabled = $this->featureSettingsRepository->isEnabled('community.enabled')
            && $this->featureSettingsRepository->isEnabled('publications.enabled')
            && $this->featureSettingsRepository->isEnabled('comments.enabled');
        $reportsEnabled = $this->featureSettingsRepository->isEnabled('community.enabled')
            && $this->featureSettingsRepository->isEnabled('publications.enabled')
            && $this->featureSettingsRepository->isEnabled('reports.enabled');
        $copyingEnabled = $this->featureSettingsRepository->isEnabled('community.enabled')
            && $this->featureSettingsRepository->isEnabled('publications.enabled')
            && $this->featureSettingsRepository->isEnabled('copying.enabled');
        $publication['reactions'] = $this->publicationRepository->reactionSummary(
            (int)$publication['id'],
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );
        $publication['comments'] = $this->publicationRepository->visibleComments(
            (int)$publication['id'],
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );

        $payload = $publication['payload'] ?? [];
        $title = (string)($payload['character']['name'] ?? $payload['template']['name'] ?? $payload['image']['title'] ?? 'Publikacja');
        $this->render('public_publication', [
            'title' => $title . ' - OCStudio',
            'publication' => $publication,
            'payload' => $payload,
            'reactionsEnabled' => $reactionsEnabled,
            'commentsEnabled' => $commentsEnabled,
            'reportsEnabled' => $reportsEnabled,
            'copyingEnabled' => $copyingEnabled,
            'viewerLoggedIn' => isset($_SESSION['user_id']),
            'csrfToken' => isset($_SESSION['user_id']) ? $this->csrfToken() : '',
        ]);
    }

    public function publishCharacter(): void
    {
        $this->requireLogin();

        try {
            $input = $this->requireJsonPost();
            $characterId = (int)($input['characterId'] ?? 0);
            if ($characterId <= 0) {
                $this->jsonError('Brak postaci.', 422);
            }

            $variantId = ((int)($input['variantId'] ?? 0)) ?: null;
            $publication = $this->publicationService->publishCharacter(
                (int)$_SESSION['user_id'],
                $characterId,
                $variantId,
                (string)($input['changeReason'] ?? 'initial')
            );

            $this->notifyFollowersAboutPublication((int)$_SESSION['user_id'], $publication);

            $this->jsonResponse(['success' => true, 'publication' => $publication]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie opublikowac postaci.');
        }
    }

    public function publishTemplate(): void
    {
        $this->requireLogin();

        try {
            $input = $this->requireJsonPost();
            $templateId = (int)($input['templateId'] ?? 0);
            if ($templateId <= 0) {
                $this->jsonError('Brak szablonu.', 422);
            }

            $publication = $this->publicationService->publishTemplate(
                (int)$_SESSION['user_id'],
                $templateId,
                (string)($input['changeReason'] ?? 'initial')
            );

            $this->notifyFollowersAboutPublication((int)$_SESSION['user_id'], $publication);

            $this->jsonResponse(['success' => true, 'publication' => $publication]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie opublikowac szablonu.');
        }
    }

    public function publishImage(): void
    {
        $this->requireLogin();

        try {
            $input = $this->requireJsonPost();
            $imageAssetId = (int)($input['imageAssetId'] ?? $input['imageId'] ?? 0);
            if ($imageAssetId <= 0) {
                $this->jsonError('Brak zdjecia.', 422);
            }

            $publication = $this->publicationService->publishImage(
                (int)$_SESSION['user_id'],
                $imageAssetId,
                (string)($input['changeReason'] ?? 'initial')
            );

            $this->notifyFollowersAboutPublication((int)$_SESSION['user_id'], $publication);

            $this->jsonResponse(['success' => true, 'publication' => $publication]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie opublikowac zdjecia.');
        }
    }

    public function publishStory(): void
    {
        $this->requireLogin();

        try {
            $input = $this->requireJsonPost();
            $storyId = (int)($input['storyId'] ?? 0);
            if ($storyId <= 0) {
                $this->jsonError('Brak historii.', 422);
            }

            $publication = $this->publicationService->publishStory(
                (int)$_SESSION['user_id'],
                $storyId,
                (string)($input['changeReason'] ?? 'initial')
            );

            $this->notifyFollowersAboutPublication((int)$_SESSION['user_id'], $publication);

            $this->jsonResponse(['success' => true, 'publication' => $publication]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie opublikowac historii.');
        }
    }

    public function publishRelationBoard(): void
    {
        $this->requireLogin();

        try {
            $input = $this->requireJsonPost();
            $boardId = (int)($input['boardId'] ?? 0);
            if ($boardId <= 0) {
                $this->jsonError('Brak tablicy relacji.', 422);
            }

            $publication = $this->publicationService->publishRelationBoard(
                (int)$_SESSION['user_id'],
                $boardId,
                (string)($input['changeReason'] ?? 'initial')
            );

            $this->notifyFollowersAboutPublication((int)$_SESSION['user_id'], $publication);

            $this->jsonResponse(['success' => true, 'publication' => $publication]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie opublikowac relacji.');
        }
    }

    public function unpublish(): void
    {
        $this->requireLogin();

        try {
            $input = $this->requireJsonPost();
            $publicationId = (int)($input['publicationId'] ?? 0);
            if ($publicationId <= 0) {
                $this->jsonError('Brak publikacji.', 422);
            }

            $publication = $this->publicationService->unpublish((int)$_SESSION['user_id'], $publicationId);
            $this->jsonResponse(['success' => true, 'publication' => $publication]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie cofnac publikacji.');
        }
    }

    public function copy(): void
    {
        $this->requireLogin();

        if (!$this->featureSettingsRepository->isEnabled('community.enabled')
            || !$this->featureSettingsRepository->isEnabled('publications.enabled')
            || !$this->featureSettingsRepository->isEnabled('copying.enabled')) {
            $this->jsonError('Kopiowanie jest obecnie wylaczone przez administracje.', 403);
        }

        try {
            $input = $this->requireJsonPost();
            $publicId = trim((string)($input['publicId'] ?? ''));
            if ($publicId === '') {
                $this->jsonError('Brak publikacji.', 422);
            }

            $publication = $this->publicationService->copyPublication((int)$_SESSION['user_id'], $publicId);
            if (($publication['status'] ?? '') === 'published') {
                $this->notifyFollowersAboutPublication((int)$_SESSION['user_id'], $publication);
            }

            $this->jsonResponse(['success' => true, 'publication' => $publication]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie skopiowac publikacji.');
        }
    }

    public function react(): void
    {
        $this->requireLogin();

        if (!$this->featureSettingsRepository->isEnabled('community.enabled')
            || !$this->featureSettingsRepository->isEnabled('publications.enabled')
            || !$this->featureSettingsRepository->isEnabled('reactions.enabled')) {
            $this->jsonError('Reakcje sa obecnie wylaczone przez administracje.', 403);
        }

        try {
            $input = $this->requireJsonPost();
            $publicationId = (int)($input['publicationId'] ?? 0);
            $reactionType = trim((string)($input['reactionType'] ?? ''));
            if ($publicationId <= 0 || $reactionType === '') {
                $this->jsonError('Brak reakcji.', 422);
            }
            $this->assertInteractionAllowed($publicationId, (int)$_SESSION['user_id']);

            $summary = $this->publicationRepository->toggleReaction(
                $publicationId,
                (int)$_SESSION['user_id'],
                $reactionType
            );
            if ($summary === null) {
                $this->jsonError('Publikacja nie zostala znaleziona.', 404);
            }

            if (($summary['currentReaction'] ?? null) === $reactionType) {
                $this->notifyPublicationOwner(
                    $publicationId,
                    (int)$_SESSION['user_id'],
                    'publication.reaction',
                    'Nowa reakcja pod publikacja',
                    'Ktos zareagowal na Twoja publikacje.'
                );
            }

            $this->jsonResponse(['success' => true, 'reactions' => $summary]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie zapisac reakcji.');
        }
    }

    public function comment(): void
    {
        $this->requireLogin();

        if (!$this->featureSettingsRepository->isEnabled('community.enabled')
            || !$this->featureSettingsRepository->isEnabled('publications.enabled')
            || !$this->featureSettingsRepository->isEnabled('comments.enabled')) {
            $this->jsonError('Komentarze sa obecnie wylaczone przez administracje.', 403);
        }

        try {
            $input = $this->requireJsonPost();
            $publicationId = (int)($input['publicationId'] ?? 0);
            $body = (string)($input['body'] ?? '');
            if ($publicationId <= 0) {
                $this->jsonError('Brak publikacji.', 422);
            }
            $this->assertInteractionAllowed($publicationId, (int)$_SESSION['user_id']);

            $comment = $this->publicationRepository->addComment(
                $publicationId,
                (int)$_SESSION['user_id'],
                $body
            );
            if ($comment === null) {
                $this->jsonError('Publikacja nie zostala znaleziona.', 404);
            }

            $this->notifyPublicationOwner(
                $publicationId,
                (int)$_SESSION['user_id'],
                'publication.comment',
                'Nowy komentarz pod publikacja',
                mb_substr((string)$comment['body'], 0, 140)
            );

            $this->jsonResponse(['success' => true, 'comment' => $comment]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie zapisac komentarza.');
        }
    }

    public function report(): void
    {
        $this->requireLogin();

        if (!$this->featureSettingsRepository->isEnabled('community.enabled')
            || !$this->featureSettingsRepository->isEnabled('publications.enabled')
            || !$this->featureSettingsRepository->isEnabled('reports.enabled')) {
            $this->jsonError('Zgloszenia sa obecnie wylaczone przez administracje.', 403);
        }

        try {
            $input = $this->requireJsonPost();
            $publicationId = (int)($input['publicationId'] ?? 0);
            if ($publicationId <= 0) {
                $this->jsonError('Brak publikacji.', 422);
            }

            $result = $this->publicationRepository->reportPublication(
                $publicationId,
                (int)$_SESSION['user_id'],
                (string)($input['reasonCategory'] ?? 'other'),
                (string)($input['details'] ?? ''),
                $this->featureSettingsRepository->integerValue('reports.auto_adult_threshold', 15)
            );
            if ($result === null) {
                $this->jsonError('Publikacja nie zostala znaleziona.', 404);
            }

            $publication = $this->publicationRepository->basicPublicationInfo($publicationId);
            $this->notificationRepository->createForAdmins(
                (int)$_SESSION['user_id'],
                'publication.report',
                'Nowe zgloszenie publikacji',
                $publication ? (string)$publication['title'] : 'Publikacja zostala zgloszona.',
                'publication',
                $publicationId,
                '/admin',
                ['openReportCount' => $result['openReportCount'] ?? null]
            );

            $this->jsonResponse(['success' => true, 'report' => $result]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie zapisac zgloszenia.');
        }
    }

    private function notifyPublicationOwner(int $publicationId, int $actorUserId, string $type, string $title, string $body): void
    {
        $publication = $this->publicationRepository->basicPublicationInfo($publicationId);
        if (!$publication || (int)$publication['ownerUserId'] === $actorUserId) {
            return;
        }

        $this->notificationRepository->create(
            (int)$publication['ownerUserId'],
            $actorUserId,
            $type,
            $title,
            $body,
            'publication',
            $publicationId,
            (string)$publication['url'],
            ['publicationTitle' => $publication['title']]
        );
    }

    private function assertInteractionAllowed(int $publicationId, int $actorUserId): void
    {
        $publication = $this->publicationRepository->basicPublicationInfo($publicationId);
        if (!$publication) {
            return;
        }

        if ($this->blockRepository->hasInteractionBlockBetween((int)$publication['ownerUserId'], $actorUserId)) {
            $this->jsonError('Nie mozesz wykonac tej akcji dla tej publikacji.', 403);
        }
    }

    private function notifyFollowersAboutPublication(int $ownerUserId, array $publication): void
    {
        if (!$this->featureSettingsRepository->isEnabled('community.enabled')
            || !$this->featureSettingsRepository->isEnabled('follows.enabled')) {
            return;
        }

        $publicationId = (int)($publication['id'] ?? 0);
        if ($publicationId <= 0) {
            return;
        }

        $info = $this->publicationRepository->basicPublicationInfo($publicationId);
        if (!$info) {
            return;
        }

        $isFirstRevision = (int)($publication['revisionNumber'] ?? 0) <= 1;
        $title = $isFirstRevision ? 'Nowa publikacja obserwowanego' : 'Aktualizacja publikacji obserwowanego';
        $body = (string)($info['title'] ?? 'Publikacja');

        foreach ($this->followRepository->followerUserIds($ownerUserId) as $followerUserId) {
            if ($followerUserId === $ownerUserId) {
                continue;
            }

            $this->notificationRepository->create(
                $followerUserId,
                $ownerUserId,
                $isFirstRevision ? 'follow.publication.created' : 'follow.publication.updated',
                $title,
                $body,
                'publication',
                $publicationId,
                (string)$info['url'],
                ['publicationTitle' => $info['title']]
            );
        }
    }
}
