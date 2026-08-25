<?php

namespace App\Http\Requests\Admin;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ArticleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:255'],
            'kicker' => ['nullable', 'string', 'max:200'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'body' => ['nullable', 'string'],

            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],

            'type' => ['required', Rule::enum(ArticleType::class)],
            'status' => ['required', Rule::enum(ArticleStatus::class)],

            'image' => ['nullable', 'string', 'max:2048'],
            'image_id' => ['nullable', 'integer', 'exists:media,id'],
            'image_caption' => ['nullable', 'string', 'max:500'],
            'image_credit' => ['nullable', 'string', 'max:255'],

            'video_url' => ['nullable', 'url', 'max:2048'],
            'video_duration' => ['nullable', 'integer', 'min:0', 'max:65535'],

            'is_lead' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_breaking' => ['nullable', 'boolean'],
            'is_premium' => ['nullable', 'boolean'],
            'is_pinned' => ['nullable', 'boolean'],
            'allow_comments' => ['nullable', 'boolean'],

            'breaking_until' => ['nullable', 'date', 'after:now'],
            'published_at' => ['nullable', 'date'],

            'dateline' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:120'],
            'locale' => ['required', 'in:bn,en'],

            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],

            'tags' => ['nullable', 'array', 'max:15'],
            'tags.*' => ['string', 'max:80'],
            'topics' => ['nullable', 'array', 'max:5'],
            'topics.*' => ['integer', 'exists:topics,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'শিরোনাম লিখুন।',
            'category_id.required' => 'একটি বিভাগ বেছে নিন।',
            'breaking_until.after' => 'ব্রেকিং শেষ হওয়ার সময় ভবিষ্যতে হতে হবে।',
            'video_url.url' => 'সঠিক ভিডিও লিংক দিন।',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $status = $this->input('status');
                $type = $this->input('type');

                // A published story with no body is almost always a mistake.
                if ($status === ArticleStatus::Published->value
                    && $type !== ArticleType::Video->value
                    && blank($this->input('body'))) {
                    $validator->errors()->add('body', 'প্রকাশ করার আগে খবরের বিবরণ লিখুন।');
                }

                if ($type === ArticleType::Video->value && blank($this->input('video_url'))) {
                    $validator->errors()->add('video_url', 'ভিডিও ধরনের খবরে ভিডিও লিংক দিতে হবে।');
                }

                // Scheduling into the past just means "publish now" — say so
                // rather than silently doing something the editor did not ask for.
                if ($status === ArticleStatus::Scheduled->value) {
                    if (blank($this->input('published_at'))) {
                        $validator->errors()->add('published_at', 'নির্ধারিত প্রকাশের সময় দিন।');
                    } elseif (strtotime((string) $this->input('published_at')) <= time()) {
                        $validator->errors()->add('published_at', 'নির্ধারিত সময় ভবিষ্যতে হতে হবে।');
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        // Tags come from a token input as a comma-separated string.
        if (is_string($this->input('tags'))) {
            $this->merge([
                'tags' => array_values(array_filter(array_map('trim', explode(',', $this->input('tags'))))),
            ]);
        }

        // An empty slug means "regenerate from the title".
        if (blank($this->input('slug'))) {
            $this->merge(['slug' => null]);
        }
    }
}
