<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A support department: a queue of tickets, optionally fed by one email address. */
class Department extends Model
{
    protected $table = 'ticket_departments';

    protected $guarded = ['id'];

    protected $hidden = ['imap_password'];

    protected $attributes = ['is_public' => true, 'is_active' => true, 'auto_reply' => true, 'imap_enabled' => false, 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_validate_cert' => true, 'imap_folder' => 'INBOX', 'imap_after' => 'seen', 'sort' => 0];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'auto_reply' => 'boolean',
            'imap_enabled' => 'boolean',
            'imap_validate_cert' => 'boolean',
            'imap_port' => 'integer',
            'imap_password' => 'encrypted',
            'imap_checked_at' => 'datetime',
        ];
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user', 'department_id', 'user_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(TicketField::class)->orderBy('sort')->orderBy('id');
    }

    /** Active departments customers pick from on the new-ticket form. */
    public function scopeListed($query)
    {
        return $query->where('is_active', true)->where('is_public', true)->orderBy('sort')->orderBy('name');
    }

    /** Active departments customers can open tickets in (listed ones, plus unlisted ones by direct link). */
    public function scopeOpenable($query)
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('name');
    }

    /** Custom fields that apply here: the department's own and the global ones. */
    public function applicableFields(bool $forCustomer = false)
    {
        return TicketField::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $this->id))
            ->when($forCustomer, fn ($q) => $q->where('staff_only', false))
            ->orderBy('sort')->orderBy('id')->get();
    }

    public function hasMailbox(): bool
    {
        return $this->imap_enabled && $this->imap_host && $this->imap_username;
    }
}
