<?php

namespace App\Data;

readonly class WorktreeInfo
{
    public function __construct(
        public string $worktreePath,
        public string $parentPath,
        public string $branch,
        public string $slug,
    ) {}
}
