<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\TestingLaboratory;
use App\Models\User;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The ONE access rule: which patients/samples a logged-in user may see and act on.
 *
 *   ALL       administrators; lab technicians at a laboratory that has no facility (NPHL)
 *   COUNTY    lab technicians at a facility that hosts a testing laboratory (every facility in that county)
 *   FACILITY  everyone else (their own facility)
 *   NONE      a non-administrator with no assignment: sees nothing
 *
 * It only restricts a logged-in WEB user. With nobody logged in (console, scheduler, queue) or while
 * suspended (sync API and the sync centre) the scope is ALL, because every clinic computer holds all data.
 */
final class AccessScope
{
    public const ALL = 'all';

    public const COUNTY = 'county';

    public const FACILITY = 'facility';

    public const NONE = 'none';

    /** An id that matches no row: used to force an empty result through the location filters. */
    public const NO_MATCH = '00000000-0000-0000-0000-000000000000';

    private static int $suspended = 0;

    /** @var array<string, self> */
    private static array $cache = [];

    private function __construct(public readonly string $type, public readonly ?string $id = null) {}

    public static function all(): self
    {
        return new self(self::ALL);
    }

    public static function forUser(?User $user): self
    {
        if (! $user) {
            return self::all();
        }

        $role = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;
        $key = implode('|', [$user->getKey(), $role, $user->facility_id, $user->testing_laboratory_id]);

        return self::$cache[$key] ??= self::resolve($user);
    }

    /** The scope of the logged-in web user; ALL when nobody is logged in or scoping is suspended. */
    public static function current(): self
    {
        if (self::$suspended > 0) {
            return self::all();
        }

        return self::forUser(Auth::user());
    }

    /** Runs $callback with no restriction (sync engine, system work started from a web request). */
    public static function unscoped(callable $callback): mixed
    {
        self::$suspended++;
        try {
            return $callback();
        } finally {
            self::$suspended--;
        }
    }

    public static function suspend(): void
    {
        self::$suspended++;
    }

    public static function resume(): void
    {
        self::$suspended = max(0, self::$suspended - 1);
    }

    /** Forget cached scopes (after a laboratory or facility link changes). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function resolve(User $user): self
    {
        if ($user->hasRole(UserRole::ADMINISTRATOR)) {
            return self::all();
        }

        if ($user->hasRole(UserRole::LAB_TECHNICIAN)) {
            if ($user->testing_laboratory_id) {
                $lab = TestingLaboratory::find($user->testing_laboratory_id);
                if (! $lab) {
                    return new self(self::NONE);
                }
                if (! $lab->facility_id) {
                    return self::all(); // National Public Health Laboratory: stands alone, sees everything
                }
                $countyId = Facility::whereKey($lab->facility_id)->value('county_id');

                return $countyId ? new self(self::COUNTY, (string) $countyId) : new self(self::NONE);
            }

            if ($user->facility_id) {
                $facility = Facility::find($user->facility_id);
                if (! $facility) {
                    return new self(self::NONE);
                }
                $hostsLab = TestingLaboratory::where('facility_id', $facility->id)->exists();

                return $hostsLab && $facility->county_id
                    ? new self(self::COUNTY, (string) $facility->county_id)
                    : new self(self::FACILITY, (string) $facility->id);
            }

            return new self(self::NONE);
        }

        return $user->facility_id ? new self(self::FACILITY, (string) $user->facility_id) : new self(self::NONE);
    }

    public function isAll(): bool
    {
        return $this->type === self::ALL;
    }

    public function isNone(): bool
    {
        return $this->type === self::NONE;
    }

    /** Restricts an Eloquent/base query on the patients table (or any table with county_id/facility_id). */
    public function constrainPatients($query, string $prefix = ''): void
    {
        match ($this->type) {
            self::ALL => null,
            self::COUNTY => $query->where($prefix.'county_id', $this->id),
            self::FACILITY => $query->where($prefix.'facility_id', $this->id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** `select id from patients where <in scope>` (plain query builder, so no global scope recursion). */
    public function patientIds(): BaseBuilder
    {
        $query = DB::table('patients')->select('id');
        $this->constrainPatients($query);

        return $query;
    }

    /** `select id from facilities where <in scope>`. */
    public function facilityIds(): BaseBuilder
    {
        $query = DB::table('facilities')->select('id');
        match ($this->type) {
            self::ALL => null,
            self::COUNTY => $query->where('county_id', $this->id),
            self::FACILITY => $query->where('id', $this->id),
            default => $query->whereRaw('1 = 0'),
        };

        return $query;
    }

    /** `select county_id from facilities where <in scope>`. */
    public function countyIds(): BaseBuilder
    {
        return DB::table('facilities')->select('county_id')->whereIn('id', $this->facilityIds());
    }

    /** `select state_id from counties` that contain an in-scope facility. */
    public function stateIds(): BaseBuilder
    {
        return DB::table('counties')->select('state_id')->whereIn('id', $this->countyIds());
    }

    /** `select id from sample_collections where <in scope>`. */
    public function sampleIds(): BaseBuilder
    {
        $query = DB::table('sample_collections')->select('id');
        $this->constrainSamples($query);

        return $query;
    }

    /** A sample is in scope when its facility is, or when its patient is. */
    public function constrainSamples($query, string $prefix = ''): void
    {
        if ($this->isAll()) {
            return;
        }
        if ($this->isNone()) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function ($q) use ($prefix) {
            $q->whereIn($prefix.'facility_id', $this->facilityIds())
                ->orWhereIn($prefix.'patient_id', $this->patientIds());
        });
    }

    public function allowsFacility(?string $facilityId): bool
    {
        if ($this->isAll()) {
            return true;
        }
        if (! $facilityId || $this->isNone()) {
            return false;
        }

        return $this->facilityIds()->where('id', $facilityId)->exists();
    }

    /** Facility ids the user may choose or filter by; null means all. */
    public function allowedFacilityIds(): ?array
    {
        return $this->isAll() ? null : $this->facilityIds()->pluck('id')->all();
    }

    /** Restricts a state/county/facility location filter so it can only narrow, never widen, the scope. */
    public function narrowLocation(array $location): array
    {
        if ($this->isAll()) {
            return $location;
        }
        if ($this->isNone()) {
            return ['facility_id' => self::NO_MATCH];
        }

        $facilityId = $location['facility_id'] ?? null;
        if ($facilityId && $facilityId !== 'all') {
            return ['facility_id' => $this->allowsFacility((string) $facilityId) ? (string) $facilityId : self::NO_MATCH];
        }

        return $this->type === self::FACILITY
            ? ['facility_id' => $this->id]
            : ['county_id' => $this->id];
    }
}
