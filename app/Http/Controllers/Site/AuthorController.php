<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ArticleQuery;
use Illuminate\View\View;

class AuthorController extends Controller
{
    public function __invoke(User $user): View
    {
        // Only staff have public author pages; a reader's profile is private.
        abort_unless($user->role->isStaff() && $user->isActive(), 404);

        return view('site.author', [
            'author' => $user,
            'articles' => ArticleQuery::cards()
                ->where('author_id', $user->id)
                ->newest()
                ->paginate(config('site.per_page')),
        ]);
    }
}
