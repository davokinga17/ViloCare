<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Services\DueReminderService;
use App\Services\SmsService;
use App\Support\SmsSegments;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class NotificationController extends Controller
{
    protected SmsService $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function index(Request $request): View
    {
        $query = NotificationLog::with('patient')->latest();

        if ($status = $request->input('status')) {
            if ($status === 'sent') {
                $query->whereIn('status', ['sent', 'delivered']);
            } elseif ($status === 'queued') {
                $query->where('status', 'queued');
            } elseif ($status === 'gateway_failed') {
                $query->where('status', 'failed')
                      ->where(function ($q) {
                          $q->whereNull('error_message')
                            ->orWhere(function ($sub) {
                                $sub->where('error_message', 'not like', '%Privacy Violation%')
                                    ->where('error_message', 'not like', '%protected medical term%')
                                    ->where('error_message', 'not like', '%phone number%')
                                    ->where('error_message', 'not like', '%invalid recipient%');
                            });
                      });
            } elseif ($status === 'privacy_violation') {
                $query->where('status', 'failed')
                      ->where(function ($q) {
                          $q->where('error_message', 'like', '%Privacy Violation%')
                            ->orWhere('error_message', 'like', '%protected medical term%');
                      });
            } elseif ($status === 'invalid_recipient') {
                $query->where('status', 'failed')
                      ->where(function ($q) {
                          $q->where('error_message', 'like', '%phone number%')
                            ->orWhere('error_message', 'like', '%invalid recipient%');
                      });
            } elseif ($status === 'failed') {
                $query->where('status', 'failed');
            }
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        // Which gateway handled the message: lets delivery be compared between providers.
        if ($provider = $request->input('provider')) {
            $query->where('provider', $provider);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('recipient_phone', 'like', "%{$search}%")
                  ->orWhere('message_body', 'like', "%{$search}%")
                  ->orWhere('error_message', 'like', "%{$search}%")
                  ->orWhereHas('patient', function ($pq) use ($search) {
                      $pq->where('art_number', 'like', "%{$search}%")
                         ->orWhere('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        $notifications = $query->paginate(20)->withQueryString();

        $totalCount = NotificationLog::count();
        $sentCount = NotificationLog::whereIn('status', ['sent', 'delivered'])->count();
        $queuedCount = NotificationLog::where('status', 'queued')->count();

        $privacyViolationCount = NotificationLog::where('status', 'failed')
            ->where(function ($q) {
                $q->where('error_message', 'like', '%Privacy Violation%')
                  ->orWhere('error_message', 'like', '%protected medical term%');
            })->count();

        $invalidRecipientCount = NotificationLog::where('status', 'failed')
            ->where(function ($q) {
                $q->where('error_message', 'like', '%phone number%')
                  ->orWhere('error_message', 'like', '%invalid recipient%');
            })->count();

        $gatewayFailedCount = NotificationLog::where('status', 'failed')
            ->where(function ($q) {
                $q->whereNull('error_message')
                  ->orWhere(function ($sub) {
                      $sub->where('error_message', 'not like', '%Privacy Violation%')
                          ->where('error_message', 'not like', '%protected medical term%')
                          ->where('error_message', 'not like', '%phone number%')
                          ->where('error_message', 'not like', '%invalid recipient%');
                  });
            })->count();

        $stats = [
            'total' => $totalCount,
            'sent' => $sentCount,
            'queued' => $queuedCount,
            'gateway_failed' => $gatewayFailedCount,
            'privacy_violations' => $privacyViolationCount,
            'invalid_recipients' => $invalidRecipientCount,
            'failed' => $gatewayFailedCount + $privacyViolationCount + $invalidRecipientCount,
            'delivery_rate' => $totalCount > 0 ? round(($sentCount / $totalCount) * 100, 1) : 100,
        ];

        return view('notifications.index', [
            'notifications' => $notifications,
            'stats' => $stats,
        ]);
    }

    public function create(Request $request): View
    {
        $preselectedPatient = null;
        if ($patientId = $request->input('patient_id')) {
            $preselectedPatient = Patient::find($patientId);
        }

        $patientsWithPhones = Patient::where('status', 'active')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('first_name')
            ->get();

        return view('notifications.create', [
            'patients' => $patientsWithPhones,
            'preselectedPatient' => $preselectedPatient,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'uuid', 'exists:patients,id'],
            'recipient_phone' => ['required', 'string', 'max:25'],
            'message_body' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'in:result_ready,due_date_reminder,eac_session_reminder,appointment_reminder,custom'],
        ]);

        $patient = !empty($validated['patient_id']) ? Patient::find($validated['patient_id']) : null;

        try {
            $notification = $this->smsService->send(
                phoneNumber: $validated['recipient_phone'],
                message: $validated['message_body'],
                category: $validated['category'],
                patient: $patient
            );

            AuditLog::log(
                action: 'dispatched_sms_notification',
                tableName: 'notification_logs',
                recordId: (string) $notification->id,
                oldValues: null,
                newValues: [
                    'recipient' => $notification->recipient_phone,
                    'category' => $notification->category,
                    'status' => $notification->status,
                ],
                userId: auth()->id()
            );

            $message = "SMS successfully dispatched (Status: {$notification->status}).";
            if ($warning = SmsSegments::warning($validated['message_body'], $notification->provider)) {
                $message .= ' '.$warning;
            }

            return redirect()->route('notifications.index')->with('success', $message);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['message_body' => $e->getMessage()]);
        } catch (Exception $e) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['message_body' => 'SMS Gateway Error: ' . $e->getMessage()]);
        }
    }

    public function retry(NotificationLog $notification): RedirectResponse
    {
        if ($notification->isPrivacyViolation()) {
            return redirect()->back()
                ->with('error', "Cannot retry message: It violates ViLoCare's Anti-Stigma Policy due to sensitive clinical terms. Please compose a new discreet message.");
        }

        if (empty($notification->recipient_phone) || $notification->recipient_phone === 'NO_PHONE') {
            return redirect()->back()
                ->with('error', "Cannot retry message: Recipient phone number is missing or invalid.");
        }

        $this->smsService->dispatchToProvider($notification);

        AuditLog::log(
            action: 'retried_sms_notification',
            tableName: 'notification_logs',
            recordId: (string) $notification->id,
            oldValues: null,
            newValues: ['new_status' => $notification->status],
            userId: auth()->id()
        );

        $statusMsg = $notification->isSent()
            ? "SMS retry successful. Sent to recipient."
            : "SMS retry processed. Current status: {$notification->statusDisplayLabel()}.";

        return redirect()->back()
            ->with('success', $statusMsg);
    }

    public function bulkDueReminders(Request $request, DueReminderService $reminders): RedirectResponse
    {
        $result = $reminders->dispatchAll((int) $request->input('days', 7));

        $message = "Batch reminder dispatched: {$result['dispatched']} discrete reminders sent/queued for upcoming tests.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped (already reminded or no phone number).";
        }

        return redirect()->route('notifications.index')->with('success', $message);
    }
}
