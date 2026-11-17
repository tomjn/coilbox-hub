<?php

namespace App\Domain\Mirrors;

/** Writes the catalogue JSON that the hub signs. The signed bytes are the bytes served. */
final class Catalogue
{
    /**
     * @param  array{seq: int, mirrors: list<array<string, mixed>>, archives: list<array<string, mixed>>, removed: list<array<string, mixed>>}  $source  The result of `catalogue_source`.
     * @param  array{public_key: string, sig: string}|null  $nextKey
     */
    public static function encode(array $source, int $generatedAt, ?array $nextKey): string
    {
        $catalogue = [
            'v' => 1,
            'seq' => (int) $source['seq'],
            'generated_at' => $generatedAt,
            'mirrors' => array_values(array_map(fn (array $m) => [
                'id' => $m['id'],
                'base_url' => $m['base_url'],
                'public_key' => $m['public_key'],
            ], $source['mirrors'])),
            'archives' => array_values(array_map(fn (array $a) => [
                'filename' => $a['filename'],
                'kind' => $a['kind'],
                'sha256' => $a['sha256'],
                'size' => (int) $a['size'],
                'internal_name' => $a['internal_name'],
                'version' => $a['version'],
                'shortname' => $a['shortname'],
                'author' => $a['author'],
                'description' => $a['description'],
            ], $source['archives'])),
            'removed' => array_values(array_map(fn (array $r) => [
                'sha256' => $r['sha256'],
                'filename' => $r['filename'],
                'reason' => $r['reason'],
                'removed_at' => (int) $r['removed_at'],
            ], $source['removed'])),
        ];

        if ($nextKey !== null) {
            $catalogue['next_key'] = ['public_key' => $nextKey['public_key'], 'sig' => $nextKey['sig']];
        }

        return json_encode($catalogue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
