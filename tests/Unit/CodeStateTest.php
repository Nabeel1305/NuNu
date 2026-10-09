<?php

namespace Tests\Unit;

use App\Services\Codes\CodeState;
use PHPUnit\Framework\TestCase;

class CodeStateTest extends TestCase
{
    public function test_an_issued_code_can_be_redeemed_cancelled_or_expired(): void
    {
        foreach ([CodeState::Redeemed, CodeState::Cancelled, CodeState::Expired] as $next) {
            $this->assertTrue(CodeState::Issued->canTransitionTo($next));
        }
    }

    public function test_a_redeemed_code_settles_or_fails_and_nothing_else(): void
    {
        $this->assertTrue(CodeState::Redeemed->canTransitionTo(CodeState::Settled));
        $this->assertTrue(CodeState::Redeemed->canTransitionTo(CodeState::Failed));
        $this->assertFalse(CodeState::Redeemed->canTransitionTo(CodeState::Cancelled));
        $this->assertFalse(CodeState::Redeemed->canTransitionTo(CodeState::Issued));
    }

    public function test_final_states_go_nowhere(): void
    {
        foreach ([CodeState::Settled, CodeState::Failed, CodeState::Cancelled, CodeState::Expired] as $state) {
            $this->assertTrue($state->isTerminal());
        }
    }
}
