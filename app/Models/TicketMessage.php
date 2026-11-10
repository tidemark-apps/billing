<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketMessage extends Model
{
    protected $guarded = ['id'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function isReply(): bool
    {
        return $this->kind === 'reply';
    }

    public function isNote(): bool
    {
        return $this->kind === 'note';
    }

    public function isEvent(): bool
    {
        return $this->kind === 'event';
    }

    public function fromStaff(): bool
    {
        return $this->author_type === 'staff';
    }
}
