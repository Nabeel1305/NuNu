<?php

namespace Tests\Unit;

use App\Services\Settlement\SettlementStatus;
use PHPUnit\Framework\TestCase;

class SettlementStatusTest extends TestCase
{
    public function test_known_states_are_accepted(): void
    {
        foreach (SettlementStatus::STATES as $state) {
            $this->assertSame($state, (new SettlementStatus($state))->state);
        }
    }

    public function test_an_unknown_state_is_refused_rather_than_guessed_at(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SettlementStatus('maybe');
    }
}
