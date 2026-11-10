@props(['invoice'])
{{-- One status per invoice: an unpaid invoice past its due date reads "Overdue" instead of "Open". --}}
@if ($invoice->isOverdue())<span class="pill bad">Overdue</span>
@elseif ($invoice->status === \App\Enums\InvoiceStatus::Open)<span class="pill brand">Unpaid</span>
@else<x-status :status="$invoice->status" />@endif
