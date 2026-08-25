import Alpine from '../bootstrap';

/**
 * State for the admin article editor: tag tokens, lead-image upload, and the
 * conditional fields that depend on status/type.
 */
Alpine.data('articleEditor', (config) => ({
    title: config.title ?? '',
    status: document.getElementById('f-status')?.value ?? 'draft',
    type: document.getElementById('f-type')?.value ?? 'news',
    breaking: false,

    metaTitle: document.getElementById('f-mt')?.value ?? '',
    metaDescription: document.getElementById('f-md')?.value ?? '',

    tags: config.tags ?? [],
    tagDraft: '',

    image: config.image ?? null,
    // The media row behind `image`. Both have to travel together: `image`
    // feeds the plain src and `image_id` feeds the srcset, so posting one
    // without the other leaves the article serving the old picture's
    // derivative ladder while claiming the new one's src — and a browser
    // given both attributes reads srcset and ignores src.
    imageId: config.imageId ?? null,
    // What the upload belongs to, so the server can file it under that story.
    // The saved slug when there is one, otherwise whatever headline is in the
    // box — slugifying is left to PHP, because the Bangla rules (\p{M} for
    // vowel signs and hasant) live in one place and must not be reimplemented.
    articleSlug: config.articleSlug ?? null,
    imageUrl: config.imageUrl ?? null,
    uploading: false,
    uploadError: '',

    init() {
        this.breaking = document.querySelector('[name="is_breaking"]')?.checked ?? false;
    },

    addTag() {
        const value = this.tagDraft.trim().replace(/,+$/, '');
        if (!value) { this.tagDraft = ''; return; }
        if (this.tags.length >= 15) { this.tagDraft = ''; return; }
        if (!this.tags.includes(value)) this.tags.push(value);
        this.tagDraft = '';
    },

    removeTag(i) {
        this.tags.splice(i, 1);
    },

    uploadFolder() {
        return this.articleSlug || this.title || '';
    },

    clearImage() {
        this.image = null;
        this.imageId = null;
        this.imageUrl = null;
    },

    async upload(file) {
        if (!file) return;

        if (file.size > 8 * 1024 * 1024) {
            this.uploadError = 'ফাইলের আকার সর্বোচ্চ ৮ মেগাবাইট হতে পারে।';
            return;
        }

        this.uploading = true;
        this.uploadError = '';

        const body = new FormData();
        body.append('file', file);
        body.append('for', this.uploadFolder());

        try {
            const res = await fetch(config.uploadEndpoint, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    Accept: 'application/json',
                },
                body,
            });

            const data = await res.json();

            if (!res.ok) {
                // Laravel returns 422 with a validation bag.
                this.uploadError = data.message ?? 'আপলোড ব্যর্থ হয়েছে।';
                return;
            }

            // Store the relative path (what the model persists), preview the URL.
            this.image = data.path;
            this.imageId = data.id ?? null;
            this.imageUrl = data.card ?? data.url;
        } catch {
            this.uploadError = 'আপলোড করা যায়নি, আবার চেষ্টা করুন।';
        } finally {
            this.uploading = false;
        }
    },
}));

/**
 * Minimal HTML helper for the body textarea. Deliberately not a WYSIWYG: the
 * body is rendered unescaped, so anything richer needs server-side sanitising
 * before it can be trusted.
 */
Alpine.data('richText', (targetId) => ({
    wrap(command) {
        const el = document.getElementById(targetId);
        if (!el) return;

        const start = el.selectionStart;
        const end = el.selectionEnd;
        const selected = el.value.slice(start, end);

        const templates = {
            h2: (s) => `<h2>${s || 'উপশিরোনাম'}</h2>`,
            h3: (s) => `<h3>${s || 'উপশিরোনাম'}</h3>`,
            b: (s) => `<strong>${s || 'বোল্ড'}</strong>`,
            i: (s) => `<em>${s || 'ইটালিক'}</em>`,
            ul: (s) => `<ul>\n  <li>${s || 'তালিকার আইটেম'}</li>\n</ul>`,
            quote: (s) => `<blockquote><p>${s || 'উদ্ধৃতি'}</p></blockquote>`,
            link: (s) => {
                const url = window.prompt('লিংক ঠিকানা দিন:', 'https://');
                if (!url) return s;
                return `<a href="${url}">${s || url}</a>`;
            },
            img: (s) => {
                const url = window.prompt('ছবির ঠিকানা দিন:', '');
                if (!url) return s;
                const caption = window.prompt('ক্যাপশন (ঐচ্ছিক):', '') || '';
                return `<figure>\n  <img src="${url}" alt="${caption}">\n`
                    + (caption ? `  <figcaption>${caption}</figcaption>\n` : '')
                    + `</figure>`;
            },
        };

        const replacement = (templates[command] ?? ((s) => s))(selected);
        if (replacement === selected) return;

        el.setRangeText(replacement, start, end, 'end');
        el.focus();
        el.dispatchEvent(new Event('input'));
    },
}));
