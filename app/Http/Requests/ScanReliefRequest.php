<?php

namespace App\Http\Requests;

use App\Enums\RepresentativeRelationship;
use App\Models\ReliefEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScanReliefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'representative' => $this->boolean('representative'),
            'representative_verified' => $this->boolean('representative_verified'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $ids = ReliefEvent::query()->visibleTo($this->user())->pluck('id')->all();
        $represented = $this->boolean('representative');

        return [
            'event_id' => ['required', 'integer', Rule::in($ids)],
            'code' => ['required', 'string', 'max:255'],
            'representative' => ['boolean'],
            'representative_name' => [Rule::requiredIf($represented), 'nullable', 'string', 'min:3', 'max:150'],
            'representative_relationship' => [Rule::requiredIf($represented), 'nullable', Rule::enum(RepresentativeRelationship::class)],
            'representative_verified' => ['boolean', Rule::when($represented, ['accepted'])],
        ];
    }

    /**
     * The person collecting for the resident, or null when the resident collects for themselves.
     *
     * @return array{name: string, relationship: string, verified: bool}|null
     */
    public function representative(): ?array
    {
        if (! $this->boolean('representative')) {
            return null;
        }

        return [
            'name' => trim($this->string('representative_name')->toString()),
            'relationship' => $this->string('representative_relationship')->toString(),
            'verified' => true,
        ];
    }
}
