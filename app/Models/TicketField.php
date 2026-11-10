<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An extra question on the ticket form, for one department or all of them. */
class TicketField extends Model
{
    public const TYPES = ['text' => 'Single line', 'textarea' => 'Multiple lines', 'select' => 'Choice', 'checkbox' => 'Yes / no', 'number' => 'Number', 'date' => 'Date'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'staff_only' => 'boolean', 'is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Laravel validation rules for a submitted value. @return array<int, mixed> */
    public function rules(): array
    {
        $rules = [$this->required && $this->type !== 'checkbox' ? 'required' : 'nullable'];

        return array_merge($rules, match ($this->type) {
            'textarea' => ['string', 'max:5000'],
            'select' => ['string', 'in:'.implode(',', array_map(fn ($o) => str_replace(',', '', $o), $this->options ?? []))],
            'checkbox' => ['boolean'],
            'number' => ['numeric'],
            'date' => ['date'],
            default => ['string', 'max:255'],
        });
    }
}
