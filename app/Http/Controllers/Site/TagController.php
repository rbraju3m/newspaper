<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Services\ArticleQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

class TagController extends Controller
{
    public function __invoke(Tag $tag): View
    {
        return view('site.tag', [
            'tag' => $tag,
            'articles' => ArticleQuery::cards()
                ->whereHas('tags', fn (Builder $q) => $q->whereKey($tag->id))
                ->newest()
                ->paginate(config('site.per_page')),
        ]);
    }
}
