<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/MessageRepository.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';

class MessageController extends AppController
{
    private MessageRepository $messageRepository;
    private SocialFeatureSettingsRepository $featureSettingsRepository;

    public function __construct()
    {
        $this->messageRepository = new MessageRepository();
        $this->featureSettingsRepository = new SocialFeatureSettingsRepository();
    }

    public function conversations(): void
    {
        $this->requireMessagesEnabled();
        $userId = (int)$_SESSION['user_id'];

        $this->jsonResponse([
            'success' => true,
            'unreadCount' => $this->messageRepository->unreadCount($userId),
            'conversations' => $this->messageRepository->listConversations($userId),
        ]);
    }

    public function searchRecipients(): void
    {
        $this->requireMessagesEnabled();
        $query = trim((string)($_GET['q'] ?? ''));

        $this->jsonResponse([
            'success' => true,
            'users' => $this->messageRepository->searchRecipients((int)$_SESSION['user_id'], $query),
        ]);
    }

    public function start(): void
    {
        $this->requireMessagesEnabled();

        try {
            $input = $this->requireJsonPost();
            $conversation = $this->messageRepository->findOrCreateDirectConversation(
                (int)$_SESSION['user_id'],
                (int)($input['userId'] ?? 0)
            );

            $this->jsonResponse([
                'success' => true,
                'conversation' => $conversation,
            ]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie rozpoczac rozmowy.');
        }
    }

    public function thread(): void
    {
        $this->requireMessagesEnabled();
        $conversationUuid = trim((string)($_GET['conversation'] ?? ''));
        $afterId = (int)($_GET['after'] ?? 0);

        try {
            $conversation = $this->messageRepository->conversationForUser((int)$_SESSION['user_id'], $conversationUuid);
            if (!$conversation) {
                $this->jsonError('Rozmowa nie zostala znaleziona.', 404);
            }

            $this->jsonResponse([
                'success' => true,
                'conversation' => $conversation,
                'messages' => $this->messageRepository->messagesForUser((int)$_SESSION['user_id'], $conversationUuid, $afterId),
                'unreadCount' => $this->messageRepository->unreadCount((int)$_SESSION['user_id']),
            ]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie pobrac rozmowy.');
        }
    }

    public function send(): void
    {
        $this->requireMessagesEnabled();

        try {
            $input = $this->requireJsonPost();
            $message = $this->messageRepository->sendMessage(
                (int)$_SESSION['user_id'],
                trim((string)($input['conversationId'] ?? '')),
                (string)($input['body'] ?? '')
            );

            $this->jsonResponse([
                'success' => true,
                'message' => $message,
                'unreadCount' => $this->messageRepository->unreadCount((int)$_SESSION['user_id']),
            ]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie wyslac wiadomosci.');
        }
    }

    private function requireMessagesEnabled(): void
    {
        $this->requireLogin();

        if (!$this->featureSettingsRepository->isEnabled('community.enabled')
            || !$this->featureSettingsRepository->isEnabled('messages.enabled')) {
            $this->jsonError('Wiadomosci sa obecnie wylaczone przez administracje.', 403);
        }
    }
}
