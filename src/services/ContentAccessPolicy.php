<?php

require_once __DIR__ . '/../models/Story.php';

class ContentAccessPolicy
{
    public function canViewOwnedStory(Story $story, int $userId, bool $includeHidden, bool $worldHiddenInPath): bool
    {
        if ($userId <= 0 || $story->getIdUser() !== $userId) {
            return false;
        }

        if ($includeHidden) {
            return true;
        }

        return !$story->isHidden() && !$worldHiddenInPath;
    }

    public function canViewStorySource(Story $story, int $userId, bool $includeHidden, bool $worldHiddenInPath): bool
    {
        // Until publication snapshots exist, story sources are private owner-only data.
        return $this->canViewOwnedStory($story, $userId, $includeHidden, $worldHiddenInPath);
    }
}
