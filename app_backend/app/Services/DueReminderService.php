<?php

namespace App\Services;

use App\Models\NotificationLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides who gets a "health review is due" SMS and sends it, one reminder per patient.
 *
 * The audience comes from VlDueService (one row per patient, latest result only), so a patient with
 * several old result rows is never messaged more than once. Overdue patients are not re-messaged
 * (only due dates from today up to the look-ahead window), as before.
 */
class DueReminderService
{
    /** Minimum gap between two due reminders to the same patient, whatever the due date. */
    public const REMINDER_WINDOW_DAYS = 3;

    public function __construct(
        private VlDueService $vlDue,
        private SmsService $sms,
    ) {}

    /**
     * @return Collection<int, VlDueItem> due-today-or-later patients in the window, one per patient
     */
    public function candidates(int $daysAhead): Collection
    {
        return $this->vlDue->due([], $daysAhead)
            ->filter(fn (VlDueItem $item) => ! $item->isOverdue())
            ->values();
    }

    /**
     * Why this item must not be messaged now, or null when it is eligible.
     */
    public function skipReason(VlDueItem $item): ?string
    {
        if (empty($item->patient->phone)) {
            return 'no phone number';
        }

        if ($this->hasRecentReminder($item)) {
            return 'already reminded';
        }

        return null;
    }

    /**
     * True when a reminder for this due date is already sent/queued, or any due reminder
     * was logged inside the window. Failed attempts do not count, so they can be retried.
     */
    public function hasRecentReminder(VlDueItem $item): bool
    {
        return NotificationLog::query()
            ->where('patient_id', $item->patient->id)
            ->where('category', 'due_date_reminder')
            ->whereIn('status', ['queued', 'sent', 'delivered'])
            ->where(function ($q) use ($item) {
                $q->where('message_body', 'like', '%due on '.$this->dateText($item->next_due_date).'.')
                    ->orWhere('created_at', '>=', now()->subDays(self::REMINDER_WINDOW_DAYS));
            })
            ->exists();
    }

    /** Sends (or queues when offline) the reminder. Caller is responsible for calling skipReason() first. */
    public function send(VlDueItem $item): ?NotificationLog
    {
        return $this->sms->sendDueReminderNotification($item->patient, $item->next_due_date);
    }

    /**
     * Sends reminders to every eligible patient.
     *
     * @return array{dispatched: int, skipped: int}
     */
    public function dispatchAll(int $daysAhead): array
    {
        $dispatched = 0;
        $skipped = 0;

        foreach ($this->candidates($daysAhead) as $item) {
            if ($this->skipReason($item)) {
                $skipped++;

                continue;
            }

            $this->send($item);
            $dispatched++;
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped];
    }

    /** Must match the date format used by SmsService::sendDueReminderNotification(). */
    private function dateText(Carbon $date): string
    {
        return $date->format('M j, Y');
    }
}
