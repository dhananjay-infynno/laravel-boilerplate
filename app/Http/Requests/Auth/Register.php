<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * `docs/02-API-SPEC.md` §2 specifies a single `name` field, which is what the
 * mobile app sends. The underlying users table is the boilerplate's, with
 * separate first_name / last_name / username columns.
 *
 * Rather than push that split onto every client — nobody signing up for a
 * cashbook wants to invent a username — it is normalised here. Clients that
 * still send the three columns (admin panel, seeders) keep working untouched.
 */
class Register extends FormRequest
{
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
            'name' => ['required_without_all:first_name,last_name', 'nullable', 'string', 'max:120'],
            'first_name' => ['required_without:name', 'nullable', 'max:120'],
            'last_name' => ['nullable', 'max:120'],
            'username' => ['nullable', 'max:120', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'min:8', 'confirmed'],
            'country_code' => ['nullable', 'max:5'],
            'mobile_no' => ['nullable', 'min:8', 'max:15'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'referral_code' => ['nullable', 'string', 'max:12'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        $name = trim((string) $this->input('name', ''));

        if ($name !== '' && $this->input('first_name') === null) {
            /*
             * Split on the LAST space: "Dhananjay Kumar Thakkar" is far more
             * likely to be first "Dhananjay Kumar", last "Thakkar" than the
             * reverse. A single-word name leaves last_name empty rather than
             * duplicating — plenty of people have one name.
             */
            $parts = preg_split('/\s+/', $name) ?: [$name];
            $last = count($parts) > 1 ? (string) array_pop($parts) : '';

            $merge['first_name'] = implode(' ', $parts);
            $merge['last_name'] = $last;
        }

        if ($this->input('username') === null) {
            $merge['username'] = $this->deriveUsername();
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Derived from the email local part, suffixed for uniqueness.
     *
     * Never shown to the user anywhere — it exists only because the column is
     * unique and the boilerplate's admin tooling expects it.
     */
    private function deriveUsername(): string
    {
        $base = Str::slug(Str::before((string) $this->input('email', ''), '@'), '_');
        $base = $base !== '' ? Str::limit($base, 100, '') : 'user';

        return $base.'_'.Str::lower(Str::random(6));
    }
}
