<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use PHPUnit\Framework\TestCase;

class AuditHashTest extends TestCase
{
    private function fields(string $description = 'Code issued'): array
    {
        return [
            'event_type' => 'code.issued', 'actor_type' => 'tenant', 'actor_id' => null,
            'subject_type' => 'PaymentCode', 'subject_id' => 7, 'reference' => 'abc',
            'description' => $description, 'metadata' => ['k' => 'v'],
        ];
    }

    public function test_the_same_fields_and_link_give_the_same_hash(): void
    {
        $this->assertSame(AuditLog::computeHash('p', $this->fields()), AuditLog::computeHash('p', $this->fields()));
    }

    public function test_changing_any_field_or_the_previous_hash_changes_the_hash(): void
    {
        $base = AuditLog::computeHash('p', $this->fields());

        $this->assertNotSame($base, AuditLog::computeHash('p', $this->fields('Code cancelled')));
        $this->assertNotSame($base, AuditLog::computeHash('q', $this->fields()));
        $this->assertNotSame($base, AuditLog::computeHash(null, $this->fields()));
    }
}
