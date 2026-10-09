<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class AuditLogService
{
    public function record(
        int $tenantId,
        string $eventType,
        string $actorType,
        ?int $actorId,
        ?string $subjectType,
        ?int $subjectId,
        string $description,
        array $metadata = [],
        ?string $reference = null,
    ): AuditLog {
        return DB::transaction(function () use (
            $tenantId, $eventType, $actorType, $actorId, $subjectType, $subjectId, $description, $metadata, $reference
        ) {
            // Take turns per tenant on the chain's head row. A locking read always sees
            // the newest committed hash, even inside a transaction whose snapshot is older,
            // so two writers can never extend the chain from the same entry.
            $prev = $this->lockHead($tenantId);

            $fields = [
                'event_type' => $eventType,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'reference' => $reference,
                'description' => $description,
                'metadata' => $metadata,
            ];

            $hash = AuditLog::computeHash($prev, $fields);

            $entry = AuditLog::create($fields + ['tenant_id' => $tenantId, 'prev_hash' => $prev, 'hash' => $hash]);

            DB::table('audit_chain_heads')->where('tenant_id', $tenantId)->update(['last_hash' => $hash]);

            return $entry;
        });
    }

    /** Lock the tenant's head row and return the hash of its newest audit entry. */
    private function lockHead(int $tenantId): ?string
    {
        $head = DB::table('audit_chain_heads')->where('tenant_id', $tenantId)->lockForUpdate()->first();

        if ($head === null) {
            // Normally created with the tenant. Only a tenant that predates the head
            // table, or one inserted by hand, gets here.
            DB::table('audit_chain_heads')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'last_hash' => AuditLog::where('tenant_id', $tenantId)->orderByDesc('id')->value('hash'),
            ]);

            $head = DB::table('audit_chain_heads')->where('tenant_id', $tenantId)->lockForUpdate()->first();
        }

        return $head->last_hash;
    }

    /** Ids of entries whose hash or link does not match; empty means the chain is intact. */
    public function verifyChain(int $tenantId): array
    {
        $broken = [];
        $prev = null;

        AuditLog::where('tenant_id', $tenantId)->orderBy('id')->chunk(500, function ($rows) use (&$broken, &$prev) {
            foreach ($rows as $row) {
                $expected = AuditLog::computeHash($prev, $row->only([
                    'event_type', 'actor_type', 'actor_id', 'subject_type',
                    'subject_id', 'reference', 'description', 'metadata',
                ]));

                if (! hash_equals($expected, $row->hash) || $row->prev_hash !== $prev) {
                    $broken[] = $row->id;
                }

                $prev = $row->hash;
            }
        });

        return $broken;
    }
}
