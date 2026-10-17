<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory, HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'patient_id',
        'recipient_phone',
        'category',
        'message_body',
        'status',
        'provider',
        'provider_message_id',
        'sent_at',
        'error_message',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'last_modified_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function isSent(): bool
    {
        return in_array($this->status, ['sent', 'delivered']);
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function isPrivacyViolation(): bool
    {
        if ($this->status !== 'failed') {
            return false;
        }

        $error = strtolower($this->error_message ?? '');
        return str_contains($error, 'privacy violation') || str_contains($error, 'protected medical term');
    }

    public function isInvalidRecipient(): bool
    {
        if ($this->status !== 'failed') {
            return false;
        }

        $error = strtolower($this->error_message ?? '');
        return str_contains($error, 'phone number') || str_contains($error, 'invalid recipient');
    }

    public function isGatewayFailure(): bool
    {
        return $this->status === 'failed' && !$this->isPrivacyViolation() && !$this->isInvalidRecipient();
    }

    public function failureType(): ?string
    {
        if ($this->status !== 'failed') {
            return null;
        }

        if ($this->isPrivacyViolation()) {
            return 'privacy_violation';
        }

        if ($this->isInvalidRecipient()) {
            return 'invalid_recipient';
        }

        return 'gateway_failure';
    }

    public function failureTypeLabel(): ?string
    {
        return match ($this->failureType()) {
            'privacy_violation' => 'Privacy / Anti-Stigma Policy Violation',
            'invalid_recipient' => 'Invalid / Missing Phone Number',
            'gateway_failure' => 'SMS Gateway Provider Failure',
            default => null,
        };
    }

    public function isRetryable(): bool
    {
        return $this->status === 'queued' || $this->isGatewayFailure();
    }

    public function statusBadgeClass(): string
    {
        if ($this->status === 'sent' || $this->status === 'delivered') {
            return 'badge-success';
        }

        if ($this->status === 'queued') {
            return 'badge-info';
        }

        if ($this->status === 'failed') {
            if ($this->isPrivacyViolation()) {
                return 'badge-warning';
            }
            if ($this->isInvalidRecipient()) {
                return 'badge-secondary';
            }
            return 'badge-danger';
        }

        return 'badge-secondary';
    }

    public function statusDisplayLabel(): string
    {
        if ($this->status === 'sent' || $this->status === 'delivered') {
            return 'Delivered / Sent';
        }

        if ($this->status === 'queued') {
            return 'Queued Offline';
        }

        if ($this->status === 'failed') {
            if ($this->isPrivacyViolation()) {
                return 'Privacy Blocked';
            }
            if ($this->isInvalidRecipient()) {
                return 'Invalid Phone';
            }
            return 'Gateway Failed';
        }

        return ucfirst($this->status);
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            'result_ready' => 'Lab Report Ready',
            'due_date_reminder' => 'Health Check Due',
            'eac_session_reminder' => 'EAC Counselling Session',
            'appointment_reminder' => 'Clinic Visit Reminder',
            'custom' => 'Custom Notice',
            default => ucfirst(str_replace('_', ' ', $this->category)),
        };
    }
}
