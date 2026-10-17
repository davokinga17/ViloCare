<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Patient;
use Carbon\Carbon;
use App\Services\Sms\AfricasTalkingProvider;
use App\Services\Sms\SmsProviderFactory;
use App\Services\Sms\SmsProviderInterface;
use App\Support\PhoneNumber;
use App\Support\SmsSegments;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SmsService
{
    protected SmsProviderInterface $provider;

    /** Kept for callers that predate the provider switch; each provider has its own reason text. */
    public const NOT_CONFIGURED_REASON = AfricasTalkingProvider::NOT_CONFIGURED_REASON;

    // Sensitive clinical words strictly forbidden in patient-facing SMS
    protected const FORBIDDEN_WORDS = [
        'hiv',
        'viral load',
        'viralload',
        'vl',
        'arv',
        'art',
        'antiretroviral',
        'aids',
        'cd4',
        'unsuppressed',
        'suppressed',
        'regimen',
        'retroviral',
        'switch',
        'failure',
        'infection',
    ];

    /**
     * @param  SmsProviderInterface|null  $provider  the gateway to use; by default the one chosen on Settings > Integrations
     */
    public function __construct(?SmsProviderInterface $provider = null)
    {
        $this->provider = $provider ?? SmsProviderFactory::make();
    }

    public function provider(): SmsProviderInterface
    {
        return $this->provider;
    }

    /**
     * Validate message text against privacy rules to protect patient confidentiality.
     */
    public function validateDiscretion(string $message): void
    {
        $lowered = strtolower($message);
        foreach (self::FORBIDDEN_WORDS as $forbidden) {
            if (preg_match('/\b' . preg_quote($forbidden, '/') . '\b/i', $lowered)) {
                throw new InvalidArgumentException("Privacy Violation: SMS content contains protected medical term '{$forbidden}'. ViLoCare enforces discreet, neutral wording only.");
            }
        }
    }

    /**
     * Send a single SMS via the selected provider or queue locally if offline/unavailable.
     * Guaranteed audit logging: creates a failed NotificationLog record on validation error or missing phone.
     */
    public function send(
        string $phoneNumber,
        string $message,
        string $category = 'custom',
        ?Patient $patient = null
    ): NotificationLog {
        $formattedPhone = $this->formatPhoneNumber($phoneNumber);

        // 1. Enforce confidentiality & check for forbidden medical terms
        $discretionError = null;
        try {
            $this->validateDiscretion($message);
        } catch (InvalidArgumentException $e) {
            $discretionError = $e->getMessage();
        }

        if (empty($phoneNumber) || $phoneNumber === 'NO_PHONE' || empty($formattedPhone)) {
            $patientInfo = $patient ? " for patient {$patient->full_name} ({$patient->art_number})" : "";
            $discretionError = $discretionError ?? "Missing or invalid recipient phone number{$patientInfo}.";
        }

        if ($discretionError) {
            Log::warning("ViLoCare SMS Failed: {$discretionError}");
            return NotificationLog::create([
                'patient_id' => $patient?->id,
                'recipient_phone' => $formattedPhone ?: ($phoneNumber ?: 'NO_PHONE'),
                'category' => $category,
                'message_body' => $message,
                'status' => 'failed',
                'error_message' => $discretionError,
                'provider' => $this->provider->name(),
                'sync_status' => 'pending',
                'last_modified_at' => now(),
            ]);
        }

        $notification = NotificationLog::create([
            'patient_id' => $patient?->id,
            'recipient_phone' => $formattedPhone,
            'category' => $category,
            'message_body' => $message,
            'status' => 'queued',
            'provider' => $this->provider->name(),
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        // One segment holds 160 GSM characters (70 with special characters); a provider may refuse longer messages.
        if ($warning = SmsSegments::warning($message, $this->provider->name())) {
            Log::warning("ViLoCare SMS to {$formattedPhone}: {$warning}");
        }

        return $this->dispatchToProvider($notification);
    }

    /**
     * Hands a notification record to the selected SMS provider and records the outcome.
     */
    public function dispatchToProvider(NotificationLog $notification): NotificationLog
    {
        // Guard against sending unvalidated or privacy-violating content
        try {
            $this->validateDiscretion($notification->message_body);
        } catch (InvalidArgumentException $e) {
            $notification->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'sync_status' => 'pending',
                'last_modified_at' => now(),
            ]);

            return $notification;
        }

        if (empty($notification->recipient_phone) || $notification->recipient_phone === 'NO_PHONE') {
            $notification->update([
                'status' => 'failed',
                'error_message' => 'Missing or invalid recipient phone number.',
                'sync_status' => 'pending',
                'last_modified_at' => now(),
            ]);

            return $notification;
        }

        if (! $this->provider->isConfigured()) {
            // Nothing can be delivered without credentials, so never record the message as sent.
            Log::warning("ViLoCare SMS not sent: {$this->provider->label()} is not configured.");
            $notification->update([
                'status' => 'failed',
                'provider' => $this->provider->name(),
                'error_message' => $this->provider->notConfiguredReason(),
                'sync_status' => 'pending',
                'last_modified_at' => now(),
            ]);

            return $notification;
        }

        $result = $this->provider->send($notification->recipient_phone, $notification->message_body);

        // The row records the provider that handled THIS attempt, so delivery can be compared between providers.
        $common = ['provider' => $this->provider->name(), 'sync_status' => 'pending', 'last_modified_at' => now()];

        if ($result->success) {
            $notification->update($common + [
                'status' => 'sent',
                'provider_message_id' => $result->messageId,
                'sent_at' => now(),
                'error_message' => null,
            ]);
        } elseif ($result->deferred) {
            // Unreachable gateway: remain queued for the next sync/queue run.
            $notification->update($common + ['status' => 'queued', 'error_message' => $result->error]);
        } else {
            $notification->update($common + ['status' => 'failed', 'error_message' => $result->error]);
        }

        return $notification;
    }
    /**
     * Sanitize facility name for discreet patient-facing SMS by replacing sensitive clinical terms
     * (e.g. "Kapoeta Civil Hospital ART Clinic" -> "Kapoeta Civil Hospital Health Clinic").
     */
    public function sanitizeFacilityName(?string $facilityName): string
    {
        if (empty($facilityName)) {
            return 'your clinic';
        }

        // Replace sensitive clinical acronyms and words with neutral health terms
        $sanitized = preg_replace('/\b(art|arv|hiv|aids|cd4|vl|viral\s*load)\b/i', 'Health', $facilityName);
        $sanitized = preg_replace('/\s+/', ' ', trim($sanitized));

        return $sanitized ?: 'your clinic';
    }

    /**
     * Standard discrete template: Lab Report Ready notice.
     */
    public function sendResultReadyNotification(Patient $patient): ?NotificationLog
    {
        $facilityName = $this->sanitizeFacilityName($patient->facility?->name);
        $message = "Hello {$patient->first_name}, your routine wellness lab report is ready at {$facilityName}. Please visit during clinic hours to review.";

        return $this->send(
            phoneNumber: $patient->phone ?? 'NO_PHONE',
            message: $message,
            category: 'result_ready',
            patient: $patient
        );
    }

    /**
     * Standard discrete template: Upcoming Due Date Health Check.
     */
    public function sendDueReminderNotification(Patient $patient, Carbon|string $dueDate): ?NotificationLog
    {
        $dueDate = Carbon::parse($dueDate);
        $facilityName = $this->sanitizeFacilityName($patient->facility?->name);
        $formattedDate = $dueDate->format('M j, Y');
        $message = "Hello {$patient->first_name}, a gentle reminder that your scheduled health review at {$facilityName} is due on {$formattedDate}.";

        return $this->send(
            phoneNumber: $patient->phone ?? 'NO_PHONE',
            message: $message,
            category: 'due_date_reminder',
            patient: $patient
        );
    }

    /**
     * Standard discrete template: EAC Session Counseling reminder.
     */
    public function sendEacSessionReminderNotification(Patient $patient, Carbon|string $sessionDate): ?NotificationLog
    {
        $sessionDate = Carbon::parse($sessionDate);
        $facilityName = $this->sanitizeFacilityName($patient->facility?->name);
        $formattedDate = $sessionDate->format('M j, Y');
        $message = "Hello {$patient->first_name}, your follow-up wellness counseling session at {$facilityName} is scheduled for {$formattedDate}.";

        return $this->send(
            phoneNumber: $patient->phone ?? 'NO_PHONE',
            message: $message,
            category: 'eac_session_reminder',
            patient: $patient
        );
    }

    /**
     * Standard discrete template: Appointment Visit Reminder.
     */
    public function sendAppointmentReminder(Appointment $appointment): ?NotificationLog
    {
        $patient = $appointment->patient;
        $facilityName = $this->sanitizeFacilityName($patient?->facility?->name);
        $formattedDate = Carbon::parse($appointment->appointment_date)->format('M j, Y');
        $patientName = $patient?->first_name ?? 'Client';
        $message = "Hello {$patientName}, this is a reminder for your clinic visit at {$facilityName} on {$formattedDate}. Please bring your clinic card.";

        return $this->send(
            phoneNumber: $patient?->phone ?? 'NO_PHONE',
            message: $message,
            category: 'appointment_reminder',
            patient: $patient
        );
    }

    /**
     * Helper to format phone number into E.164 standard format.
     */
    public function formatPhoneNumber(string $phone): string
    {
        return PhoneNumber::normalise($phone);
    }
}
