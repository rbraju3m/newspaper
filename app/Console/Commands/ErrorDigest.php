<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * What broke, grouped, over the last few days.
 *
 * The push alert answers "is something on fire right now" and deliberately
 * says nothing twice. This answers the other question — what has been failing
 * quietly all week, and how often — which the throttle is specifically
 * designed to hide.
 *
 * Reads the JSON lines written by the `errors` log channel rather than a
 * table, so it still works when the database was the thing that broke.
 */
class ErrorDigest extends Command
{
    protected $signature = 'errors:digest
        {--days=1 : How many days back to include}
        {--top=15 : How many distinct errors to list}
        {--email= : Send the digest to this address instead of printing it}';

    protected $description = 'Summarise recent exceptions from the error log';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = Carbon::today()->subDays($days - 1);

        $records = $this->records($since);

        if ($records === []) {
            $this->components->info('No errors logged in the last '.$days.' day(s).');

            return self::SUCCESS;
        }

        $groups = $this->group($records);
        $report = $this->render($groups, count($records), $days);

        if (filled($address = $this->option('email'))) {
            Mail::raw($report, fn ($message) => $message->to($address)->subject(
                '['.config('site.name_en', config('app.name')).'] '.count($records).' error(s) in '.$days.' day(s)'
            ));

            $this->components->info('Digest sent to '.$address.'.');

            return self::SUCCESS;
        }

        $this->line($report);

        return self::SUCCESS;
    }

    /**
     * One decoded record per line. A malformed line is skipped rather than
     * fatal: a log truncated mid-write must not take the digest with it.
     *
     * @return list<array<string, mixed>>
     */
    private function records(Carbon $since): array
    {
        $records = [];

        foreach ($this->logFiles($since) as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = json_decode($line, true);

                if (is_array($record) && isset($record['datetime'])) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    /**
     * The daily channel writes `errors-YYYY-MM-DD.log` next to the configured
     * path, so the window is a filename filter rather than a scan of every
     * line ever logged.
     *
     * The path is read from the channel config rather than hard-coded, so
     * moving the log moves the digest with it.
     *
     * @return list<string>
     */
    private function logFiles(Carbon $since): array
    {
        $configured = (string) config('logging.channels.errors.path', storage_path('logs/errors.log'));
        $directory = dirname($configured);
        $extension = pathinfo($configured, PATHINFO_EXTENSION);
        $stem = pathinfo($configured, PATHINFO_FILENAME);

        $files = [];

        for ($date = $since->copy(); $date->lte(Carbon::today()); $date->addDay()) {
            $path = $directory.'/'.$stem.'-'.$date->format('Y-m-d').($extension ? '.'.$extension : '');

            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function group(array $records): array
    {
        $groups = [];

        foreach ($records as $record) {
            $context = $record['context'] ?? [];
            $key = $context['fingerprint'] ?? sha1((string) ($record['message'] ?? ''));

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'count' => 0,
                    'type' => $context['type'] ?? 'unknown',
                    'message' => (string) ($record['message'] ?? ''),
                    'file' => $context['file'] ?? '',
                    'first' => $record['datetime'],
                    'last' => $record['datetime'],
                    'urls' => [],
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['last'] = max($groups[$key]['last'], $record['datetime']);
            $groups[$key]['first'] = min($groups[$key]['first'], $record['datetime']);

            if (isset($context['url']) && count($groups[$key]['urls']) < 3) {
                $groups[$key]['urls'][$context['url']] = true;
            }
        }

        uasort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $groups;
    }

    /** @param  array<string, array<string, mixed>>  $groups */
    private function render(array $groups, int $total, int $days): string
    {
        $top = max(1, (int) $this->option('top'));
        $lines = [
            $total.' error(s) over '.$days.' day(s), '.count($groups).' distinct.',
            str_repeat('-', 72),
        ];

        foreach (array_slice($groups, 0, $top, true) as $fingerprint => $group) {
            $lines[] = '';
            $lines[] = sprintf('%5d ×  %s  [%s]', $group['count'], class_basename($group['type']), $fingerprint);
            $lines[] = '        '.Str::limit($group['message'], 120);
            $lines[] = '        at '.$group['file'];
            $lines[] = '        first '.$this->stamp($group['first']).'   last '.$this->stamp($group['last']);

            foreach (array_keys($group['urls']) as $url) {
                $lines[] = '        '.Str::limit($url, 100);
            }
        }

        if (count($groups) > $top) {
            $lines[] = '';
            $lines[] = '… and '.(count($groups) - $top).' more distinct error(s).';
        }

        return implode("\n", $lines);
    }

    private function stamp(string $datetime): string
    {
        return Carbon::parse($datetime)->format('D H:i');
    }
}
