<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\PageSourceManifest;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\PayloadReader;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

final class AnalysisRepresentation
{
    public function text(AnalysisRunSnapshot $run, ?ExecutionContext $context = null): string
    {
        $r = LedgerRow::one("SELECT disk,storage_path,encode(content_hash,'hex') AS hash,size_bytes FROM prc_document_representations WHERE id=?", [$run->representationId])
            ?? throw new OperationDenied('representation_missing');
        if ($r->int('size_bytes') > 8_000_000 || $r->string('hash') !== $run->representationHash->hex) {
            throw new OperationDenied('representation_bounds');
        }
        try {
            $stream = Storage::disk($r->string('disk'))->readStream($r->string('storage_path'));
            if (! is_resource($stream)) {
                throw new DependencyUnavailable('storage');
            }
            try {
                $text = '';
                while (! feof($stream)) {
                    $context?->tick();
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                        throw new DependencyUnavailable('storage');
                    }
                    $text .= $chunk;
                    if (strlen($text) > 8_000_000) {
                        throw new OperationDenied('representation_bounds');
                    }
                }
            } finally {
                fclose($stream);
            }
        } catch (FilesystemException $failure) {
            throw new DependencyUnavailable('storage', previous: $failure);
        }
        if (strlen($text) > 8_000_000 || hash('sha256', $text) !== $run->representationHash->hex) {
            throw new OperationDenied('representation_hash_drift');
        }

        return $text;
    }

    /** @return list<string> */
    public function pages(string $text): array
    {
        if (strlen($text) > 8_000_000 || ! mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('Invalid bounded prepared text.');
        }
        preg_match_all('/^\[page:([0-9]+)\]\r?$/m', $text, $matches, PREG_OFFSET_CAPTURE);
        if ($matches[0] === [] || count($matches[0]) > 4096 || trim(substr($text, 0, $matches[0][0][1])) !== '') {
            throw new \InvalidArgumentException('Independent page anchors required.');
        }
        $pages = [];
        foreach ($matches[0] as $i => [$marker, $offset]) {
            if ((int) $matches[1][$i][0] !== $i + 1) {
                throw new \InvalidArgumentException('Page anchors are ambiguous, missing or reordered.');
            }
            $start = $offset + strlen($marker);
            $end = $matches[0][$i + 1][1] ?? strlen($text);
            $pages[] = trim(substr($text, $start, $end - $start), "\r\n");
        }

        return $pages;
    }

    public function source(string $text, ContentHash $hash, string $coverage): PageSourceManifest
    {
        if (ContentHash::of($text) != $hash) {
            throw new \InvalidArgumentException('Prepared source hash drift.');
        }
        $r = PayloadReader::json($coverage);
        $pages = $this->pages($text);
        if ($r->string('unit') !== 'page' || $r->integer('source_units') !== count($pages) || $r->list('missing') !== []) {
            throw new \InvalidArgumentException('Complete independent preparation coverage required.');
        }
        $cursor = 0;
        foreach ($r->list('covered') as $range) {
            if (! is_array($range) || count($range) !== 2 || $range[0] !== $cursor || ! is_int($range[1]) || $range[1] <= $cursor) {
                throw new \InvalidArgumentException('Original page coverage has a gap.');
            }
            $cursor = $range[1];
        }
        if ($cursor !== count($pages)) {
            throw new \InvalidArgumentException('Preparation omitted original pages.');
        }
        $proof = [];
        foreach ($r->list('pages') as $entry) {
            if (! $entry instanceof \stdClass || ! is_int($entry->page ?? null) || ! is_string($entry->status ?? null)
                || isset($proof[$entry->page - 1])) {
                throw new \InvalidArgumentException('Original page proof required.');
            }
            $proof[$entry->page - 1] = $entry->status;
        }
        if (count($proof) !== count($pages)) {
            throw new \InvalidArgumentException('Missing original page proof.');
        }
        $anchors = $blank = [];
        foreach ($pages as $page => $content) {
            if (! in_array($proof[$page] ?? null, ['native_text', 'ocr_text', 'proved_blank'], true)) {
                throw new \InvalidArgumentException('Original page is not independently complete.');
            }
            $anchors['page:'.$page] = $page;
            if (trim($content) === '') {
                if ($proof[$page] !== 'proved_blank') {
                    throw new \InvalidArgumentException('Empty prepared page has no independent blank proof.');
                }
                $blank[] = $page;
            }
            $lines = explode("\n", $content);
            if (count($lines) >= 100000) {
                throw new \InvalidArgumentException('Source page exceeds anchor bound.');
            }
            $pageAnchors = 1;
            foreach ($lines as $line => $value) {
                if (trim($value) !== '') {
                    $anchors['page:'.$page.':line:'.$line] = $page;
                    $pageAnchors++;
                }
            }
            if ($pageAnchors > 4096) {
                throw new \InvalidArgumentException('An indivisible source page exceeds the anchor bound.');
            }
        }

        return new PageSourceManifest($hash, count($pages), $anchors, $blank);
    }

    public function anchored(string $text, ?PageRange $range = null): string
    {
        $result = '';
        foreach ($this->pages($text) as $page => $content) {
            if ($range !== null && ($page < $range->start || $page >= $range->end)) {
                continue;
            }
            $result .= '[page:'.$page."]\n";
            foreach (explode("\n", $content) as $line => $value) {
                $result .= '[page:'.$page.':line:'.$line.';row_index:'.($page * 100000 + $line).'] '.$value."\n";
            }
        }

        return $result;
    }
}
