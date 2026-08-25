<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Services\ArticleQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

class TopicController extends Controller
{
    public function __invoke(Topic $topic): View
    {
        abort_unless($topic->is_active, 404);

        return view('site.topic', [
            'topic' => $topic,
            'articles' => ArticleQuery::cards()
                ->whereHas('topics', fn (Builder $q) => $q->whereKey($topic->id))
                ->newest()
                ->paginate(config('site.per_page')),
        ]);
    }
}
