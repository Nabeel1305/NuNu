<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tenant isolation rests on every tenant-owned model using BelongsToTenant. This
 * fails the build when someone adds a table with a tenant_id and forgets that.
 */
class TenantScopeCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Tables with a tenant_id that are deliberately not auto-scoped, and why. */
    private const EXEMPT = [
        'api_keys' => 'looked up by key before any tenant is known',
        'voice_numbers' => 'resolved from the dialled number before any tenant is known',
        'audit_logs' => 'written only by AuditLogService, which is always given the tenant id',
        'audit_chain_heads' => 'lock row addressed by tenant id inside AuditLogService; has no model',
    ];

    public function test_every_table_with_a_tenant_id_is_scoped_or_explicitly_exempt(): void
    {
        $models = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');
            if (is_subclass_of($class, Model::class)) {
                $models[(new $class)->getTable()] = $class;
            }
        }

        $unscoped = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (! Schema::hasColumn($name, 'tenant_id') || array_key_exists($name, self::EXEMPT)) {
                continue;
            }

            $class = $models[$name] ?? null;

            if ($class === null || ! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $unscoped[] = $name;
            }
        }

        $this->assertSame([], $unscoped, 'Tables with a tenant_id but no tenant-scoped model: ' . implode(', ', $unscoped));
    }

    public function test_the_exemptions_still_exist(): void
    {
        foreach (array_keys(self::EXEMPT) as $table) {
            $this->assertTrue(Schema::hasTable($table), "Exempt table {$table} no longer exists; remove it from the list.");
        }
    }
}
