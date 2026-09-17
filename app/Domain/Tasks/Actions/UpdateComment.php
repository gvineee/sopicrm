<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Tasks\Models\Comment;

/**
 * spec section 10: comments keep "edit history". Every edit pushes the
 * PREVIOUS body (not the new one) onto `edit_history` with the timestamp it
 * was replaced at, so the full chain of prior versions is reconstructible.
 */
class UpdateComment
{
    public function execute(Comment $comment, string $newBody): Comment
    {
        $history = $comment->edit_history ?? [];
        $editedAt = $comment->edited_at ?? $comment->created_at;
        $history[] = [
            'body' => $comment->body,
            'edited_at' => $editedAt->toIso8601String(),
        ];

        $comment->update([
            'body' => $newBody,
            'edited_at' => now(),
            'edit_history' => $history,
        ]);

        return $comment;
    }
}
