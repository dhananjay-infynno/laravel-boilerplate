<?php

declare(strict_types=1);

namespace App\Http\Requests\Entry;

use App\DataObjects\CreateEntryData;
use App\Enums\EntryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Party;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Clients send UUIDs; internal ids never leave the API.
 *
 * Resolving them HERE, scoped to the authenticated user, is what stops a
 * crafted payload posting into someone else's ledger: an id they do not own
 * resolves to null and then fails the required check. The service never sees a
 * raw client value.
 */
final class StoreEntry extends FormRequest
{
    /** @var array<string, int|null> */
    private array $resolved = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_column(EntryType::cases(), 'value'))],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'entry_time' => ['nullable', 'date_format:H:i:s'],
            'amount' => ['required', 'decimal:0,4', 'gt:0', 'max_digits:18'],
            'from_account_uuid' => ['nullable', 'uuid'],
            'to_account_uuid' => ['nullable', 'uuid'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'category_uuid' => ['nullable', 'uuid'],
            'party_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = EntryType::tryFrom((string) $this->input('type'));

            if (! $type instanceof EntryType) {
                return;
            }

            if ($type === EntryType::ExternalTransfer) {
                $validator->errors()->add('type', (string) __('validation.custom_messages.use_external_transfer_endpoint'));

                return;
            }

            $from = $this->resolveAccount('from_account_uuid');
            $to = $this->resolveAccount('to_account_uuid');

            if ($type->requiresFromAccount() && $from === null) {
                $validator->errors()->add('from_account_uuid', (string) __('validation.required', ['attribute' => 'from account']));
            }

            if ($type->requiresToAccount() && $to === null) {
                $validator->errors()->add('to_account_uuid', (string) __('validation.required', ['attribute' => 'to account']));
            }

            if ($type === EntryType::AccountToAccount && $from !== null && $from === $to) {
                $validator->errors()->add('to_account_uuid', (string) __('validation.different', [
                    'attribute' => 'to account',
                    'other' => 'from account',
                ]));
            }

            $this->resolveCategory($validator);
            $this->resolveParty($validator);
        });
    }

    public function toData(): CreateEntryData
    {
        $v = $this->validated();

        return new CreateEntryData(
            userId: (int) $this->user()->id,
            type: EntryType::from($v['type']),
            entryDate: (string) $v['entry_date'],
            amount: (string) $v['amount'],
            fromAccountId: $this->resolveAccount('from_account_uuid'),
            toAccountId: $this->resolveAccount('to_account_uuid'),
            remarks: $v['remarks'] ?? null,
            referenceNo: $v['reference_no'] ?? null,
            categoryId: $this->resolved['category_uuid'] ?? null,
            partyId: $this->resolved['party_uuid'] ?? null,
            entryTime: $v['entry_time'] ?? null,
            idempotencyKey: $this->header('Idempotency-Key'),
        );
    }

    private function resolveAccount(string $key): ?int
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $uuid = $this->input($key);

        $id = is_string($uuid) && $uuid !== ''
            ? Account::query()->ownedBy((int) $this->user()->id)->where('uuid', $uuid)->value('id')
            : null;

        return $this->resolved[$key] = $id !== null ? (int) $id : null;
    }

    private function resolveCategory(Validator $validator): void
    {
        $uuid = $this->input('category_uuid');

        if (! is_string($uuid) || $uuid === '') {
            $this->resolved['category_uuid'] = null;

            return;
        }

        // System categories (user_id null) are available to everyone.
        $id = Category::query()
            ->availableTo((int) $this->user()->id)
            ->where('uuid', $uuid)
            ->value('id');

        if ($id === null) {
            $validator->errors()->add('category_uuid', (string) __('validation.exists', ['attribute' => 'category']));
        }

        $this->resolved['category_uuid'] = $id !== null ? (int) $id : null;
    }

    private function resolveParty(Validator $validator): void
    {
        $uuid = $this->input('party_uuid');

        if (! is_string($uuid) || $uuid === '') {
            $this->resolved['party_uuid'] = null;

            return;
        }

        $id = Party::query()
            ->ownedBy((int) $this->user()->id)
            ->where('uuid', $uuid)
            ->value('id');

        if ($id === null) {
            $validator->errors()->add('party_uuid', (string) __('validation.exists', ['attribute' => 'party']));
        }

        $this->resolved['party_uuid'] = $id !== null ? (int) $id : null;
    }
}
