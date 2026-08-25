<?php

namespace App\Http\Requests\Site;

use App\Models\Comment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CommentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:'.\App\Models\Setting::get('comments_min_length', 10), 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'মন্তব্য লিখুন।',
            'body.min' => 'মন্তব্যটি আরেকটু বিস্তারিত লিখুন।',
            'body.max' => 'মন্তব্য সর্বোচ্চ ২০০০ অক্ষরের হতে পারে।',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $parentId = $this->input('parent_id');

                if (! $parentId) {
                    return;
                }

                $parent = Comment::find($parentId);

                // A reply must belong to the same article, or a crafted
                // parent_id could graft a thread onto an unrelated story.
                if (! $parent || $parent->article_id !== $this->route('article')->id) {
                    $validator->errors()->add('parent_id', 'এই মন্তব্যের উত্তর দেওয়া যাবে না।');

                    return;
                }

                // One level of nesting only — deeper threads are unreadable on
                // a phone, which is where most of this traffic is.
                if ($parent->parent_id !== null) {
                    $this->merge(['parent_id' => $parent->parent_id]);
                }
            },
        ];
    }
}
