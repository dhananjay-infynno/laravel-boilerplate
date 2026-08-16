<?php

declare(strict_types=1);

namespace App\Http\Resources\Entry;

use App\Models\Entry;
use App\Traits\ResourceFilterable;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Entry $resource
 */
#[SchemaName('Entry')]
final class Resource extends JsonResource
{
    use ResourceFilterable;

    protected $model = Entry::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->fields();

        // Every internal id and the idempotency key are stripped. The key
        // identifies a replayable request and has no business in a response.
        unset(
            $data['id'],
            $data['user_id'],
            $data['from_account_id'],
            $data['to_account_id'],
            $data['category_id'],
            $data['party_id'],
            $data['counterparty_user_id'],
            $data['counterparty_account_id'],
            $data['linked_entry_id'],
            $data['parent_entry_id'],
            $data['idempotency_key'],
        );

        // whenLoaded, never a query — this runs once per row in a list.
        $data['from_account'] = $this->whenLoaded('fromAccount', fn (): array => [
            'uuid' => (string) $this->resource->fromAccount->uuid,
            'name' => (string) $this->resource->fromAccount->name,
            'account_number' => (string) $this->resource->fromAccount->account_number,
        ]);

        $data['to_account'] = $this->whenLoaded('toAccount', fn (): array => [
            'uuid' => (string) $this->resource->toAccount->uuid,
            'name' => (string) $this->resource->toAccount->name,
            'account_number' => (string) $this->resource->toAccount->account_number,
        ]);

        $data['category'] = $this->whenLoaded('category', fn (): array => [
            'uuid' => (string) $this->resource->category->uuid,
            'name' => (string) $this->resource->category->name,
        ]);

        return $data;
    }
}
