<?php

namespace Tests;

use App\Services\BackgroundSyncLauncher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /** How many times the (fake) background sync launcher was asked to start a process. */
    public int $backgroundSyncStarts = 0;

    /**
     * Staff other than administrators work for one facility (see AccessScope). Test fixtures that create such
     * users without caring which facility use this one: the facility with the given code, else the one that
     * Facility::firstOrFail() returns (what most fixtures put their patients in).
     */
    protected function defaultFacilityId(?string $code = null): ?string
    {
        if ($code) {
            return \App\Models\Facility::where('code', $code)->value('id');
        }

        if ($facility = \App\Models\Facility::first()) {
            return $facility->id;
        }

        // Tests that seed no locations still need a facility to assign.
        $state = \App\Models\State::create(['name' => 'Test State', 'code' => 'TST']);
        $county = \App\Models\County::create(['name' => 'Test County', 'code' => 'TSC', 'state_id' => $state->id]);

        return \App\Models\Facility::create(['name' => 'Test Facility', 'code' => 'TSF-001', 'county_id' => $county->id])->id;
    }

    /** Gives the app a (fake) Africa's Talking key and a faked gateway that accepts every message. */
    protected function fakeSmsGateway(): void
    {
        config(['services.africastalking.api_key' => 'test-key', 'services.africastalking.sandbox' => true]);
        Http::fake(['*africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => [['status' => 'Success', 'messageId' => 'ATXid_test']]]], 201)]);
    }

    /** Selects Twilio with fake credentials and a faked gateway that accepts every message. */
    protected function fakeTwilioGateway(): void
    {
        config([
            'services.sms.provider' => 'twilio',
            'services.twilio.account_sid' => 'AC'.str_repeat('a', 32),
            'services.twilio.auth_token' => 'twilio-token-secret',
            'services.twilio.sender' => 'ViLoCare',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM'.str_repeat('1', 32), 'status' => 'queued'], 201)]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clinic computers do not send SMS by default (see App\Support\SmsRole). Tests that exercise sending assume a
        // computer that does; MultiComputerSafetyTest turns it off again to test the default.
        config(['sync.sends_sms' => true]);

        // No test may ever spawn a real `php artisan vilocare:sync` process.
        $test = $this;
        $this->app->instance(BackgroundSyncLauncher::class, new class($test) extends BackgroundSyncLauncher
        {
            public function __construct(private TestCase $test) {}

            public function start(): bool
            {
                $this->test->backgroundSyncStarts++;

                return true;
            }
        });
    }
}
