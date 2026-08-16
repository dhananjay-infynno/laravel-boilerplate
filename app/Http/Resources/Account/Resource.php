<?php

declare(strict_types=1);

namespace App\Http\Resources\Account;

use App\Models\Account;
use App\Traits\ResourceFilterable;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Account $resource
 */
#[SchemaName('Account')]
final class Resource extends JsonResource
{
    use ResourceFilterable;

    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->fields();

        // Internal ids never leave the API — uuid is the only public
        // identifier. `main_flag` is a generated column and an implementation
        // detail of the one-main-per-user constraint.
        unset($data['id'], $data['user_id'], $data['main_flag'], $data['is_recalculating']);

        // whenCounted, never a query: a count inside toArray() runs once per
        // row and turns a 25-item list into 26 queries.
        $data['entry_count'] = $this->whenCounted('entriesFrom');

        // Surfaced so the client can show "recalculating..." on the statement
        // view rather than silently displaying a stale chain.
        if ($this->resource->is_recalculating) {
            $data['is_recalculating'] = true;
        }

        return $data;
    }
}
