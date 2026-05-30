<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../repositories/CharacterRepository.php';

class AdminController extends AppController
{
    private UsersRepository $userRepository;
    private CharacterRepository $characterRepository;

    public function __construct()
    {
        $this->userRepository = new UsersRepository();
        $this->characterRepository = new CharacterRepository();
    }

    public function index(): void
    {
        $this->requireAdmin();
        $this->purgeExpiredDeletionRequests();

        $search = trim($_GET['q'] ?? '');
        $users = $this->userRepository->getAdminUserRows($search);
        foreach ($users as &$user) {
            $storage = $this->getUserStorageStats((int)$user['id']);
            $user['storage_used'] = $storage['usedMb'];
            $user['storage_percent'] = $storage['percent'];
            $user['is_current_admin'] = (int)$user['id'] === (int)$_SESSION['user_id'];
            $user['characters'] = $this->characterRepository->getCharactersByUserId((int)$user['id']);
        }

        $this->render('admin', [
            'title' => 'Admin - OCStudio',
            'adminUsers' => $users,
            'adminSearch' => $search,
            'csrfToken' => $this->csrfToken(),
        ]);
    }

    public function banUser(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        $days = max(1, min(3650, (int)($_POST['days'] ?? 1)));
        $reason = trim($_POST['reason'] ?? '');

        if ($userId > 0 && $userId !== (int)$_SESSION['user_id']) {
            $until = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:sP');
            $this->userRepository->setBan($userId, $until, $reason ?: 'Brak podanego powodu.');
        }

        header('Location: /admin');
        exit();
    }

    public function unbanUser(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $this->userRepository->clearBan($userId);
        }

        header('Location: /admin');
        exit();
    }

    public function scheduleDeleteUser(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0 && $userId !== (int)$_SESSION['user_id']) {
            $this->userRepository->scheduleDeletion($userId);
        }

        header('Location: /admin');
        exit();
    }

    public function cancelDeleteUser(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $this->userRepository->cancelDeletion($userId);
        }

        header('Location: /admin');
        exit();
    }

    private function purgeExpiredDeletionRequests(): void
    {
        foreach ($this->userRepository->getExpiredDeletionUserIds() as $userId) {
            foreach ($this->getUserImageFilenames($userId) as $filename) {
                $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $filename;
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $this->userRepository->deleteUserById($userId);
        }
    }

    private function getUserImageFilenames(int $userId): array
    {
        $filenames = [];
        foreach ($this->characterRepository->getCharactersByUserId($userId) as $character) {
            $this->addImageFilename($filenames, $character->getImage());

            foreach ($this->characterRepository->getCharacterVariants($character->getId()) as $variant) {
                $this->addImageFilename($filenames, $variant['image'] ?? null);
            }
        }

        return array_keys($filenames);
    }

    private function addImageFilename(array &$filenames, ?string $image): void
    {
        $image = trim((string)$image);
        if ($image === '' || in_array($image, ['default.png', 'default.jpg'], true)) {
            return;
        }

        $filename = basename($image);
        if ($filename !== '' && $filename === $image) {
            $filenames[$filename] = true;
        }
    }
}
