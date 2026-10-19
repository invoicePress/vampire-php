<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Profile;

use EinvoiceValidator\Exception\ImportException;

/**
 * Task 5.1 — reads a profile JSON file and resolves its `extends` chain.
 *
 * ⚠️ A cycle in `extends` is an error, not a silent stop: two profiles extending each other would
 * otherwise produce whichever result the recursion happened to bottom out at.
 */
final class ProfileLoader
{
    public function __construct(private readonly string $directory)
    {
    }

    public function load(string $id): Profile
    {
        return $this->loadWithChain($id, []);
    }

    /**
     * @param list<string> $seen
     */
    private function loadWithChain(string $id, array $seen): Profile
    {
        if (in_array($id, $seen, true)) {
            throw new ImportException('profile "extends" forms a cycle: ' . implode(' -> ', [...$seen, $id]));
        }

        $path = $this->directory . '/' . $id . '.json';

        if (!is_file($path)) {
            throw new ImportException("profile \"$id\" not found in {$this->directory}");
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new ImportException("cannot read profile \"$id\"");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $profile = Profile::fromArray($data);

        $parentId = $data['extends'] ?? null;

        if (!is_string($parentId) || $parentId === '') {
            return $profile;
        }

        return $this->loadWithChain($parentId, [...$seen, $id])->mergedWith($profile);
    }
}
