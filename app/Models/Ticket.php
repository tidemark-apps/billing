<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'is_locked' => 'boolean',
            'custom_fields' => 'array',
            'last_reply_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /** What a customer may see: replies only, never internal notes or system lines. */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('kind', 'reply');
    }

    public function isClosed(): bool
    {
        return $this->status->isClosed();
    }

    /** Staff see tickets in their departments and any assigned to them; admins see everything. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn('department_id', DepartmentAccess::idsFor($user))
            ->orWhere('assigned_to', $user->id));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', TicketStatus::Closed->value);
    }

    public function canBeSeenBy(User $user): bool
    {
        return $user->isAdmin()
            || $this->assigned_to === $user->id
            || ($this->department_id !== null && in_array($this->department_id, DepartmentAccess::idsFor($user), true));
    }
}
