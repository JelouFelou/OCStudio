<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/NotificationRepository.php';

class NotificationController extends AppController
{
    private NotificationRepository $notificationRepository;

    public function __construct()
    {
        $this->notificationRepository = new NotificationRepository();
    }

    public function list(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('community.enabled', 'Powiadomienia sa obecnie wylaczone.', true);
        $userId = (int)$_SESSION['user_id'];

        $this->jsonResponse([
            'success' => true,
            'unreadCount' => $this->notificationRepository->unreadCount($userId),
            'notifications' => $this->notificationRepository->latestForUser($userId),
        ]);
    }

    public function markRead(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('community.enabled', 'Powiadomienia sa obecnie wylaczone.', true);
        $input = $this->requireJsonPost();
        $notificationId = (int)($input['notificationId'] ?? 0);
        if ($notificationId <= 0) {
            $this->jsonError('Brak powiadomienia.', 422);
        }

        $this->notificationRepository->markRead((int)$_SESSION['user_id'], $notificationId);
        $this->jsonResponse([
            'success' => true,
            'unreadCount' => $this->notificationRepository->unreadCount((int)$_SESSION['user_id']),
        ]);
    }

    public function markAllRead(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('community.enabled', 'Powiadomienia sa obecnie wylaczone.', true);
        $this->validateCsrfRequest(true);

        $this->notificationRepository->markAllRead((int)$_SESSION['user_id']);
        $this->jsonResponse([
            'success' => true,
            'unreadCount' => 0,
        ]);
    }
}
