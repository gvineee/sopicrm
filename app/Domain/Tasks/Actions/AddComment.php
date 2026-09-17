<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Comment;
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
        return Comment::create([
            'commentable_type' => $commentable->getMorphClass(),
            'commentable_id' => $commentable->getKey(),
            'author_user_id' => $author->id,
            'body' => $body,
            'mentions' => $mentionedUserIds,
            'parent_comment_id' => $parentCommentId,
        ]);
    }
}
