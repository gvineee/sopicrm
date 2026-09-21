<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Notifications\Support\NotificationCreator;
use App\Domain\Notifications\Support\NotificationType;
use App\Domain\Tasks\Models\Comment;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * spec section 10: "კომენტარები (ტექსტი, mentions, replies, ავტორი/დრო,
 * edit history)". `mentions` is a list of User ids the client resolved
 * (e.g. from an @-autocomplete against project members) — validated by the
 * FormRequest to belong to the same organization, never trusted blindly
 * beyond that.
 */
class AddComment
{
    /**
     * @param  list<string>  $mentionedUserIds
     */
    public function execute(Model $commentable, User $author, string $body, array $mentionedUserIds = [], ?string $parentCommentId = null): Comment
    {
        $comment = Comment::create([
            'commentable_type' => $commentable->getMorphClass(),
            'commentable_id' => $commentable->getKey(),
            'author_user_id' => $author->id,
            'body' => $body,
            'mentions' => $mentionedUserIds,
            'parent_comment_id' => $parentCommentId,
        ]);

        $this->notifyMentioned($commentable, $author, $mentionedUserIds, $comment);

        return $comment;
    }

    /**
     * NOTIFY-01: only Task is a real `commentable` in this codebase today
     * (per this class's own docblock) — a future second commentable type
     * needs its own deep-link case added here, not a silent guess. Never
     * notifies the author for mentioning themselves.
     *
     * @param  list<string>  $mentionedUserIds
     */
    private function notifyMentioned(Model $commentable, User $author, array $mentionedUserIds, Comment $comment): void
    {
        if (! $commentable instanceof Task) {
            return;
        }

        $deepLink = "/projects/{$commentable->project_id}/tasks/{$commentable->id}";

        foreach (array_unique($mentionedUserIds) as $userId) {
            if ($userId === $author->id) {
                continue;
            }

            /** @var User|null $mentioned */
            $mentioned = User::query()->find($userId);

            if ($mentioned === null) {
                continue;
            }

            NotificationCreator::create(
                recipient: $mentioned,
                type: NotificationType::MENTION,
                title: 'ხსენება კომენტარში',
                message: "{$author->name}-მა გახსენათ დავალებაზე \"{$commentable->title}\"",
                dedupKey: "mention:{$comment->id}:{$userId}",
                deepLink: $deepLink,
            );
        }
    }
}
