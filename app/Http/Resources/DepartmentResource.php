<?php

namespace App\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Department
 * Customers get name, description and the fields they can fill in. Staff also see mail settings; administrators additionally
 * the IMAP connection (host, user) and staff ids. The IMAP password is write-only: only `has_imap_password` is returned.
 */
class DepartmentResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request?->user();
        $staff = (bool) $user?->isStaff();
        $admin = (bool) $user?->isAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'sort' => $this->sort,
            'is_public' => $this->when($staff, $this->is_public),
            'is_active' => $this->when($staff, $this->is_active),
            'email' => $this->when($staff, $this->email),
            'auto_reply' => $this->when($staff, $this->auto_reply),
            'imap_enabled' => $this->when($admin, $this->imap_enabled),
            'imap_host' => $this->when($admin, $this->imap_host),
            'imap_port' => $this->when($admin, $this->imap_port),
            'imap_encryption' => $this->when($admin, $this->imap_encryption),
            'imap_validate_cert' => $this->when($admin, $this->imap_validate_cert),
            'imap_username' => $this->when($admin, $this->imap_username),
            'has_imap_password' => $this->when($admin, fn () => (string) $this->getRawOriginal('imap_password') !== ''),
            'imap_folder' => $this->when($admin, $this->imap_folder),
            'imap_after' => $this->when($admin, $this->imap_after),
            'imap_checked_at' => $this->when($admin, $this->imap_checked_at?->toIso8601String()),
            'imap_error' => $this->when($admin, $this->imap_error),
            'staff_ids' => $this->when($admin, fn () => $this->staff()->pluck('users.id')->map(fn ($i) => (int) $i)->all()),
            'fields' => $this->when(! $staff, fn () => TicketFieldResource::collection($this->applicableFields(forCustomer: true))),
            'created_at' => $this->when($staff, $this->created_at?->toIso8601String()),
        ];
    }
}
