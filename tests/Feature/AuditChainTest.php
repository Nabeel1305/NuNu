<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuditChainTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function record(int $tenantId, string $what): AuditLog
    {
        return app(AuditLogService::class)->record($tenantId, 'test.event', 'system', null, null, null, $what);
    }

    public function test_a_new_tenant_starts_with_an_empty_chain_head(): void
    {
        [$tenant] = $this->makeTenant();

        $this->assertNull(DB::table('audit_chain_heads')->where('tenant_id', $tenant->id)->value('last_hash'));
    }

    public function test_the_head_always_holds_the_newest_hash_and_entries_link_up(): void
    {
        [$tenant] = $this->makeTenant();

        $first = $this->record($tenant->id, 'one');
        $second = $this->record($tenant->id, 'two');
        $third = $this->record($tenant->id, 'three');

        $this->assertNull($first->prev_hash);
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertSame($second->hash, $third->prev_hash);
        $this->assertSame($third->hash, DB::table('audit_chain_heads')->where('tenant_id', $tenant->id)->value('last_hash'));
        $this->assertSame([], app(AuditLogService::class)->verifyChain($tenant->id));
    }

    public function test_tenants_have_separate_chains(): void
    {
        [$a] = $this->makeTenant();
        [$b] = $this->makeTenant();

        $this->record($a->id, 'a1');
        $firstB = $this->record($b->id, 'b1');

        $this->assertNull($firstB->prev_hash);
    }

    public function test_a_tenant_without_a_head_row_gets_one_and_the_chain_continues(): void
    {
        [$tenant] = $this->makeTenant();
        $first = $this->record($tenant->id, 'before');

        DB::table('audit_chain_heads')->where('tenant_id', $tenant->id)->delete();

        $second = $this->record($tenant->id, 'after');

        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertSame([], app(AuditLogService::class)->verifyChain($tenant->id));
    }
}
